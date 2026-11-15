<?php

namespace Tests\Feature\TaxRelief;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\SentMessage;
use Tests\TestCase;

/** Monitor diario: avisa por correo si boliviaimpuestos se rompe o cambia el cálculo. */
class CheckUpstreamCommandTest extends TestCase
{
    private const PLAN_URL = 'boliviaimpuestos.com/maletin/fap.php*';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['tax_relief.monitor.alert_email' => 'alertas@example.com']);
    }

    private function plan(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/tax-relief/plan-caso-base.json')), true);
    }

    /** @return Collection<int, SentMessage> */
    private function sentMails(): Collection
    {
        return app('mail.manager')->mailer()->getSymfonyTransport()->messages();
    }

    public function test_quiet_when_everything_matches(): void
    {
        Http::fake([self::PLAN_URL => Http::response($this->plan())]);

        $this->artisan('tax-relief:check')->assertSuccessful();

        $this->assertCount(0, $this->sentMails());
    }

    public function test_warns_when_the_calculation_changed(): void
    {
        $changed = $this->plan();
        $changed['totalContado'] = 12000.00;
        Http::fake([self::PLAN_URL => Http::response($changed)]);

        $this->artisan('tax-relief:check')->assertSuccessful();

        $this->assertCount(1, $this->sentMails());
        $this->assertSame('Calculadora de alivio tributario: cambió el cálculo', $this->sentMails()->first()->getOriginalMessage()->getSubject());
    }

    public function test_alerts_when_the_service_is_broken(): void
    {
        Http::fake([self::PLAN_URL => Http::response('caído', 500)]);

        $this->artisan('tax-relief:check')->assertFailed();

        $this->assertCount(1, $this->sentMails());
        $this->assertSame('Calculadora de alivio tributario: dejó de funcionar', $this->sentMails()->first()->getOriginalMessage()->getSubject());
    }

    public function test_skips_when_the_calculator_is_turned_off(): void
    {
        config(['tax_relief.enabled' => false]);

        $this->artisan('tax-relief:check')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertCount(0, $this->sentMails());
    }
}
