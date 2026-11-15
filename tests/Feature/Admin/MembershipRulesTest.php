<?php

namespace Tests\Feature\Admin;

use App\Models\AccessCode;
use App\Models\PaymentRequest;
use App\Models\Subscription;
use App\Models\SubscriptionGrant;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\CoreSecuritySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reglas del panel de membresías: estados de un pago, planes archivados, historial y bandeja.
 */
class MembershipRulesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreSecuritySeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-11-15 12:00:00'));

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_approved_payment_cannot_be_rejected_and_days_stay(): void
    {
        $payment = PaymentRequest::factory()->create();
        $this->actingAs($this->admin)->postJson(route('admin.subscriptions.requests.approve', $payment))->assertOk();
        $expiresBefore = Subscription::where('user_id', $payment->user_id)->value('expires_at');

        $this->actingAs($this->admin)
            ->postJson(route('admin.subscriptions.requests.reject', $payment), ['admin_notes' => 'Me equivoqué'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_request');

        $this->assertSame(PaymentRequest::STATUS_APPROVED, $payment->fresh()->status);
        $this->assertEquals($expiresBefore, Subscription::where('user_id', $payment->user_id)->value('expires_at'));
    }

    public function test_payment_cannot_be_approved_twice(): void
    {
        $payment = PaymentRequest::factory()->create();

        $this->actingAs($this->admin)->postJson(route('admin.subscriptions.requests.approve', $payment))->assertOk();
        $this->actingAs($this->admin)->postJson(route('admin.subscriptions.requests.approve', $payment))->assertUnprocessable();

        $this->assertSame(1, SubscriptionGrant::where('payment_request_id', $payment->id)->count());
        $this->assertTrue(
            Subscription::where('user_id', $payment->user_id)->first()->expires_at->equalTo(Carbon::now()->addDays(365))
        );
    }

    public function test_rejected_payment_cannot_be_approved(): void
    {
        $payment = PaymentRequest::factory()->rejected()->create();

        $this->actingAs($this->admin)->postJson(route('admin.subscriptions.requests.approve', $payment))->assertUnprocessable();

        $this->assertSame(0, Subscription::where('user_id', $payment->user_id)->count());
    }

    public function test_approving_payment_for_archived_plan_gives_plan_days_not_a_year(): void
    {
        $plan = SubscriptionPlan::factory()->semester()->create();
        $payment = PaymentRequest::factory()->forPlan($plan)->create();
        $plan->delete();

        $this->actingAs($this->admin)->postJson(route('admin.subscriptions.requests.approve', $payment))->assertOk();

        $subscription = Subscription::where('user_id', $payment->user_id)->first();
        $this->assertTrue($subscription->expires_at->equalTo(Carbon::now()->addDays(180)));
    }

    public function test_payment_without_plan_is_not_approved_with_a_guessed_year(): void
    {
        $payment = PaymentRequest::factory()->create(['subscription_plan_id' => null]);

        $this->actingAs($this->admin)
            ->postJson(route('admin.subscriptions.requests.approve', $payment))
            ->assertUnprocessable();

        $this->assertSame(0, Subscription::count());
        $this->assertSame(PaymentRequest::STATUS_PENDING, $payment->fresh()->status);
    }

    public function test_approving_writes_history_with_previous_and_new_expiry(): void
    {
        $student = User::factory()->create();
        $previous = Carbon::now()->addDays(20);
        Subscription::factory()->create(['user_id' => $student->id, 'expires_at' => $previous]);
        $plan = SubscriptionPlan::factory()->semester()->create();
        $payment = PaymentRequest::factory()->forPlan($plan)->create(['user_id' => $student->id]);

        $this->actingAs($this->admin)
            ->postJson(route('admin.subscriptions.requests.approve', $payment))
            ->assertOk()
            ->assertJsonPath('success', true);

        $grant = SubscriptionGrant::where('payment_request_id', $payment->id)->sole();
        $this->assertSame(180, $grant->days);
        $this->assertTrue($grant->previous_expires_at->equalTo($previous));
        $this->assertTrue($grant->new_expires_at->equalTo($previous->copy()->addDays(180)));
        $this->assertSame($this->admin->id, $grant->granted_by);
        $this->assertSame(1, Subscription::where('user_id', $student->id)->count());
    }

    public function test_second_payment_after_expiry_starts_today(): void
    {
        $student = User::factory()->create();
        Subscription::factory()->expired()->create(['user_id' => $student->id]);
        $payment = PaymentRequest::factory()->create(['user_id' => $student->id]);

        $this->actingAs($this->admin)->postJson(route('admin.subscriptions.requests.approve', $payment))->assertOk();

        $grant = SubscriptionGrant::where('payment_request_id', $payment->id)->sole();
        $this->assertNull($grant->previous_expires_at);
        $this->assertTrue($grant->new_expires_at->equalTo(Carbon::now()->addDays(365)));
    }

    public function test_pending_preview_shows_the_date_access_would_reach(): void
    {
        $plan = SubscriptionPlan::factory()->semester()->create();
        PaymentRequest::factory()->forPlan($plan)->create();

        $response = $this->actingAs($this->admin)->get(route('admin.subscriptions.index'));

        $response->assertOk();
        $pending = $response->viewData('initialPendingRequests');
        $this->assertSame('14 de mayo de 2027', $pending[0]['would_expire_at']);
        $this->assertSame('image', $pending[0]['proof_kind']);
    }

    public function test_pdf_proof_is_flagged_as_pdf(): void
    {
        PaymentRequest::factory()->pdf()->create();

        $pending = $this->actingAs($this->admin)->get(route('admin.subscriptions.index'))->viewData('initialPendingRequests');

        $this->assertSame('pdf', $pending[0]['proof_kind']);
    }

    public function test_codes_cannot_be_generated_for_an_archived_plan(): void
    {
        $plan = SubscriptionPlan::factory()->create();
        $plan->delete();

        $this->actingAs($this->admin)
            ->postJson(route('admin.subscriptions.codes.store'), ['subscription_plan_id' => $plan->id, 'quantity' => 2])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('subscription_plan_id');

        $this->assertSame(0, AccessCode::count());
    }

    public function test_archived_plan_name_can_be_reused_but_restore_detects_the_clash(): void
    {
        $old = SubscriptionPlan::factory()->create(['name' => 'Plan Anual']);
        $old->delete();

        $this->actingAs($this->admin)
            ->postJson(route('admin.subscriptions.plans.store'), ['name' => 'Plan Anual', 'price' => 150, 'duration_days' => 365])
            ->assertCreated();

        $this->actingAs($this->admin)
            ->postJson(route('admin.subscriptions.plans.restore', $old->id))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->assertTrue($old->fresh()->trashed());
    }

    public function test_old_pending_payment_is_never_pushed_out_of_the_inbox(): void
    {
        $oldPending = PaymentRequest::factory()->create();
        PaymentRequest::factory()->count(120)->approved()->create();

        $response = $this->actingAs($this->admin)->get(route('admin.subscriptions.index'));

        $pendingIds = collect($response->viewData('initialPendingRequests'))->pluck('id');
        $this->assertTrue($pendingIds->contains($oldPending->id));
        $this->assertSame(1, $response->viewData('initialSummary')['pending_payments']);

        $reviewed = $response->viewData('initialReviewedRequests');
        $this->assertCount(50, $reviewed['items']);
        $this->assertSame(2, $reviewed['next_page']);
        $this->assertSame(120, $reviewed['total']);

        $this->actingAs($this->admin)
            ->getJson(route('admin.subscriptions.requests.index', ['page' => 3]))
            ->assertOk()
            ->assertJsonCount(20, 'items')
            ->assertJsonPath('next_page', null);
    }

    public function test_code_search_treats_percent_as_text_not_wildcard(): void
    {
        AccessCode::factory()->count(3)->create(['created_by' => $this->admin->id]);
        AccessCode::factory()->create(['created_by' => $this->admin->id, 'reference' => 'Descuento 50% feria']);

        $this->actingAs($this->admin)
            ->getJson(route('admin.subscriptions.codes.index', ['q' => '%']))
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.reference', 'Descuento 50% feria');
    }

    public function test_admin_can_write_a_single_use_code_by_hand(): void
    {
        $this->actingAs($this->admin)
            ->postJson(route('admin.subscriptions.codes.store'), ['custom_code' => ' 2026Arte ', 'duration_days' => 30, 'quantity' => 1])
            ->assertCreated()
            ->assertJsonPath('access_code.code', '2026ARTE');

        $student = User::factory()->create();
        $student->assignRole('User');
        $other = User::factory()->create();
        $other->assignRole('User');

        // Se canjea sin importar mayúsculas y sirve una sola vez.
        $this->actingAs($student)->postJson(route('membership.redeem'), ['code' => '2026arte'])->assertOk();
        $this->actingAs($other)->postJson(route('membership.redeem'), ['code' => '2026ARTE'])->assertUnprocessable();

        $this->assertTrue($student->fresh()->hasActiveSubscription());
        $this->assertFalse($other->fresh()->hasActiveSubscription());
    }

    public function test_hand_written_code_is_validated(): void
    {
        AccessCode::factory()->create(['code' => '2026ARTE', 'created_by' => $this->admin->id]);
        $store = fn (array $data) => $this->actingAs($this->admin)
            ->postJson(route('admin.subscriptions.codes.store'), $data + ['duration_days' => 30, 'quantity' => 1]);

        $store(['custom_code' => '2026arte'])->assertUnprocessable()->assertJsonValidationErrors('custom_code');
        $store(['custom_code' => 'ARTE'])->assertUnprocessable()->assertJsonValidationErrors('custom_code');
        $store(['custom_code' => 'ARTE 2026'])->assertUnprocessable()->assertJsonValidationErrors('custom_code');
        $store(['custom_code' => 'AÑO-2026'])->assertUnprocessable()->assertJsonValidationErrors('custom_code');
        $store(['custom_code' => '-ARTE2026'])->assertUnprocessable()->assertJsonValidationErrors('custom_code');
        $store(['custom_code' => 'FERIA2026', 'quantity' => 5])->assertUnprocessable()->assertJsonValidationErrors('quantity');

        $this->assertSame(1, AccessCode::count());
    }

    public function test_migration_backfills_history_for_existing_subscriptions(): void
    {
        $this->artisan('migrate:rollback', ['--step' => 1])->assertSuccessful();

        $student = User::factory()->create();
        $payment = PaymentRequest::factory()->approved()->create(['user_id' => $student->id, 'reviewed_by' => $this->admin->id]);
        Subscription::factory()->create([
            'user_id' => $student->id,
            'payment_request_id' => $payment->id,
            'starts_at' => Carbon::now()->subDays(10),
            'expires_at' => Carbon::now()->addDays(170),
        ]);

        $this->artisan('migrate')->assertSuccessful();

        $grant = SubscriptionGrant::sole();
        $this->assertSame($student->id, $grant->user_id);
        $this->assertSame($payment->id, $grant->payment_request_id);
        $this->assertSame(180, $grant->days);
        $this->assertSame($this->admin->id, $grant->granted_by);
    }

    public function test_redeeming_a_code_writes_history(): void
    {
        $student = User::factory()->create();
        $student->assignRole('User');
        $code = AccessCode::factory()->create(['created_by' => $this->admin->id, 'duration_days' => 5]);

        $this->actingAs($student)->postJson(route('membership.redeem'), ['code' => $code->code])->assertOk();

        $grant = SubscriptionGrant::where('access_code_id', $code->id)->sole();
        $this->assertSame(5, $grant->days);
        $this->assertSame(SubscriptionGrant::SOURCE_CODE, $grant->source());
        $this->assertSame($this->admin->id, $grant->granted_by);
    }
}
