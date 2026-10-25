<?php

namespace Tests\Feature\Security;

use App\Core\Storage\PrivateVault;
use App\Models\Activity;
use App\Models\User;
use Database\Seeders\CoreSecuritySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CoreSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreSecuritySeeder::class);
    }

    /**
     * B1 / S1: Registro público abierto asigna automáticamente el rol base 'User' y requiere verificación.
     */
    public function test_public_registration_assigns_default_user_role(): void
    {
        $response = $this->post('/register', [
            'name' => 'Usuario Publico',
            'email' => 'publico@example.com',
            'password' => 'Password123!#$',
            'password_confirmation' => 'Password123!#$',
        ]);

        $this->assertAuthenticated();
        $user = User::where('email', 'publico@example.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->hasRole('User'));
        $this->assertNull($user->email_verified_at);
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    /**
     * B1 / S1: Usuario sin verificar es bloqueado en rutas protegidas por 'verified'.
     */
    public function test_unverified_user_cannot_access_dashboard(): void
    {
        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertRedirect(route('verification.notice'));
    }

    /**
     * B3 / S3: Cuentas eliminadas (SoftDeletes) no pueden iniciar sesión.
     */
    public function test_soft_deleted_user_cannot_login(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('ValidPassword123!'),
        ]);
        $user->delete();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'ValidPassword123!',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    /**
     * B4 / S4: Los registros de auditoría son inmutables.
     */
    public function test_audit_logs_cannot_be_updated_or_deleted(): void
    {
        $activity = Activity::create([
            'log_name' => 'default',
            'description' => 'Registro de prueba inmutable',
        ]);

        $this->expectException(\LogicException::class);
        $activity->update(['description' => 'Intento de modificación']);
    }

    public function test_audit_logs_cannot_be_deleted(): void
    {
        $activity = Activity::create([
            'log_name' => 'default',
            'description' => 'Registro de prueba inmutable',
        ]);

        $this->expectException(\LogicException::class);
        $activity->delete();
    }

    /**
     * B5 / S5: PrivateVault rechaza estrictamente secuencias de Path Traversal.
     */
    public function test_private_vault_rejects_path_traversal(): void
    {
        $maliciousPaths = [
            '../etc/passwd',
            '....//secret.txt',
            'subfolder/..\\file.pdf',
            '/root/secret.key',
            "file.txt\0.pdf",
        ];

        foreach ($maliciousPaths as $path) {
            try {
                PrivateVault::validatePath($path);
                $this->fail("El path '{$path}' no fue rechazado por PrivateVault.");
            } catch (HttpException $e) {
                $this->assertEquals(404, $e->getStatusCode());
            }
        }
    }

    public function test_private_vault_subfolder_whitelist_validation(): void
    {
        $invalidSubfolders = [
            '../invoices',
            'invoices/../../secret',
            'invoices\\contracts',
            'inv@ices!',
        ];

        foreach ($invalidSubfolders as $folder) {
            try {
                PrivateVault::validateSubfolder($folder);
                $this->fail("El subfolder '{$folder}' no fue rechazado.");
            } catch (HttpException $e) {
                $this->assertEquals(404, $e->getStatusCode());
            }
        }

        // Subfolder válido debe permitirse
        $this->assertEquals('invoices/2026', PrivateVault::validateSubfolder('invoices/2026'));
    }

    /**
     * B3 / S3: El último SuperAdmin no puede ser degradado ni despojado de su rol.
     */
    public function test_last_superadmin_cannot_be_demoted(): void
    {
        $superAdmin = User::role('SuperAdmin')->first();
        $this->assertNotNull($superAdmin);

        $response = $this->actingAs($superAdmin)->patchJson(route('admin.users.update-role', $superAdmin), [
            'role' => 'User',
        ]);

        $response->assertStatus(422);
        $this->assertTrue($superAdmin->fresh()->hasRole('SuperAdmin'));
    }

    public function test_last_superadmin_cannot_be_deleted(): void
    {
        $superAdmin = User::role('SuperAdmin')->first();
        $this->assertNotNull($superAdmin);

        $response = $this->actingAs($superAdmin)->deleteJson(route('admin.users.destroy', $superAdmin));

        $response->assertStatus(422);
        $this->assertFalse($superAdmin->fresh()->trashed());
    }

    /**
     * UX/UI: La raíz '/' carga la landing page para acceder al login o registro.
     */
    public function test_root_renders_landing_page(): void
    {
        $response = $this->get('/');
        $response->assertStatus(200);
    }
}
