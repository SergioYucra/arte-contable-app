<?php

namespace Tests\Feature\Security;

use App\Core\Storage\PrivateVault;
use App\Providers\AppServiceProvider;
use App\Support\Security\FailClosedPwnedVerifier;
use Database\Seeders\CoreSecuritySeeder;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\NotPwnedVerifier;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Revisión de seguridad OWASP Top 10:2025 (REVISION-SEGURIDAD.pdf): configuración y endurecimiento.
 * P1 (A02), P2 (A02/A10), P3 (A04), P6 (A09), P10 (A06), P14 (A02) y P17 (A10).
 */
class OwaspReviewHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreSecuritySeeder::class);
    }

    // ---------------------------------------------------------------- P1: cabeceras

    public function test_every_page_sends_security_headers_and_a_nonce_based_csp(): void
    {
        $response = $this->get('/login')->assertOk();

        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

        $csp = (string) $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("base-uri 'self'", $csp);
        $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $csp);
        $this->assertStringNotContainsString('fonts.bunny.net', $csp);

        // El script en línea del tema lleva el mismo nonce que la CSP.
        $this->assertSame(1, preg_match("/'nonce-([^']+)'/", $csp, $matches));
        $this->assertStringContainsString('nonce="'.$matches[1].'"', $response->getContent());

        // P15: la tipografía ya no se carga desde un tercero.
        $this->assertStringNotContainsString('fonts.bunny.net', $response->getContent());
    }

    public function test_hsts_is_only_sent_in_production_over_https(): void
    {
        $this->get('https://localhost/login')->assertHeaderMissing('Strict-Transport-Security');

        $this->app['env'] = 'production';

        $this->get('https://localhost/login')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    // ---------------------------------------------------------------- P3: HTTPS

    public function test_http_requests_are_redirected_to_https_when_forced(): void
    {
        config(['app.force_https' => true]);

        $this->get('http://localhost/login')->assertStatus(301)->assertRedirect('https://localhost/login');
        $this->get('https://localhost/login')->assertOk();
    }

    // ---------------------------------------------------------------- P2: configuración de producción

    public function test_production_refuses_to_boot_with_debug_or_log_mailer(): void
    {
        $guard = new ReflectionMethod(AppServiceProvider::class, 'refuseDangerousProductionConfiguration');

        config(['app.debug' => true, 'mail.default' => 'smtp']);
        $this->assertThrows(fn () => $guard->invoke(null), RuntimeException::class, 'APP_DEBUG=true');

        config(['app.debug' => false, 'mail.default' => 'log']);
        $this->assertThrows(fn () => $guard->invoke(null), RuntimeException::class, 'MAIL_MAILER=log');

        config(['app.debug' => false, 'mail.default' => 'smtp']);
        $guard->invoke(null);
        $this->addToAssertionCount(1);
    }

    public function test_security_check_fails_on_a_development_configuration(): void
    {
        $this->artisan('security:check', ['--offline' => true])
            ->expectsOutputToContain('FALLA')
            ->assertFailed();
    }

    private function productionLikeConfiguration(): void
    {
        $this->app['env'] = 'production';

        config([
            'app.debug' => false,
            'app.url' => 'https://arte-contable.example',
            'session.secure' => true,
            'session.encrypt' => true,
            'app.force_https' => true,
            'mail.default' => 'smtp',
            'mail.from.address' => 'hola@arte-contable.example',
            'logging.channels.stack.level' => 'warning',
            'services.turnstile.enabled' => true,
            'services.turnstile.site_key' => '0x4AAAAAAAclaveReal',
            'services.turnstile.secret_key' => '0x4AAAAAAAsecretoReal',
            'app.pwned_passwords_fail_closed' => true,
            'backup.backup.destination.disks' => ['backups'],
        ]);
    }

    public function test_security_check_passes_on_a_correct_production_configuration(): void
    {
        $this->productionLikeConfiguration();

        Http::fake([
            'api.pwnedpasswords.com/*' => Http::response("0018A45C4D1DEF81644B54AB7F969B88D65:1\n"),
            'challenges.cloudflare.com/*' => Http::response(['success' => false, 'error-codes' => ['invalid-input-secret']]),
        ]);

        $this->artisan('security:check')->assertSuccessful();
    }

    public function test_security_check_rejects_test_captcha_keys_and_an_unreachable_hibp(): void
    {
        $this->productionLikeConfiguration();
        config(['services.turnstile.site_key' => '1x00000000000000000000AA']);

        Http::fake(fn () => throw new ConnectionException('Sin salida a internet'));

        $this->artisan('security:check')
            ->expectsOutputToContain('Turnstile: claves reales')
            ->expectsOutputToContain('Have I Been Pwned')
            ->assertFailed();
    }

    // ---------------------------------------------------------------- P6: backups

    public function test_backups_are_scheduled_and_stored_on_a_private_disk(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('backup:run')
            ->expectsOutputToContain('backup:clean')
            ->expectsOutputToContain('backup:monitor')
            ->assertSuccessful();

        $this->assertSame(['backups'], config('backup.backup.destination.disks'));
        $this->assertFalse(config('filesystems.disks.backups.serve'));
    }

    // ---------------------------------------------------------------- P10: bóveda privada

    public function test_private_vault_accepts_only_whitelisted_formats_whose_content_matches(): void
    {
        $root = sys_get_temp_dir().'/vault_whitelist_'.uniqid();
        File::ensureDirectoryExists($root);
        config(['filesystems.disks.'.PrivateVault::DISK => ['driver' => 'local', 'root' => $root]]);
        Storage::forgetDisk(PrivateVault::DISK);

        $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', true);

        // UploadedFile::fake() informa el tipo según el nombre; un archivo real lo detecta por su contenido
        // (finfo), igual que una subida de verdad.
        $upload = function (string $name, string $content) use ($root): UploadedFile {
            $path = $root.'/subida_'.uniqid();
            file_put_contents($path, $content);

            return new UploadedFile($path, $name, null, null, true);
        };

        try {
            $stored = PrivateVault::store($upload('foto.png', $png), 'pruebas');
            $this->assertStringEndsWith('.png', $stored['path']);

            $rejected = [
                'formato fuera de la lista' => $upload('pagina.html', '<html><body><p>hola</p></body></html>'),
                'imagen con extensión ejecutable' => $upload('foto.php', $png),
                'texto disfrazado de imagen' => $upload('foto.png', 'esto no es una imagen'),
                'svg' => $upload('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'),
                'sin extensión' => $upload('archivo', $png),
            ];

            foreach ($rejected as $case => $file) {
                try {
                    PrivateVault::store($file, 'pruebas');
                    $this->fail("La bóveda aceptó: {$case}.");
                } catch (HttpException $e) {
                    $this->assertSame(422, $e->getStatusCode(), $case);
                }
            }
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_private_vault_download_names_cannot_inject_headers(): void
    {
        $root = sys_get_temp_dir().'/vault_download_'.uniqid();
        File::ensureDirectoryExists($root);
        config(['filesystems.disks.'.PrivateVault::DISK => ['driver' => 'local', 'root' => $root]]);
        Storage::forgetDisk(PrivateVault::DISK);

        try {
            Storage::disk(PrivateVault::DISK)->put('pruebas/doc.pdf', "%PDF-1.4\n%%EOF");

            $response = PrivateVault::download('pruebas/doc.pdf', "informe\"\r\nSet-Cookie: x=1\\..\\balance.pdf");
            $disposition = (string) $response->headers->get('Content-Disposition');

            $this->assertStringStartsWith('attachment;', $disposition);
            $this->assertStringNotContainsString("\n", $disposition);
            $this->assertStringNotContainsString("\r", $disposition);
            $this->assertStringContainsString('balance.pdf', $disposition);
            $this->assertNull($response->headers->get('Set-Cookie'));
        } finally {
            File::deleteDirectory($root);
        }
    }

    // ---------------------------------------------------------------- P14: proxies

    public function test_client_ip_comes_from_the_proxy_header_only_when_the_proxy_is_trusted(): void
    {
        Route::get('/_pruebas/ip', fn (Request $request) => $request->ip());

        $request = fn () => $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
            ->withHeaders(['X-Forwarded-For' => '203.0.113.9'])
            ->get('/_pruebas/ip');

        $request()->assertSeeText('10.0.0.5');

        config(['app.trusted_proxies' => '10.0.0.5']);

        $request()->assertSeeText('203.0.113.9');
    }

    // ---------------------------------------------------------------- P17: contraseñas filtradas

    private function validateUncompromised(string $password): \Illuminate\Validation\Validator
    {
        $validator = Validator::make(['password' => $password], ['password' => [Password::min(8)->uncompromised()]]);
        $validator->passes();

        return $validator;
    }

    public function test_pwned_password_check_fails_closed_when_hibp_does_not_answer(): void
    {
        $this->assertInstanceOf(FailClosedPwnedVerifier::class, app(UncompromisedVerifier::class));

        Http::fake(fn () => throw new ConnectionException('Sin salida a internet'));

        try {
            $this->validateUncompromised('UnaClave123!');
            $this->fail('La contraseña se aceptó sin poder comprobarla.');
        } catch (ValidationException $e) {
            $this->assertSame(FailClosedPwnedVerifier::UNAVAILABLE_MESSAGE, $e->errors()['password'][0]);
        }
    }

    public function test_pwned_password_check_fails_closed_on_an_error_response(): void
    {
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 503)]);

        $this->expectException(ValidationException::class);

        $this->validateUncompromised('UnaClave123!');
    }

    public function test_pwned_password_check_still_works_when_hibp_answers(): void
    {
        $password = 'UnaClave123!';
        $hash = strtoupper(sha1($password));

        Http::fake(['api.pwnedpasswords.com/*' => Http::response(substr($hash, 5).":42\nAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA:1\n")]);
        $this->assertTrue($this->validateUncompromised($password)->errors()->has('password'), 'Una contraseña filtrada debía rechazarse.');
    }

    public function test_pwned_password_check_can_return_to_laravel_default(): void
    {
        $this->app->forgetInstance(UncompromisedVerifier::class);
        config(['app.pwned_passwords_fail_closed' => false]);

        $verifier = app(UncompromisedVerifier::class);

        $this->assertInstanceOf(NotPwnedVerifier::class, $verifier);
        $this->assertNotInstanceOf(FailClosedPwnedVerifier::class, $verifier);
    }
}
