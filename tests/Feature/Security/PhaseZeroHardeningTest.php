<?php

namespace Tests\Feature\Security;

use App\Core\Storage\PrivateVault;
use App\Models\User;
use Database\Seeders\CoreSecuritySeeder;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PhaseZeroHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreSecuritySeeder::class);
    }

    /**
     * H6: La vista administrativa monta su isla Vue con data-props limpio sin plantillas inline compiladas en navegador.
     */
    public function test_admin_users_view_mounts_vue_island_with_data_props_without_inline_template(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        $response = $this->actingAs($admin)->get(route('admin.users.index'));

        $response->assertOk();
        $response->assertSee('data-vue-island="UserManagementTable"', false);
        $response->assertSee('data-props="', false);
        $response->assertDontSee('<user-management-table', false);
    }

    /**
     * Sesiones: Desactivar un usuario invalida sus sesiones activas en BD y limpia su remember_token.
     */
    public function test_deactivating_user_revokes_active_database_sessions_and_remember_token(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        $student = User::factory()->create([
            'remember_token' => 'persistent-remember-token',
        ]);
        $student->assignRole('User');

        DB::table('sessions')->insert([
            'id' => 'active-session-123',
            'user_id' => $student->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload' => base64_encode('test'),
            'last_activity' => now()->timestamp,
        ]);

        $response = $this->actingAs($admin)->deleteJson(route('admin.users.destroy', $student));
        $response->assertOk()->assertJson(['success' => true]);

        $trashedStudent = User::withTrashed()->findOrFail($student->id);
        $this->assertTrue($trashedStudent->trashed());
        $this->assertNull($trashedStudent->remember_token);
        $this->assertDatabaseMissing('sessions', ['user_id' => $student->id]);
    }

    /**
     * Middleware verified: Un administrador sin correo verificado no puede acceder a rutas administrativas.
     */
    public function test_unverified_admin_cannot_access_admin_routes(): void
    {
        $unverifiedAdmin = User::factory()->unverified()->create();
        $unverifiedAdmin->assignRole('Admin');

        $response = $this->actingAs($unverifiedAdmin)->get(route('admin.users.index'));

        $response->assertRedirect(route('verification.notice'));
    }

    /**
     * Perfil: Cambiar el correo electrónico en Mi Perfil envía automáticamente el nuevo enlace de verificación.
     */
    public function test_changing_email_in_profile_sends_verification_notification(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'actual@ejemplo.com',
        ]);

        $response = $this->actingAs($user)->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => 'nuevo@ejemplo.com',
        ]);

        $response->assertRedirect(route('profile.edit'));
        $this->assertNull($user->fresh()->email_verified_at);
        Notification::assertSentTo($user->fresh(), VerifyEmail::class);
    }

    /**
     * PrivateVault: Permite streamInline para imágenes seguras (PNG/WebP/PDF) y bloquea SVG/ejecutables contra Stored XSS.
     */
    public function test_private_vault_streams_safe_images_inline_and_blocks_svg_or_executable_uploads(): void
    {
        $tempVaultRoot = sys_get_temp_dir().'/private_vault_test_'.uniqid();
        File::ensureDirectoryExists($tempVaultRoot);

        config(['filesystems.disks.'.PrivateVault::DISK => [
            'driver' => 'local',
            'root' => $tempVaultRoot,
            'visibility' => 'private',
        ]]);
        Storage::forgetDisk(PrivateVault::DISK);

        try {
            // PNG válido real de 1x1 píxel (magic bytes reales para inspección por finfo sin depender de ext-gd)
            $validPngBytes = base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
                true
            );
            $pngFile = UploadedFile::fake()->createWithContent('comprobante.png', $validPngBytes);
            $stored = PrivateVault::store($pngFile, 'payments/2026');

            $inlineResponse = PrivateVault::streamInline($stored['path'], 'comprobante.png');
            $this->assertStringStartsWith('inline;', (string) $inlineResponse->headers->get('Content-Disposition'));
            $this->assertSame('nosniff', $inlineResponse->headers->get('X-Content-Type-Options'));
            $this->assertStringContainsString('sandbox', (string) $inlineResponse->headers->get('Content-Security-Policy'));

            // Un PDF válido debe servirse inline con object-src 'self' sin 'sandbox' vacío (compatible con Chromium/Edge)
            $validPdfBytes = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF";
            $pdfFile = UploadedFile::fake()->createWithContent('comprobante.pdf', $validPdfBytes);
            $storedPdf = PrivateVault::store($pdfFile, 'payments/2026', ['application/pdf']);
            $pdfResponse = PrivateVault::streamInline($storedPdf['path'], 'comprobante.pdf');
            $this->assertStringContainsString("object-src 'self'", (string) $pdfResponse->headers->get('Content-Security-Policy'));
            $this->assertStringNotContainsString('sandbox', (string) $pdfResponse->headers->get('Content-Security-Policy'));

            // Lista blanca de MIME: subir un PNG cuando solo se permite WebP debe rechazarse (422)
            try {
                PrivateVault::store($pngFile, 'editions/1', ['image/webp']);
                $this->fail('Se permitió subir un MIME distinto a la lista blanca.');
            } catch (HttpException $e) {
                $this->assertSame(422, $e->getStatusCode());
            }

            // Un archivo SVG malicioso nunca debe servirse inline
            Storage::disk(PrivateVault::DISK)->put(
                'payments/2026/xss.svg',
                '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'
            );

            try {
                PrivateVault::streamInline('payments/2026/xss.svg');
                $this->fail('Se permitió transmitir inline un archivo SVG potencialmente malicioso.');
            } catch (HttpException $e) {
                $this->assertSame(415, $e->getStatusCode());
            }

            // Un archivo con extensión ejecutable debe rechazarse al intentar almacenarse
            $phpPayload = UploadedFile::fake()->create('shell.php', 2, 'text/x-php');
            try {
                PrivateVault::store($phpPayload, 'payments/2026');
                $this->fail('Se permitió subir un archivo ejecutable PHP a PrivateVault.');
            } catch (HttpException $e) {
                $this->assertSame(422, $e->getStatusCode());
            }
        } finally {
            File::deleteDirectory($tempVaultRoot);
        }
    }

    /**
     * Idioma y SEO: Títulos únicos en español (sin duplicados y sin slugs en inglés) y mensajes de rol en español.
     */
    public function test_pages_render_single_spanish_title_and_role_messages_avoid_english_identifiers(): void
    {
        $landingResponse = $this->get('/');
        $landingResponse->assertOk();
        $landingResponse->assertDontSeeText('Panel de Control');
        // N4: la portada no promete funciones que todavía no existen
        $landingResponse->assertDontSeeText('100% Interactivo');
        $landingResponse->assertDontSeeText('Ejercicios Paso a Paso');
        $landingResponse->assertDontSeeText('ejercicios prácticos');
        $this->assertSame(1, substr_count($landingResponse->getContent(), '<title>'));
        $this->assertStringNotContainsString('noindex', $landingResponse->getContent());

        $loginHtml = $this->get('/login')->assertOk()->getContent();
        $this->assertStringContainsString('<title>Iniciar sesión', $loginHtml);
        $this->assertStringNotContainsString('<title>Login', $loginHtml);
        $this->assertStringContainsString('noindex, nofollow', $loginHtml);

        $superAdmin = User::role('SuperAdmin')->firstOrFail();
        $student = User::factory()->create(['name' => 'María Contadora']);
        $student->assignRole('User');

        $roleResponse = $this->actingAs($superAdmin)->patchJson(route('admin.users.update-role', $student), [
            'role' => 'Manager',
        ]);

        $roleResponse->assertOk();
        $this->assertStringContainsString('Coordinador', (string) $roleResponse->json('message'));
        $this->assertStringNotContainsString('Manager', (string) $roleResponse->json('message'));
    }

    /**
     * Aislamiento silencioso: Admin no ve cuentas SuperAdmin, recibe 404 ante sondas por ID
     * y no posee permiso sobre la bitácora de auditoría ('view-audit-logs').
     */
    public function test_superadmin_is_completely_invisible_to_admin_and_audit_permission_is_restricted(): void
    {
        $superAdmin = User::role('SuperAdmin')->firstOrFail();

        $admin = User::factory()->create([
            'name' => 'Operador Administrativo',
            'email' => 'operador@ejemplo.com',
        ]);
        $admin->assignRole('Admin');

        $manager = User::factory()->create();
        $manager->assignRole('Manager');

        // 1. Permiso de auditoría exclusivo para SuperAdmin
        $this->assertTrue($superAdmin->can('view-audit-logs'));
        $this->assertFalse($admin->can('view-audit-logs'));
        $this->assertFalse($manager->can('view-audit-logs'));

        // 2. En listado de usuarios, Admin no recibe ni el correo ni el nombre del SuperAdmin
        $adminIndex = $this->actingAs($admin)->get(route('admin.users.index'));
        $adminIndex->assertOk();
        $adminIndex->assertDontSee($superAdmin->email, false);
        $adminIndex->assertDontSee($superAdmin->name, false);

        // En cambio, SuperAdmin sí visualiza su propia cuenta maestra
        $superIndex = $this->actingAs($superAdmin)->get(route('admin.users.index'));
        $superIndex->assertOk();
        $superIndex->assertSee($superAdmin->email, false);

        // 3. Sondas directas por ID contra SuperAdmin devuelven 404 (anti-enumeración)
        $this->actingAs($admin)
            ->patchJson(route('admin.users.update-role', $superAdmin), ['role' => 'User'])
            ->assertNotFound();

        $this->actingAs($admin)
            ->deleteJson(route('admin.users.destroy', $superAdmin))
            ->assertNotFound();

        $this->actingAs($admin)
            ->postJson(route('admin.users.resend-verification', $superAdmin))
            ->assertNotFound();
    }
}
