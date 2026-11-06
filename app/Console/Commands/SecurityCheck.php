<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * P2 (H10), P5, P6, P17: lista de chequeo de despliegue. Se corre en el servidor después de cada
 * cambio de .env: `php artisan security:check`. Termina con error si algo crítico está mal, así se
 * puede usar también en un script de despliegue.
 */
class SecurityCheck extends Command
{
    protected $signature = 'security:check {--offline : No probar las conexiones a Have I Been Pwned ni a Cloudflare}';

    protected $description = 'Revisa la configuración de seguridad antes de desplegar en producción';

    /** Claves de prueba públicas de Cloudflare Turnstile: nunca deben llegar a producción. */
    private const TURNSTILE_TEST_PREFIXES = ['1x0000', '2x0000', '3x0000'];

    /** @var list<array{0: string, 1: string, 2: string}> */
    private array $rows = [];

    private bool $failed = false;

    public function handle(): int
    {
        $this->checkEnvironment();
        $this->checkSessionAndHttps();
        $this->checkMail();
        $this->checkTurnstile();
        $this->checkPasswordsAndAudit();
        $this->checkBackups();

        if (! $this->option('offline')) {
            $this->checkOutboundServices();
        }

        $this->table(['Estado', 'Chequeo', 'Detalle'], $this->rows);

        if ($this->failed) {
            $this->error('Hay problemas críticos: corrígelos antes de abrir el sitio.');

            return self::FAILURE;
        }

        $this->info('Sin problemas críticos. Revisa también los avisos.');

        return self::SUCCESS;
    }

    private function checkEnvironment(): void
    {
        $this->expect(app()->isProduction(), 'APP_ENV', 'Es "'.app()->environment().'". En el servidor debe ser production: en local, trustHosts() no se aplica.');
        $this->expect(! config('app.debug'), 'APP_DEBUG', 'Debe ser false: con true, los errores muestran trazas internas.');
        $this->expect(filled(config('app.key')), 'APP_KEY', 'Falta: genera una con php artisan key:generate.');
        $this->expect(Str::startsWith((string) config('app.url'), 'https://'), 'APP_URL', 'Debe empezar con https:// y ser el dominio real (también fija los hosts aceptados).');
        $this->warnUnless(config('logging.channels.'.config('logging.default').'.level', 'debug') !== 'debug', 'LOG_LEVEL', 'En debug el log crece rápido y guarda más detalle del necesario. Usa warning o error.');
    }

    private function checkSessionAndHttps(): void
    {
        $this->expect((bool) config('session.secure'), 'SESSION_SECURE_COOKIE', 'Debe ser true: la cookie de sesión solo debe viajar por HTTPS.');
        $this->expect((bool) config('session.encrypt'), 'SESSION_ENCRYPT', 'Debe ser true en producción.');
        $this->expect((bool) config('app.force_https'), 'FORCE_HTTPS', 'Debe ser true: redirige http:// a https://.');

        $proxies = (string) config('app.trusted_proxies');
        $this->note('TRUSTED_PROXIES', $proxies === '' ? 'Sin proxy de confianza. Correcto si Hostinger sirve la app directo, sin CDN delante.' : "Confía en: {$proxies}.");
    }

    private function checkMail(): void
    {
        $mailer = (string) config('mail.default');
        $this->expect(! in_array($mailer, ['log', 'array'], true), 'MAIL_MAILER', "Es \"{$mailer}\": no envía correos y deja los enlaces de recuperación en el log.");
        $this->warnUnless(! Str::endsWith((string) config('mail.from.address'), '@example.com'), 'MAIL_FROM_ADDRESS', 'Sigue el correo de ejemplo.');
    }

