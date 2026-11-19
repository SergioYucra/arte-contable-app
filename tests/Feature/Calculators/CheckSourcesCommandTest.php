<?php

namespace Tests\Feature\Calculators;

use App\Modules\Calculators\Services\BoliviaImpuestosPages;
use App\Modules\Calculators\Services\FormulaReader;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * Revisión diaria: compara las fórmulas de boliviaimpuestos.com con las nuestras
 * (parametros.json) y avisa al desarrollador líder si cambian o si no se pudo revisar.
 * Usa copias recortadas de sus páginas reales (tests/Fixtures/calculators); nunca sale a internet.
 */
class CheckSourcesCommandTest extends TestCase
{
    private const RC_URL = 'boliviaimpuestos.com/maletin/rc-iva.php';

    private const BONO_URL = 'boliviaimpuestos.com/maletin/bono-antiguedad.php';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['calculators.monitor.alert_to' => 'lider@example.com']);
    }

    private function page(string $name): string
    {
        return file_get_contents(base_path("tests/Fixtures/calculators/{$name}.html"));
    }

    /** Las dos páginas como están hoy; se puede reemplazar cualquiera. */
    private function fakePages(array $overrides = []): void
    {
        Http::fake($overrides + [
            self::RC_URL => Http::response($this->page('rc-iva')),
            self::BONO_URL => Http::response($this->page('bono-antiguedad')),
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

    // ------------------------------------------------------------------
    // Todo igual
    // ------------------------------------------------------------------

    public function test_quiet_when_both_pages_match_our_parameters(): void
    {
        $this->fakePages();

        $this->artisan('calculators:check')
            ->expectsOutputToContain('OK: las dos páginas siguen con las mismas fórmulas')
            ->assertSuccessful();

        Http::assertSentCount(2);
        $this->assertCount(0, $this->sentMails());
    }

    public function test_what_we_read_from_their_pages_is_exactly_our_parameters_json(): void
    {
        $ours = FormulaReader::ours();

        foreach (['rc_iva' => 'rc-iva', 'bono_antiguedad' => 'bono-antiguedad'] as $key => $fixture) {
            $html = $this->page($fixture);
            $script = BoliviaImpuestosPages::calculationScript($html, config("calculators.sources.{$key}.marker"));
            $this->assertNotNull($script, "No se encontró el cálculo de {$key}");
            $this->assertSame([], FormulaReader::differences($key, $ours[$key], FormulaReader::fromPage($key, $script, $html)));
            $this->assertSame(config("calculators.sources.{$key}.fingerprint"), BoliviaImpuestosPages::fingerprint($script));
        }
    }

    public function test_requests_only_https_without_following_redirects(): void
    {
        $this->fakePages([self::RC_URL => Http::response('', 301, ['Location' => 'https://otro-sitio.example/'])]);

        $this->artisan('calculators:check')->assertFailed();

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'otro-sitio'));
        $this->assertStringContainsString('RC-IVA: redirigió a otra dirección (código 301)', $this->onlyMail()->getTextBody());
    }

    // ------------------------------------------------------------------
    // Cambió algo
    // ------------------------------------------------------------------

    public function test_warns_when_they_change_a_rate(): void
    {
        $this->fakePages([self::RC_URL => Http::response(str_replace('g*0.1271', 'g*0.1221', $this->page('rc-iva')))]);

        $this->artisan('calculators:check')->assertSuccessful();

        $mail = $this->onlyMail();
        $this->assertSame('Calculadoras RC-IVA y Bono: boliviaimpuestos cambió su cálculo', $mail->getSubject());
        $this->assertSame('lider@example.com', $mail->getTo()[0]->getAddress());
        $this->assertStringContainsString('Aporte a la AFP: usamos 12,71% y su página ahora usa 12,21%.', $mail->getTextBody());
        $this->assertStringContainsString('parametros.json', $mail->getTextBody());
    }

    public function test_warns_when_they_add_a_new_minimum_wage(): void
    {
        $bono = str_replace("var SMN=[[2026,3300,'DS 5516'],", "var SMN=[[2027,3500,'DS 5700'],[2026,3300,'DS 5516'],", $this->page('bono-antiguedad'));
        $this->fakePages([self::BONO_URL => Http::response($bono)]);

        $this->artisan('calculators:check')->assertSuccessful();

        $this->assertStringContainsString('Agregaron el salario mínimo de 2027: Bs 3.500 (DS 5700).', $this->onlyMail()->getTextBody());
    }

    public function test_warns_when_the_bonus_scale_changes(): void
    {
        $bono = str_replace('[25,null,50]', '[25,null,55]', $this->page('bono-antiguedad'));
        $this->fakePages([self::BONO_URL => Http::response($bono)]);

        $this->artisan('calculators:check')->assertSuccessful();

        $body = $this->onlyMail()->getTextBody();
        $this->assertStringContainsString('Escala del bono: usamos', $body);
        $this->assertStringContainsString('25 años o más 55%', $body);
    }

    public function test_warns_when_their_code_changes_even_if_rates_stay_the_same(): void
    {
        $rc = str_replace('var saldo=Math.max(0,sinComp-sf-compFac);', 'var saldo=Math.max(0,sinComp-compFac);', $this->page('rc-iva'));
        $this->assertNotSame($this->page('rc-iva'), $rc);
        $this->fakePages([self::RC_URL => Http::response($rc)]);

        $this->artisan('calculators:check')->assertSuccessful();

        $this->assertStringContainsString('El código de su cálculo cambió', $this->onlyMail()->getTextBody());
    }

    // ------------------------------------------------------------------
    // No se pudo revisar
    // ------------------------------------------------------------------

    public function test_reports_when_their_site_fails(): void
    {
        $this->fakePages([self::RC_URL => Http::response('Error', 500)]);

        $this->artisan('calculators:check')->assertFailed();

        $mail = $this->onlyMail();
        $this->assertSame('Calculadoras RC-IVA y Bono: no se pudo revisar boliviaimpuestos', $mail->getSubject());
        $this->assertStringContainsString('RC-IVA: respondió con un error (código 500)', $mail->getTextBody());
        $this->assertStringContainsString('Los estudiantes no notan nada', $mail->getTextBody());
    }

    public function test_reports_when_cloudflare_blocks_the_check(): void
    {
        $this->fakePages([self::BONO_URL => Http::response('<html>Just a moment...</html>', 403, ['Server' => 'cloudflare'])]);

        $this->artisan('calculators:check')->assertFailed();

        $this->assertStringContainsString('Cloudflare bloqueó la revisión automática (código 403)', $this->onlyMail()->getTextBody());
    }

    public function test_an_outage_behind_cloudflare_is_not_reported_as_a_block(): void
    {
        $this->fakePages([self::RC_URL => Http::response('<html>Service Unavailable</html>', 503, ['Server' => 'cloudflare', 'cf-ray' => 'abc123'])]);

        $this->artisan('calculators:check')->assertFailed();

        $body = $this->onlyMail()->getTextBody();
        $this->assertStringContainsString('RC-IVA: respondió con un error (código 503)', $body);
        $this->assertStringNotContainsString('Cloudflare bloqueó', $body);
    }

    public function test_the_same_warning_is_not_sent_again_every_day(): void
    {
        $rate = '0.1221';
        $this->fakePages([self::RC_URL => function () use (&$rate) {
            return Http::response(str_replace('g*0.1271', "g*{$rate}", $this->page('rc-iva')));
        }]);

        $this->artisan('calculators:check')->assertSuccessful();
        $this->artisan('calculators:check')
            ->expectsOutputToContain('El mismo aviso ya se envió')
            ->assertSuccessful();

        $this->assertCount(1, $this->sentMails());

        // Un cambio distinto sí vuelve a avisar.
        $rate = '0.1200';
        $this->artisan('calculators:check')->assertSuccessful();
        $this->assertCount(2, $this->sentMails());
    }

    public function test_reports_when_their_site_does_not_answer(): void
    {
        Http::fake([
            self::RC_URL => fn () => throw new ConnectionException('cURL error 28: timeout'),
            self::BONO_URL => Http::response($this->page('bono-antiguedad')),
        ]);

        $this->artisan('calculators:check')->assertFailed();

        $this->assertStringContainsString('RC-IVA: no respondió', $this->onlyMail()->getTextBody());
    }

    public function test_reports_when_the_calculation_is_no_longer_on_the_page(): void
    {
        $this->fakePages([self::RC_URL => Http::response('<html><body><script>var otra=1;</script></body></html>')]);

        $this->artisan('calculators:check')->assertFailed();

        $this->assertStringContainsString('no encontramos el cálculo en su página', $this->onlyMail()->getTextBody());
    }

    public function test_reports_when_a_value_can_no_longer_be_read(): void
    {
        $this->fakePages([self::RC_URL => Http::response(str_replace('var afp=g*0.1271;', 'var afp=aporte(g);', $this->page('rc-iva')))]);

        $this->artisan('calculators:check')->assertFailed();

        $this->assertStringContainsString('ya no encontramos el aporte a la AFP', $this->onlyMail()->getTextBody());
    }

    public function test_reports_when_the_page_is_unusually_large(): void
    {
        config(['calculators.monitor.max_bytes' => 1000]);
        $this->fakePages();

        $this->artisan('calculators:check')->assertFailed();

        $this->assertStringContainsString('la página pesa mucho más de lo normal', $this->onlyMail()->getTextBody());
    }

    public function test_refuses_an_address_outside_boliviaimpuestos(): void
    {
        config(['calculators.sources.rc_iva.url' => 'http://boliviaimpuestos.com/maletin/rc-iva.php']);
        Http::fake([self::BONO_URL => Http::response($this->page('bono-antiguedad'))]);

        $this->artisan('calculators:check')->assertFailed();

        Http::assertSentCount(1);
        $this->assertStringContainsString('no es una página https de boliviaimpuestos.com', $this->onlyMail()->getTextBody());
    }

    // ------------------------------------------------------------------
    // Correo
    // ------------------------------------------------------------------

    public function test_without_a_recipient_the_warning_only_goes_to_the_log(): void
    {
        config(['calculators.monitor.alert_to' => '']);
        $this->fakePages([self::RC_URL => Http::response('Error', 500)]);

        $this->artisan('calculators:check')
            ->expectsOutputToContain('el aviso solo queda en el log')
            ->assertFailed();

        $this->assertCount(0, $this->sentMails());
    }

    public function test_no_mail_option_only_prints(): void
    {
        $this->fakePages([self::RC_URL => Http::response('Error', 500)]);

        $this->artisan('calculators:check --no-mail')->assertFailed();

        $this->assertCount(0, $this->sentMails());
    }

    public function test_sends_to_every_valid_address_and_skips_invalid_ones(): void
    {
        config(['calculators.monitor.alert_to' => 'lider@example.com, no-es-correo, otro@example.com']);
        $this->fakePages([self::RC_URL => Http::response('Error', 500)]);

        $this->artisan('calculators:check')
            ->expectsOutputToContain('Se ignoraron direcciones no válidas')
            ->assertFailed();

        $to = array_map(fn ($a) => $a->getAddress(), $this->onlyMail()->getTo());
        $this->assertSame(['lider@example.com', 'otro@example.com'], $to);
    }

    public function test_test_mail_option_checks_the_mail_setup(): void
    {
        Http::fake();

        $this->artisan('calculators:check --test-mail')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame('Calculadoras RC-IVA y Bono: correo de prueba', $this->onlyMail()->getSubject());
    }

    public function test_show_fingerprint_prints_the_current_one(): void
    {
        $this->fakePages();

        $this->artisan('calculators:check --show-fingerprint')
            ->expectsOutputToContain('Huella actual de RC-IVA: '.config('calculators.sources.rc_iva.fingerprint'))
            ->assertSuccessful();
    }
}
