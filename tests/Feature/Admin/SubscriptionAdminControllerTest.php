<?php

namespace Tests\Feature\Admin;

use App\Core\Storage\PrivateVault;
use App\Models\AccessCode;
use App\Models\PaymentRequest;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Database\Seeders\CoreSecuritySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class SubscriptionAdminControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $tempStorageBase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreSecuritySeeder::class);

        $this->tempStorageBase = sys_get_temp_dir().'/arte-contable-subscriptions-test-'.Str::uuid();
        File::ensureDirectoryExists($this->tempStorageBase.'/public');
        File::ensureDirectoryExists($this->tempStorageBase.'/vault');

        config()->set('filesystems.disks.public.root', $this->tempStorageBase.'/public');
        config()->set('filesystems.disks.'.PrivateVault::DISK.'.root', $this->tempStorageBase.'/vault');

        Storage::forgetDisk('public');
        Storage::forgetDisk(PrivateVault::DISK);

        $this->admin = User::where('email', 'admin@artecontable.test')->first()
            ?? User::factory()->create();

        if (! $this->admin->hasRole('Admin')) {
            $this->admin->assignRole('Admin');
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->tempStorageBase) && File::isDirectory($this->tempStorageBase)) {
            File::deleteDirectory($this->tempStorageBase);
        }

        parent::tearDown();
    }

    public function test_admin_can_view_subscriptions_dashboard(): void
    {
        SubscriptionPlan::create([
            'name' => 'Plan Anual',
            'price' => 200.00,
            'duration_days' => 365,
        ]);

        $response = $this->actingAs($this->admin)->get(route('admin.subscriptions.index'));

        $response->assertOk();
        $response->assertViewIs('admin.subscriptions.index');
        $response->assertSee('data-vue-island="SubscriptionManager"', false);
    }

    private function makeValidWebpUpload(string $filename): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $filename,
            base64_decode('UklGRhoAAABXRUJQVlA4TA0AAAAvAAAAEAcQERGIiP4HAA==', true) ?: ''
        );
    }

    public function test_admin_can_create_subscription_plan_with_qr_image(): void
    {
        $qrFile = $this->makeValidWebpUpload('banco-qr.webp');

        $response = $this->actingAs($this->admin)->postJson(route('admin.subscriptions.plans.store'), [
            'name' => 'Plan Semestre 2026',
            'price' => 120.00,
            'duration_days' => 180,
            'description' => 'Acceso semestral con QR bancario',
            'is_active' => true,
            'sort_order' => 1,
            'qr_image' => $qrFile,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('plan.name', 'Plan Semestre 2026');

        $this->assertDatabaseHas('subscription_plans', [
            'name' => 'Plan Semestre 2026',
            'price' => 120.00,
            'duration_days' => 180,
        ]);

        $plan = SubscriptionPlan::where('name', 'Plan Semestre 2026')->first();
        $this->assertNotNull($plan->qr_image_path);
        Storage::disk('public')->assertExists($plan->qr_image_path);
    }

    public function test_admin_can_approve_payment_request_and_activates_subscription(): void
    {
        $student = User::factory()->create();
        $plan = SubscriptionPlan::create([
            'name' => 'Plan Anual',
            'price' => 200.00,
            'duration_days' => 365,
        ]);

        $paymentRequest = PaymentRequest::create([
            'user_id' => $student->id,
            'subscription_plan_id' => $plan->id,
            'amount' => 200.00,
            'reference_text' => 'BNB-TRF-987654',
            'proof_vault_path' => 'receipts/fake-proof.jpg',
            'status' => PaymentRequest::STATUS_PENDING,
        ]);

        $response = $this->actingAs($this->admin)->postJson(route('admin.subscriptions.requests.approve', $paymentRequest->id));

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $this->assertDatabaseHas('payment_requests', [
            'id' => $paymentRequest->id,
            'status' => PaymentRequest::STATUS_APPROVED,
            'reviewed_by' => $this->admin->id,
        ]);

        $this->assertDatabaseHas('subscriptions', [
            'user_id' => $student->id,
            'subscription_plan_id' => $plan->id,
            'payment_request_id' => $paymentRequest->id,
            'status' => Subscription::STATUS_ACTIVE,
        ]);
    }

    public function test_admin_can_reject_payment_request_with_notes(): void
    {
        $student = User::factory()->create();
        $paymentRequest = PaymentRequest::create([
            'user_id' => $student->id,
            'amount' => 120.00,
            'reference_text' => 'TRF-1111',
            'proof_vault_path' => 'receipts/test.png',
            'status' => PaymentRequest::STATUS_PENDING,
        ]);

        $response = $this->actingAs($this->admin)->postJson(route('admin.subscriptions.requests.reject', $paymentRequest->id), [
            'admin_notes' => 'El comprobante está borroso y no se distingue el número de cuenta.',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('payment_requests', [
            'id' => $paymentRequest->id,
            'status' => PaymentRequest::STATUS_REJECTED,
            'admin_notes' => 'El comprobante está borroso y no se distingue el número de cuenta.',
        ]);
    }

    public function test_admin_can_generate_batch_of_access_codes_and_revoke_one(): void
    {
        $plan = SubscriptionPlan::create([
            'name' => 'Plan Semestral',
            'price' => 120.00,
            'duration_days' => 180,
        ]);

        $response = $this->actingAs($this->admin)->postJson(route('admin.subscriptions.codes.store'), [
            'subscription_plan_id' => $plan->id,
            'quantity' => 5,
            'reference' => 'Lote Conferencia UMSA',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('quantity', 5);
        $response->assertJsonCount(5, 'access_codes');

        $this->assertDatabaseCount('access_codes', 5);
        $this->assertDatabaseHas('access_codes', [
            'subscription_plan_id' => $plan->id,
            'duration_days' => 180,
            'reference' => 'Lote Conferencia UMSA',
        ]);

        $firstCode = AccessCode::where('subscription_plan_id', $plan->id)->first();
        $this->assertMatchesRegularExpression('/^AC-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $firstCode->code);

        $revokeResponse = $this->actingAs($this->admin)->postJson(route('admin.subscriptions.codes.revoke', $firstCode->id));
        $revokeResponse->assertOk();

        $this->assertNotNull($firstCode->fresh()->revoked_at);
    }

    public function test_admin_can_generate_custom_duration_codes_without_plan(): void
    {
        $response = $this->actingAs($this->admin)->postJson(route('admin.subscriptions.codes.store'), [
            'subscription_plan_id' => null,
            'duration_days' => 45,
            'quantity' => 2,
            'reference' => 'Beca especial auditoría',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('quantity', 2);
        $response->assertJsonCount(2, 'access_codes');

        $this->assertDatabaseHas('access_codes', [
            'subscription_plan_id' => null,
            'duration_days' => 45,
            'reference' => 'Beca especial auditoría',
        ]);
    }
}
