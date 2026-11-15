<?php

namespace Tests\Feature\Admin;

use App\Core\Storage\PrivateVault;
use App\Models\PaymentRequest;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\CoreSecuritySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Precio especial por tiempo limitado: configuración en el panel, lo que ve el estudiante y el monto del pago.
 */
class SpecialPriceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $tempStorageBase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreSecuritySeeder::class);
        // 12:00 UTC = 08:00 en Bolivia.
        Carbon::setTestNow(Carbon::parse('2026-11-15 12:00:00', 'UTC'));

        $this->tempStorageBase = sys_get_temp_dir().'/arte-contable-special-'.Str::uuid();
        File::ensureDirectoryExists($this->tempStorageBase.'/public');
        File::ensureDirectoryExists($this->tempStorageBase.'/vault');
        config()->set('filesystems.disks.public.root', $this->tempStorageBase.'/public');
        config()->set('filesystems.disks.'.PrivateVault::DISK.'.root', $this->tempStorageBase.'/vault');
        Storage::forgetDisk('public');
        Storage::forgetDisk(PrivateVault::DISK);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        if (isset($this->tempStorageBase) && File::isDirectory($this->tempStorageBase)) {
            File::deleteDirectory($this->tempStorageBase);
        }

        parent::tearDown();
    }

    private function webp(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode('UklGRhoAAABXRUJQVlA4TA0AAAAvAAAAEAcQERGIiP4HAA==', true) ?: '');
    }

    private function planPayload(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Plan Anual',
            'price' => 200,
            'duration_days' => 365,
            'is_active' => '1',
            'special_enabled' => '1',
            'special_price' => 150,
            'special_label' => 'Black Friday',
            'special_ends_at' => '2026-11-30T23:59',
            'special_qr_image' => $this->webp('qr-promo.webp'),
        ];
    }

    public function test_admin_sets_special_price_with_its_own_qr_and_dates_in_bolivia_time(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.subscriptions.plans.store'), $this->planPayload(), ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('plan.special.status', 'active')
            ->assertJsonPath('plan.special.label', 'Black Friday')
            ->assertJsonPath('plan.special.ends_at_input', '2026-11-30T23:59');

        $plan = SubscriptionPlan::sole();
        // 23:59 en Bolivia (UTC-4) = 03:59 del día siguiente en UTC.
        $this->assertSame('2026-12-01 03:59:00', $plan->special_ends_at->utc()->format('Y-m-d H:i:s'));
        $this->assertNotNull($plan->special_qr_image_path);
        Storage::disk('public')->assertExists($plan->special_qr_image_path);
        $this->assertSame(150.0, $plan->currentPrice());
    }

    public function test_special_price_is_validated(): void
    {
        $post = fn (array $data) => $this->actingAs($this->admin)
            ->post(route('admin.subscriptions.plans.store'), $this->planPayload($data), ['Accept' => 'application/json']);

        $post(['special_price' => 200])->assertUnprocessable()->assertJsonValidationErrors('special_price');
        $post(['special_price' => 250])->assertUnprocessable()->assertJsonValidationErrors('special_price');
        $post(['special_ends_at' => ''])->assertUnprocessable()->assertJsonValidationErrors('special_ends_at');
        $post(['special_ends_at' => '2026-11-15T07:00'])->assertUnprocessable()->assertJsonValidationErrors('special_ends_at');
        $post(['special_starts_at' => '2026-12-05T00:00'])->assertUnprocessable()->assertJsonValidationErrors('special_starts_at');

        $this->assertSame(0, SubscriptionPlan::count());
    }

    public function test_student_sees_and_pays_the_special_price_only_while_it_lasts(): void
    {
        $plan = SubscriptionPlan::factory()->create([
            'price' => 200,
            'qr_image_path' => 'plans-qr/normal.webp',
            'special_price' => 150,
            'special_label' => 'Black Friday',
            'special_ends_at' => Carbon::parse('2026-11-20 04:00:00', 'UTC'),
            'special_qr_image_path' => 'plans-qr/promo.webp',
        ]);

        $student = User::factory()->create();
        $student->assignRole('User');

        $plans = $this->actingAs($student)->get(route('membership.index'))->viewData('initialPlans');
        $this->assertSame(150.0, $plans[0]['price']);
        $this->assertSame('Black Friday', $plans[0]['special']['label']);
        $this->assertSame('200.00 BOB', $plans[0]['special']['regular_price_formatted']);
        $this->assertStringContainsString('promo.webp', $plans[0]['qr_image_url']);

        $this->actingAs($student)->post(route('membership.payment.store'), [
            'subscription_plan_id' => $plan->id,
            'proof_file' => UploadedFile::fake()->createWithContent(
                'comprobante.png',
                base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true) ?: ''
            ),
        ], ['Accept' => 'application/json'])->assertCreated();

        $payment = PaymentRequest::sole();
        $this->assertSame('150.00', $payment->amount);
        $this->assertSame('Black Friday', $payment->special_price_label);

        // Terminado el precio especial: vuelve el precio y el QR normales.
        Carbon::setTestNow(Carbon::parse('2026-11-21 12:00:00', 'UTC'));
        $plans = $this->actingAs($student)->get(route('membership.index'))->viewData('initialPlans');
        $this->assertSame(200.0, $plans[0]['price']);
        $this->assertNull($plans[0]['special']);
        $this->assertStringContainsString('normal.webp', $plans[0]['qr_image_url']);
    }

    public function test_scheduled_special_price_does_not_apply_yet_and_missing_qr_hides_the_normal_one(): void
    {
        $scheduled = SubscriptionPlan::factory()->create([
            'price' => 200,
            'special_price' => 150,
            'special_starts_at' => Carbon::parse('2026-11-25 04:00:00', 'UTC'),
            'special_ends_at' => Carbon::parse('2026-11-30 04:00:00', 'UTC'),
        ]);
        $this->assertSame(SubscriptionPlan::SPECIAL_SCHEDULED, $scheduled->specialPriceStatus());
        $this->assertSame(200.0, $scheduled->currentPrice());

        // Vigente pero sin QR propio: no se muestra el QR normal, que lleva otro monto.
        $noQr = SubscriptionPlan::factory()->create([
            'price' => 200,
            'qr_image_path' => 'plans-qr/normal.webp',
            'special_price' => 150,
            'special_ends_at' => Carbon::parse('2026-11-30 04:00:00', 'UTC'),
        ]);
        $this->assertNull($noQr->currentQrImageUrl());
    }

    public function test_turning_off_the_special_price_clears_it_and_deletes_its_qr(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.subscriptions.plans.store'), $this->planPayload(), ['Accept' => 'application/json'])
            ->assertCreated();
        $plan = SubscriptionPlan::sole();
        $qrPath = $plan->special_qr_image_path;

        $this->actingAs($this->admin)->post(route('admin.subscriptions.plans.update', $plan->id), [
            'name' => 'Plan Anual',
            'price' => 200,
            'duration_days' => 365,
            'is_active' => '1',
            'special_enabled' => '0',
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('plan.special', null);

        $plan->refresh();
        $this->assertNull($plan->special_price);
        $this->assertNull($plan->special_qr_image_path);
        Storage::disk('public')->assertMissing($qrPath);
    }
}
