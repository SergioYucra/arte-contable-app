<?php

namespace Tests\Feature\TaxRelief;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * Revisión diaria: prueba los 3 servicios de boliviaimpuestos y avisa al
 * desarrollador líder por correo si alguno falla o cambia el cálculo.
 */
class CheckUpstreamCommandTest extends TestCase
{
    private const PLAN_URL = 'boliviaimpuestos.com/maletin/fap.php*';

    private const CALC_URL = 'boliviaimpuestos.com/maletin/deudas.php?action=calc*';

    private const DUE_URL = 'boliviaimpuestos.com/maletin/deudas.php?action=vencimiento*';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['tax_relief.monitor.alert_to' => 'lider@example.com']);
    }

    private function fixture(string $name): array
    {
        return json_decode(file_get_contents(base_path("tests/Fixtures/tax-relief/{$name}.json")), true);
    }

    /** Los 3 servicios responden como siempre; se puede reemplazar cualquiera. */
    private function fakeUpstream(array $overrides = []): void
    {
        Http::fake($overrides + [
            self::PLAN_URL => Http::response($this->fixture('plan-caso-base')),
            self::CALC_URL => Http::response($this->fixture('desglose-con-sancion')),
            self::DUE_URL => Http::response(['fecha' => '2019-02-15']),
        ]);
    }

    /** @return Collection<int, SentMessage> */
    private function sentMails(): Collection
    {
        return app('mail.manager')->mailer()->getSymfonyTransport()->messages();
    }

    private function onlyMail(): Email
    {
        $this->assertCount(1, $this->sentMails());

        return $this->sentMails()->first()->getOriginalMessage();
    }

    public function test_quiet_when_all_three_services_match(): void
    {
        $this->fakeUpstream();

        $this->artisan('tax-relief:check')->assertSuccessful();

        Http::assertSentCount(3);
        $this->assertCount(0, $this->sentMails());
    }

    public function test_warns_the_lead_developer_when_the_calculation_changed(): void
    {
        $changed = $this->fixture('plan-caso-base');
        $changed['totalContado'] = 12000.00;
        $this->fakeUpstream([self::PLAN_URL => Http::response($changed)]);

        $this->artisan('tax-relief:check')->assertSuccessful();

        $mail = $this->onlyMail();
        $this->assertSame('Calculadora tributaria: cambió el cálculo', $mail->getSubject());
        $this->assertSame('lider@example.com', $mail->getTo()[0]->getAddress());
        $this->assertStringContainsString('Al contado: antes 12352.15, ahora 12000', $mail->getTextBody());
    }

    public function test_alerts_when_only_the_breakdown_service_breaks(): void
    {
        $this->fakeUpstream([self::CALC_URL => Http::response('caído', 500)]);

        $this->artisan('tax-relief:check')->assertFailed();

        $mail = $this->onlyMail();
        $this->assertSame('Calculadora tributaria: dejó de funcionar', $mail->getSubject());
        $this->assertStringContainsString('Desglose', $mail->getTextBody());
        $this->assertStringNotContainsString('Plan al contado y en cuotas (fap.php): no respondió', $mail->getTextBody());
    }

    public function test_alerts_when_the_due_date_service_changes_its_format(): void
    {
        $this->fakeUpstream([self::DUE_URL => Http::response(['date' => '2019-02-15'])]);

        $this->artisan('tax-relief:check')->assertFailed();

        $this->assertStringContainsString('formato distinto', $this->onlyMail()->getTextBody());
    }

    public function test_sends_one_mail_to_every_configured_address(): void
    {
        config(['tax_relief.monitor.alert_to' => 'lider@example.com, respaldo@example.com, no-es-un-correo']);
        $this->fakeUpstream([self::PLAN_URL => Http::response('caído', 500)]);

        $this->artisan('tax-relief:check')
            ->expectsOutputToContain('Se ignoraron direcciones no válidas')
            ->assertFailed();

        $to = array_map(fn ($a) => $a->getAddress(), $this->onlyMail()->getTo());
        $this->assertSame(['lider@example.com', 'respaldo@example.com'], $to);
    }

    public function test_without_an_address_it_only_logs(): void
    {
        config(['tax_relief.monitor.alert_to' => '']);
        $this->fakeUpstream([self::PLAN_URL => Http::response('caído', 500)]);

        $this->artisan('tax-relief:check')
            ->expectsOutputToContain('No hay correo del desarrollador líder configurado')
            ->assertFailed();

        $this->assertCount(0, $this->sentMails());
    }

    public function test_no_mail_option_only_reports(): void
    {
        $this->fakeUpstream([self::PLAN_URL => Http::response('caído', 500)]);

        $this->artisan('tax-relief:check', ['--no-mail' => true])->assertFailed();

        $this->assertCount(0, $this->sentMails());
    }

    public function test_test_mail_option_checks_the_mail_setup_without_calling_the_service(): void
    {
        $this->artisan('tax-relief:check', ['--test-mail' => true])
            ->expectsOutputToContain('Aviso enviado a: lider@example.com')
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame('Calculadora tributaria: correo de prueba', $this->onlyMail()->getSubject());
    }

    public function test_skips_when_the_calculator_is_turned_off(): void
    {
        config(['tax_relief.enabled' => false]);

        $this->artisan('tax-relief:check')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertCount(0, $this->sentMails());
    }

    public function test_it_is_scheduled_every_day(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('tax-relief:check')->assertSuccessful();
    }
}
