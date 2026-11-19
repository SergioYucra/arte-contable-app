<?php

namespace Tests\Feature\Student;

use App\Core\Storage\PrivateVault;
use App\Models\AccessCode;
use App\Models\PaymentRequest;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Database\Seeders\CoreSecuritySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class MembershipControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private string $tempStorageBase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreSecuritySeeder::class);

        $this->tempStorageBase = sys_get_temp_dir().'/arte-contable-membership-test-'.Str::uuid();
        File::ensureDirectoryExists($this->tempStorageBase.'/public');
        File::ensureDirectoryExists($this->tempStorageBase.'/vault');

        config()->set('filesystems.disks.public.root', $this->tempStorageBase.'/public');
        config()->set('filesystems.disks.'.PrivateVault::DISK.'.root', $this->tempStorageBase.'/vault');

        Storage::forgetDisk('public');
        Storage::forgetDisk(PrivateVault::DISK);

        $this->student = User::factory()->create([
            'email_verified_at' => now(),
        ]);
        $this->student->assignRole('User');
    }

    protected function tearDown(): void
    {
        if (isset($this->tempStorageBase) && File::isDirectory($this->tempStorageBase)) {
            File::deleteDirectory($this->tempStorageBase);
        }

        parent::tearDown();
    }

    private function makeValidWebpUpload(string $filename): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $filename,
            base64_decode('UklGRhoAAABXRUJQVlA4TA0AAAAvAAAAEAcQERGIiP4HAA==', true) ?: ''
        );
    }

    public function test_guest_is_redirected_to_login_when_accessing_membership(): void
    {
        $response = $this->get(route('membership.index'));
        $response->assertRedirect(route('login'));
    }

    public function test_student_can_view_membership_page_with_active_plans(): void
    {
        SubscriptionPlan::create([
            'name' => 'Plan Semestral',
            'price' => 120.00,
            'duration_days' => 180,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->student)->get(route('membership.index'));

        $response->assertOk();
        $response->assertViewIs('membership.index');
        $response->assertSee('data-vue-island="StudentMembershipManager"', false);
        $response->assertSee('Plan Semestral');
    }

    public function test_student_with_active_subscription_sees_active_membership_header(): void
    {
        Subscription::create([
            'user_id' => $this->student->id,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now(),
            'expires_at' => now()->addDays(180),
        ]);

        $response = $this->actingAs($this->student)->get(route('membership.index'));

        $response->assertOk();
        $response->assertSee('Mi membresía');

        // La cabecera Blade con "Membresía activa" se quitó junto con los accesos rápidos:
        // ahora el estado lo dibuja el componente a partir de estos datos.
        $membership = $response->viewData('initialMembership');
        $this->assertTrue($membership['is_active']);
        $this->assertSame('Membresía activa', $membership['status_label']);
        $this->assertSame(180, $membership['days_remaining']);
    }

    public function test_student_can_submit_payment_proof(): void
    {
        $plan = SubscriptionPlan::create([
            'name' => 'Plan Semestral',
            'price' => 120.00,
            'duration_days' => 180,
            'is_active' => true,
        ]);

        $proofFile = $this->makeValidWebpUpload('comprobante_bcp.webp');

        $response = $this->actingAs($this->student)->post(route('membership.payment.store'), [
            'subscription_plan_id' => $plan->id,
            'proof_file' => $proofFile,
            'student_notes' => 'Transferencia desde BCP app, ref 987654',
        ], ['Accept' => 'application/json']);

        $response->assertCreated();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('payment_request.plan_name', 'Plan Semestral');

        $this->assertDatabaseHas('payment_requests', [
            'user_id' => $this->student->id,
            'subscription_plan_id' => $plan->id,
            'amount' => 120.00,
            'status' => PaymentRequest::STATUS_PENDING,
            'reference_text' => 'Transferencia desde BCP app, ref 987654',
        ]);

        $paymentRequest = PaymentRequest::where('user_id', $this->student->id)->first();
        $this->assertNotNull($paymentRequest->proof_vault_path);
        Storage::disk(PrivateVault::DISK)->assertExists($paymentRequest->proof_vault_path);
    }

    public function test_student_cannot_submit_duplicate_payment_request_while_one_is_pending(): void
    {
        $plan = SubscriptionPlan::create([
            'name' => 'Plan Anual',
            'price' => 200.00,
            'duration_days' => 365,
            'is_active' => true,
        ]);

        PaymentRequest::create([
            'user_id' => $this->student->id,
            'subscription_plan_id' => $plan->id,
            'amount' => $plan->price,
            'reference_text' => 'Ref BCP 12345',
            'proof_vault_path' => 'receipts/test.webp',
            'proof_original_name' => 'test.webp',
            'proof_mime_type' => 'image/webp',
            'proof_size_bytes' => 1024,
            'status' => PaymentRequest::STATUS_PENDING,
        ]);

        $newProof = $this->makeValidWebpUpload('segundo_comprobante.webp');

        $response = $this->actingAs($this->student)->postJson(route('membership.payment.store'), [
            'subscription_plan_id' => $plan->id,
            'proof_file' => $newProof,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
    }

    public function test_student_can_redeem_valid_access_code_and_activates_subscription(): void
    {
        $plan = SubscriptionPlan::create([
            'name' => 'Plan Semestral',
            'price' => 120.00,
            'duration_days' => 180,
            'is_active' => true,
        ]);

        $code = AccessCode::create([
            'code' => 'AC-9J4K-X8PQ',
            'subscription_plan_id' => $plan->id,
            'duration_days' => 180,
            'created_by' => $this->student->id,
        ]);

        $response = $this->actingAs($this->student)->postJson(route('membership.redeem'), [
            'code' => 'ac-9j4k-x8pq', // minúsculas para probar normalización
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('membership.is_active', true);
        $response->assertJsonPath('membership.days_remaining', 180);

        $this->assertNotNull($code->fresh()->redeemed_at);
        $this->assertEquals($this->student->id, $code->fresh()->redeemed_by);

        $this->assertDatabaseHas('subscriptions', [
            'user_id' => $this->student->id,
            'access_code_id' => $code->id,
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $this->assertTrue($this->student->fresh()->hasActiveSubscription());
    }

    public function test_redeeming_code_extends_existing_active_subscription(): void
    {
        $now = Carbon::now();
        Carbon::setTestNow($now);

        Subscription::create([
            'user_id' => $this->student->id,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => $now->copy()->subDays(10),
            'expires_at' => $now->copy()->addDays(20), // Le quedan 20 días
        ]);

        $code = AccessCode::create([
            'code' => 'AC-TEST-EXTEND',
            'subscription_plan_id' => null,
            'duration_days' => 30, // Suma 30 días adicionales
            'created_by' => $this->student->id,
        ]);

        $response = $this->actingAs($this->student)->postJson(route('membership.redeem'), [
            'code' => 'AC-TEST-EXTEND',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        // Debe tener 20 días originales + 30 días nuevos = 50 días restantes
        $response->assertJsonPath('membership.days_remaining', 50);

        Carbon::setTestNow();
    }

    public function test_cannot_redeem_revoked_or_already_redeemed_code(): void
    {
        $revokedCode = AccessCode::create([
            'code' => 'AC-REVOKED-1234',
            'duration_days' => 30,
            'revoked_at' => now(),
            'created_by' => $this->student->id,
        ]);

        $response = $this->actingAs($this->student)->postJson(route('membership.redeem'), [
            'code' => 'AC-REVOKED-1234',
        ]);

        $response->assertStatus(422);

        $usedCode = AccessCode::create([
            'code' => 'AC-ALREADY-USED',
            'duration_days' => 30,
            'redeemed_at' => now(),
            'redeemed_by' => $this->student->id,
            'created_by' => $this->student->id,
        ]);

        $response2 = $this->actingAs($this->student)->postJson(route('membership.redeem'), [
            'code' => 'AC-ALREADY-USED',
        ]);

        $response2->assertStatus(422);
    }

    public function test_student_can_view_own_proof_but_not_others(): void
    {
        $vaultPath = 'receipts/student_receipt.webp';
        Storage::disk(PrivateVault::DISK)->put($vaultPath, 'fake-content');

        $req1 = PaymentRequest::create([
            'user_id' => $this->student->id,
            'subscription_plan_id' => null,
            'amount' => 120.00,
            'reference_text' => 'Comprobante #999',
            'proof_vault_path' => $vaultPath,
            'proof_original_name' => 'receipt.webp',
            'proof_mime_type' => 'image/webp',
            'proof_size_bytes' => 12,
            'status' => PaymentRequest::STATUS_PENDING,
        ]);

        // Student can view own proof
        $res1 = $this->actingAs($this->student)->get(route('membership.proof', $req1->id));
        $res1->assertOk();

        // Otra persona no puede ver el comprobante, y se le responde igual que si no existiera (404).
        $otherStudent = User::factory()->create();
        $res2 = $this->actingAs($otherStudent)->get(route('membership.proof', $req1->id));
        $res2->assertNotFound();
    }
}
