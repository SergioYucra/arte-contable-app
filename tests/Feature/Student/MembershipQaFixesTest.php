<?php

namespace Tests\Feature\Student;

use App\Core\Storage\PrivateVault;
use App\Models\AccessCode;
use App\Models\PaymentRequest;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\CoreSecuritySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Fallos encontrados en el QA manual de la página de membresía del estudiante.
 */
class MembershipQaFixesTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private string $tempStorageBase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreSecuritySeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-11-15 16:17:00', 'UTC'));

        $this->tempStorageBase = sys_get_temp_dir().'/arte-contable-qa-'.Str::uuid();
        File::ensureDirectoryExists($this->tempStorageBase.'/vault');
        config()->set('filesystems.disks.'.PrivateVault::DISK.'.root', $this->tempStorageBase.'/vault');
        Storage::forgetDisk(PrivateVault::DISK);

        $this->student = User::factory()->create();
        $this->student->assignRole('User');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        if (isset($this->tempStorageBase) && File::isDirectory($this->tempStorageBase)) {
            File::deleteDirectory($this->tempStorageBase);
        }

        parent::tearDown();
    }

    private function membership(): array
    {
        return $this->actingAs($this->student)->get(route('membership.index'))->viewData('initialMembership');
    }

    public function test_progress_bar_uses_the_real_length_of_the_access(): void
    {
        Subscription::factory()->create([
            'user_id' => $this->student->id,
            'starts_at' => now()->subDays(20),
            'expires_at' => now()->addDays(160),
        ]);

        $membership = $this->membership();

        $this->assertSame(180, $membership['total_days']);
        $this->assertSame(160, $membership['days_remaining']);
    }

    public function test_expired_and_revoked_members_are_told_what_happened(): void
    {
        Subscription::factory()->create([
            'user_id' => $this->student->id,
            'starts_at' => now()->subDays(200),
            'expires_at' => Carbon::parse('2026-10-26 12:00:00', 'UTC'),
        ]);

        $this->assertSame(['state' => 'expired', 'until' => '26 de octubre de 2026'], $this->membership()['previous']);

        Subscription::factory()->revoked()->create(['user_id' => $this->student->id]);
        $this->assertSame('revoked', $this->membership()['previous']['state']);
    }

    public function test_free_member_without_history_has_no_previous_notice(): void
    {
        $this->assertNull($this->membership()['previous']);
    }

    public function test_dates_are_shown_in_bolivia_time(): void
    {
        PaymentRequest::factory()->create(['user_id' => $this->student->id]);

        $pending = $this->actingAs($this->student)->get(route('membership.index'))->viewData('initialPendingRequest');

        // 16:17 UTC = 12:17 en Bolivia.
        $this->assertSame('15 nov. 2026, 12:17', $pending['created_at']);
    }

    public function test_rejection_reason_reaches_the_student(): void
    {
        PaymentRequest::factory()->rejected()->create(['user_id' => $this->student->id, 'admin_notes' => 'La imagen no se ve bien']);

        $recent = $this->actingAs($this->student)->get(route('membership.index'))->viewData('initialRecentRequests');

        $this->assertSame('rejected', $recent[0]['status']);
        $this->assertSame('La imagen no se ve bien', $recent[0]['admin_notes']);
    }

    public function test_upload_rejected_by_server_limit_gets_a_clear_message(): void
    {
        $plan = SubscriptionPlan::factory()->create();

        // Archivo que PHP marcó como fallido (como cuando supera upload_max_filesize).
        $failed = new \Illuminate\Http\UploadedFile(__FILE__, 'foto.png', 'image/png', UPLOAD_ERR_INI_SIZE, true);

        $this->actingAs($this->student)
            ->post(route('membership.payment.store'), ['subscription_plan_id' => $plan->id, 'proof_file' => $failed], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.proof_file.0', 'No se pudo subir el archivo, probablemente por su tamaño. Envía una captura de pantalla del comprobante en lugar de una foto, o un archivo más liviano.');
    }

    public function test_redeem_returns_the_real_code_for_the_history(): void
    {
        $admin = User::factory()->create();
        $code = AccessCode::factory()->create(['created_by' => $admin->id, 'duration_days' => 30]);

        $this->actingAs($this->student)
            ->postJson(route('membership.redeem'), ['code' => strtolower($code->code)])
            ->assertOk()
            ->assertJsonPath('redeemed_code.code', $code->code)
            ->assertJsonPath('redeemed_code.duration_days', 30)
            ->assertJsonPath('membership.total_days', 30);
    }

    public function test_voided_code_message_uses_plain_words(): void
    {
        $admin = User::factory()->create();
        $code = AccessCode::factory()->create(['created_by' => $admin->id, 'revoked_at' => now()]);

        $this->actingAs($this->student)
            ->postJson(route('membership.redeem'), ['code' => $code->code])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Este código fue anulado y ya no sirve. Pide uno nuevo a quien te lo dio.');
    }
}
