<?php

namespace Tests\Feature\TaxRelief;

use App\Models\User;
use Database\Seeders\CoreSecuritySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Calculadora de alivio tributario: acceso por rol, validación, lista blanca de
 * lo que sale, límites, cortacircuito, caché y saneamiento de lo que entra.
 * Nunca llama a boliviaimpuestos.com de verdad (Http::preventStrayRequests).
 */
class TaxReliefCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private const PLAN_URL = 'boliviaimpuestos.com/maletin/fap.php*';

    private const CALC_URL = 'boliviaimpuestos.com/maletin/deudas.php?action=calc*';

    private const DUE_URL = 'boliviaimpuestos.com/maletin/deudas.php?action=vencimiento*';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreSecuritySeeder::class);
        Http::preventStrayRequests();
    }

    // ------------------------------------------------------------------
    // Ayudantes
    // ------------------------------------------------------------------

    private function student(string $role = 'User'): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole($role);

        return $user;
    }

    private function fixture(string $name): array
    {
        return json_decode(file_get_contents(base_path("tests/Fixtures/tax-relief/{$name}.json")), true);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'fecha_solicitud' => '2026-11-15',
            'garantia' => 'efectivo',
            'cuotas' => 12,
            'deudas' => [[
                'impuesto' => 'IVA',
                'periodo' => '2019-01',
                'fecha_vencimiento' => '2019-02-15',
                'tributo_omitido' => '10000',
            ]],
        ], $overrides);
    }

    private function fakePlan(?array $body = null, int $status = 200): void
    {
        Http::fake([self::PLAN_URL => Http::response($body ?? $this->fixture('plan-caso-base'), $status)]);
    }

    private function calculate(User $user, array $payload): TestResponse
    {
        return $this->actingAs($user)->postJson(route('tax-relief.calculate'), $payload);
    }

    // ------------------------------------------------------------------
    // Acceso
    // ------------------------------------------------------------------

    public function test_guests_are_sent_to_login(): void
    {
        $this->get(route('tax-relief.index'))->assertRedirect(route('login'));
        $this->postJson(route('tax-relief.calculate'), $this->payload())->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_unverified_email_cannot_use_it(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);
        $user->assignRole('User');

        $this->actingAs($user)->postJson(route('tax-relief.calculate'), $this->payload())->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_account_without_role_is_rejected(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)->get(route('tax-relief.index'))->assertForbidden();
        $this->calculate($user, $this->payload())->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_student_sees_the_page_and_the_menu_link(): void
    {
        $this->actingAs($this->student())
            ->get(route('tax-relief.index'))
            ->assertOk()
            ->assertSee('data-vue-island="TaxReliefCalculator"', false)
            ->assertSee(route('tax-relief.index'), false);
    }

    // ------------------------------------------------------------------
    // Cálculo
    // ------------------------------------------------------------------

    public function test_student_gets_the_plan_in_our_own_format(): void
    {
        $this->fakePlan();

        $this->calculate($this->student(), $this->payload())
            ->assertOk()
            ->assertJsonPath('data.total_without_relief', 21400.77)
            ->assertJsonPath('data.total_cash', 12352.15)
            ->assertJsonPath('data.savings_cash', 9048.62)
            ->assertJsonPath('data.plan_base', 14185.57)
            ->assertJsonPath('data.down_payment', 709.28)
            ->assertJsonPath('data.balance', 12767.01)
            ->assertJsonPath('data.installments', 12)
            ->assertJsonPath('data.plan_final_total', 16371.89)
            ->assertJsonPath('data.extra_vs_cash_pct', 32.5)
            ->assertJsonPath('data.debts.0.treatment', 'regularizable')
            ->assertJsonCount(12, 'data.schedule')
            ->assertJsonPath('data.schedule.0.total', 1157.02)
            ->assertJsonMissingPath('data.totalContado');
    }

    public function test_only_whitelisted_fields_leave_our_server(): void
    {
        $this->fakePlan();

        $this->calculate($this->student(), $this->payload([
            'modo' => 'normal',
            'url' => 'http://127.0.0.1/admin',
            'nit' => '1234567',
        ]))->assertOk();

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            return str_starts_with($request->url(), 'https://boliviaimpuestos.com/maletin/fap.php?action=fap')
                && array_keys($body) === ['fechaSolicitud', 'mesPrimera', 'tipoGarantia', 'modo', 'deudas']
                && $body['modo'] === 'regularizacion'
                && array_keys($body['deudas'][0]) === ['impuesto', 'periodo', 'fechaVenc', 'tributoOmitido', 'cuotas']
                && $body['deudas'][0]['tributoOmitido'] === 10000.0;
        });
    }

    public function test_unknown_fields_inside_a_debt_are_rejected(): void
    {
        $payload = $this->payload();
        $payload['deudas'][0]['nit_completo'] = '1234567';

        $this->calculate($this->student(), $payload)->assertUnprocessable()->assertJsonValidationErrors('deudas.0');
        Http::assertNothingSent();
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalidInputs(): array
    {
        return [
            'monto negativo' => [['deudas' => [['tributo_omitido' => '-5']]], 'deudas.0.tributo_omitido'],
            'monto con 3 decimales' => [['deudas' => [['tributo_omitido' => '10.123']]], 'deudas.0.tributo_omitido'],
            'monto desmedido' => [['deudas' => [['tributo_omitido' => '999999999999']]], 'deudas.0.tributo_omitido'],
            'vence después de la solicitud' => [['deudas' => [['fecha_vencimiento' => '2026-12-01']]], 'deudas.0.fecha_vencimiento'],
            'periodo mal escrito' => [['deudas' => [['periodo' => '01/2019']]], 'deudas.0.periodo'],
            'impuesto inventado' => [['deudas' => [['impuesto' => 'ISAE']]], 'deudas.0.impuesto'],
            'demasiadas cuotas' => [['cuotas' => 37], 'cuotas'],
            'garantía inventada' => [['garantia' => 'prenda'], 'garantia'],
            'fecha de solicitud lejana' => [['fecha_solicitud' => '2099-01-01'], 'fecha_solicitud'],
        ];
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_input_never_reaches_the_external_service(array $override, string $field): void
    {
        $this->calculate($this->student(), $this->payload($override))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        Http::assertNothingSent();
    }

    public function test_more_than_ten_debts_are_rejected(): void
    {
        $payload = $this->payload();
        $payload['deudas'] = array_fill(0, 11, $payload['deudas'][0]);

        $this->calculate($this->student(), $payload)->assertUnprocessable()->assertJsonValidationErrors('deudas');
        Http::assertNothingSent();
    }

    public function test_forgiven_debts_are_reported(): void
    {
        $this->fakePlan($this->fixture('plan-con-condonada'));

        $payload = $this->payload();
        $payload['deudas'][] = ['impuesto' => 'IT', 'periodo' => '2020-05', 'fecha_vencimiento' => '2020-06-15', 'tributo_omitido' => '3000'];

        $this->calculate($this->student(), $payload)
            ->assertOk()
            ->assertJsonPath('data.forgiven_count', 1)
            ->assertJsonPath('data.total_forgiven', 5754.88)
            ->assertJsonPath('data.debts.1.treatment', 'condonado');
    }

    // ------------------------------------------------------------------
    // Límites, caché y cortacircuito
    // ------------------------------------------------------------------

    public function test_same_calculation_is_served_from_cache(): void
    {
        $this->fakePlan();
        $user = $this->student();

        $this->calculate($user, $this->payload())->assertOk();
        $this->calculate($user, $this->payload())->assertOk();

        Http::assertSentCount(1);
    }

    public function test_each_student_has_a_per_minute_limit(): void
    {
        config(['tax_relief.limits.per_user_per_minute' => 2]);
        $this->fakePlan();
        $user = $this->student();

        $this->calculate($user, $this->payload())->assertOk();
        $this->calculate($user, $this->payload())->assertOk();
        $this->calculate($user, $this->payload())
            ->assertStatus(429)
            ->assertJsonPath('reason', 'too_many');

        // Otro estudiante no se ve afectado.
        $this->calculate($this->student(), $this->payload())->assertOk();
    }

    public function test_global_budget_protects_the_external_service(): void
    {
        config(['tax_relief.limits.upstream_per_minute' => 1]);
        $this->fakePlan();

        $this->calculate($this->student(), $this->payload())->assertOk();
        $this->calculate($this->student(), $this->payload(['deudas' => [['tributo_omitido' => '20000']]]))
            ->assertStatus(429)
            ->assertJsonPath('reason', 'busy');

        Http::assertSentCount(1);
    }

    public function test_breaker_stops_calling_after_repeated_failures(): void
    {
        config(['tax_relief.limits.breaker_failures' => 2]);
        $this->fakePlan(['boom' => true], 500);
        $user = $this->student();

        foreach (['10000', '20000', '30000'] as $amount) {
            $this->calculate($user, $this->payload(['deudas' => [['tributo_omitido' => $amount]]]))
                ->assertStatus(503)
                ->assertJsonPath('reason', 'down')
                ->assertJsonMissingPath('detail');
        }

        Http::assertSentCount(2);
    }

    public function test_kill_switch_turns_everything_off(): void
    {
        config(['tax_relief.enabled' => false]);
        $user = $this->student();

        $this->calculate($user, $this->payload())->assertStatus(503)->assertJsonPath('reason', 'disabled');
        $this->actingAs($user)->get(route('dashboard'))->assertDontSee(route('tax-relief.index'), false);
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------
    // Lo que entra desde afuera
    // ------------------------------------------------------------------

    public function test_unexpected_format_is_not_shown_half_done(): void
    {
        $broken = $this->fixture('plan-caso-base');
        unset($broken['totalContado']);
        $this->fakePlan($broken);

        $this->calculate($this->student(), $this->payload())
            ->assertStatus(503)
            ->assertJsonPath('reason', 'format')
            ->assertJsonMissingPath('data');
    }

    public function test_rejection_message_is_cleaned_before_showing_it(): void
    {
        $this->fakePlan(['error' => '<b onclick="x()">UFV</b> no disponible']);

        $this->calculate($this->student(), $this->payload())
            ->assertStatus(422)
            ->assertJsonPath('reason', 'rejected')
            ->assertJsonPath('detail', 'UFV no disponible');
    }

    public function test_external_notices_are_plain_text(): void
    {
        $plan = $this->fixture('plan-caso-base');
        $plan['avisos'] = ['<script>alert(1)</script>Se ajustó a 13 cuotas.'];
        $this->fakePlan($plan);

        $notice = $this->calculate($this->student(), $this->payload())->assertOk()->json('data.notices.0');

        $this->assertStringNotContainsString('<', $notice);
        $this->assertStringContainsString('Se ajustó a 13 cuotas.', $notice);
    }

    public function test_redirects_are_not_followed(): void
    {
        Http::fake([self::PLAN_URL => Http::response('', 302, ['Location' => 'http://127.0.0.1/interno'])]);

        $this->calculate($this->student(), $this->payload())->assertStatus(503)->assertJsonPath('reason', 'down');
        Http::assertSentCount(1);
    }

    public function test_oversized_responses_are_discarded(): void
    {
        config(['tax_relief.max_response_bytes' => 100]);
        $this->fakePlan();

        $this->calculate($this->student(), $this->payload())->assertStatus(503)->assertJsonPath('reason', 'down');
    }

    public function test_only_https_is_allowed(): void
    {
        config(['tax_relief.base_url' => 'http://boliviaimpuestos.com/maletin/']);

        $this->calculate($this->student(), $this->payload())->assertStatus(503)->assertJsonPath('reason', 'disabled');
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------
    // Desglose y vencimiento
    // ------------------------------------------------------------------

    public function test_breakdown_explains_one_debt(): void
    {
        Http::fake([self::CALC_URL => Http::response($this->fixture('desglose-con-sancion'))]);

        $this->actingAs($this->student())
            ->postJson(route('tax-relief.breakdown'), [
                'fecha_solicitud' => '2026-11-15', 'impuesto' => 'IVA', 'periodo' => '2019-01',
                'fecha_vencimiento' => '2019-02-15', 'tributo_omitido' => '10000', 'con_sancion' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.value_adjustment', 4704.3)
            ->assertJsonPath('data.interest', 6696.47)
            ->assertJsonPath('data.penalty', 8822.58)
            ->assertJsonCount(3, 'data.tranches')
            ->assertJsonPath('data.tranches.0.days', 1461)
            ->assertJsonPath('data.tranches.0.interest', 2591.51);

        Http::assertSent(fn (Request $r) => $r->data()['sancionOmision'] === true && $r->data()['fechaPago'] === '2026-11-15');
    }

    public function test_due_date_lookup_sends_only_the_last_nit_digit(): void
    {
        Http::fake([self::DUE_URL => Http::response(['fecha' => '2019-02-15'])]);
        $user = $this->student();
        $query = ['impuesto' => 'IVA', 'periodo' => '2019-01', 'nit_digito' => 2];

        $this->actingAs($user)->getJson(route('tax-relief.due-date', $query))->assertOk()->assertJsonPath('data.date', '2019-02-15');
        $this->actingAs($user)->getJson(route('tax-relief.due-date', $query))->assertOk();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://boliviaimpuestos.com/maletin/deudas.php?action=vencimiento&periodo=2019-01&impuesto=IVA&nit=2');
    }

    public function test_due_date_lookup_validates_its_input(): void
    {
        $user = $this->student();

        $this->actingAs($user)->getJson(route('tax-relief.due-date', ['impuesto' => 'IVA', 'periodo' => '2019-01', 'nit_digito' => 12]))
            ->assertUnprocessable()->assertJsonValidationErrors('nit_digito');
        $this->actingAs($user)->getJson(route('tax-relief.due-date', ['impuesto' => 'Otro', 'periodo' => '2019-01', 'nit_digito' => 2]))
            ->assertUnprocessable()->assertJsonValidationErrors('impuesto');

        Http::assertNothingSent();
    }
}