    private function checkTurnstile(): void
    {
        if (! config('services.turnstile.enabled')) {
            $this->markFailed('TURNSTILE_ENABLED', 'El captcha está desactivado: login, registro y recuperación quedan sin protección contra bots.');

            return;
        }

        $siteKey = (string) config('services.turnstile.site_key');
        $secret = (string) config('services.turnstile.secret_key');

        $this->expect($siteKey !== '' && $secret !== '', 'Turnstile: claves', 'Faltan TURNSTILE_SITE_KEY o TURNSTILE_SECRET_KEY: el captcha rechazará todos los formularios.');
        $this->expect(! Str::startsWith($siteKey, self::TURNSTILE_TEST_PREFIXES) && ! Str::startsWith($secret, self::TURNSTILE_TEST_PREFIXES), 'Turnstile: claves reales', 'Son las claves de prueba de Cloudflare, que aprueban a cualquiera. Crea las reales en el panel de Cloudflare.');
    }

    private function checkPasswordsAndAudit(): void
    {
        $this->expect((bool) config('app.pwned_passwords_fail_closed'), 'PWNED_PASSWORDS_FAIL_CLOSED', 'Con false, si HIBP no responde se aceptan contraseñas sin comprobar.');
        $this->warnUnless((bool) config('activitylog.database_triggers'), 'ACTIVITY_LOG_DB_TRIGGERS', 'Sin triggers, la bitácora solo la protege el modelo: los backups (BACKUP_DISKS) son la red de seguridad.');
        $this->note('Usuario de base de datos', 'Comprueba en el panel del hosting que el usuario de la app no tenga privilegio DROP: en MySQL, TRUNCATE no dispara triggers.');
    }

    private function checkBackups(): void
    {
        $disks = (array) config('backup.backup.destination.disks', []);
        $this->expect($disks !== [], 'BACKUP_DISKS', 'No hay discos de destino para los backups.');

        $remote = array_filter($disks, fn ($disk): bool => config("filesystems.disks.{$disk}.driver") !== 'local');
        $this->warnUnless($remote !== [], 'Backups fuera del servidor', 'Todos los destinos son locales: si se pierde el servidor, se pierden también los backups. Suma un disco remoto (por ejemplo s3).');
        $this->warnUnless(filled(config('backup.backup.password')), 'BACKUP_ARCHIVE_PASSWORD', 'Los backups no están cifrados: contienen la base de datos completa.');
        $this->note('Cron', 'Los backups necesitan el cron de Hostinger: * * * * * php /ruta/a/artisan schedule:run');
    }

    private function checkOutboundServices(): void
    {
        try {
            $ok = Http::timeout(5)->withHeaders(['Add-Padding' => 'true'])->get('https://api.pwnedpasswords.com/range/00000')->successful();
        } catch (Throwable) {
            $ok = false;
        }

        $this->expect($ok, 'Conexión a Have I Been Pwned', 'El servidor no llega a api.pwnedpasswords.com: con la falla cerrada, nadie podrá elegir contraseña. Pide al hosting habilitar la salida HTTPS.');

        if (config('services.turnstile.enabled')) {
            try {
                $reachable = Http::timeout(5)->asForm()->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', ['secret' => 'check', 'response' => 'check'])->status() < 500;
            } catch (Throwable) {
                $reachable = false;
            }

            $this->expect($reachable, 'Conexión a Cloudflare Turnstile', 'El servidor no llega a challenges.cloudflare.com: el captcha rechazará todos los formularios.');
        }
    }

    private function expect(bool $condition, string $check, string $detailIfFailed): void
    {
        if ($condition) {
            $this->rows[] = ['OK', $check, ''];

            return;
        }

        $this->markFailed($check, $detailIfFailed);
    }

    private function markFailed(string $check, string $detail): void
    {
        $this->failed = true;
        $this->rows[] = ['FALLA', $check, $detail];
    }

    private function warnUnless(bool $condition, string $check, string $detailIfNot): void
    {
        $this->rows[] = $condition ? ['OK', $check, ''] : ['AVISO', $check, $detailIfNot];
    }

    private function note(string $check, string $detail): void
    {
        $this->rows[] = ['INFO', $check, $detail];
    }
}
