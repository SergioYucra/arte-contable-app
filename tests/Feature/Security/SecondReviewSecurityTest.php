<?php

namespace Tests\Feature\Security;

use App\Models\Activity;
use App\Models\User;
use Database\Seeders\CoreSecuritySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SecondReviewSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreSecuritySeeder::class);
    }

    /**
     * N1: Prevención de enumeración de usuarios en login con cuentas desactivadas.
     * Con clave errónea debe responder con error genérico ('auth.failed'),
     * y con clave correcta debe responder que la cuenta está desactivada.
     */
    public function test_login_does_not_enumerate_deactivated_users_with_wrong_password(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('RealPassword123!'),
        ]);
        $user->delete(); // SoftDelete

        // Intento con contraseña INCORRECTA
        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'WrongPassword999!',
        ]);

        $response->assertSessionHasErrors(['email' => trans('auth.failed')]);
        $this->assertGuest();

        // Intento con contraseña CORRECTA: solo aquí se debe informar que la cuenta está desactivada
        $responseValidPass = $this->post('/login', [
            'email' => $user->email,
            'password' => 'RealPassword123!',
        ]);

        $responseValidPass->assertSessionHasErrors([
            'email' => 'Esta cuenta ha sido desactivada. Por favor, comunícate con el administrador del sistema.',
        ]);
        $this->assertGuest();
    }

    /**
     * N2 & UX-05: Un administrador puede desactivar (destroy) y reactivar (restore) usuarios.
     * Ambas operaciones quedan registradas en la bitácora de auditoría.
     */
    public function test_admin_can_deactivate_and_restore_user_with_audit_log(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        $targetUser = User::factory()->create([
            'name' => 'Target Student',
            'email' => 'student@test.com',
        ]);
        $targetUser->assignRole('User');

        // 1. Desactivación (SoftDelete)
        $destroyResponse = $this->actingAs($admin)->deleteJson(route('admin.users.destroy', $targetUser));
        $destroyResponse->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertTrue($targetUser->fresh()->trashed());

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'users',
            'description' => "Usuario '{$targetUser->name}' ({$targetUser->email}) desactivado (SoftDelete)",
            'causer_id' => $admin->id,
        ]);

        // 2. Reactivación (Restore)
        $restoreResponse = $this->actingAs($admin)->postJson(route('admin.users.restore', $targetUser->id));
        $restoreResponse->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertFalse($targetUser->fresh()->trashed());

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'users',
            'description' => "Usuario '{$targetUser->name}' ({$targetUser->email}) reactivado exitosamente",
            'causer_id' => $admin->id,
        ]);
    }

    /**
     * N3: Rate limiting de cambio de correo (máximo 3 por día) y registro en bitácora.
     */
    public function test_email_change_is_rate_limited_to_three_attempts_per_day(): void
    {
        $user = User::factory()->create([
            'email' => 'original@test.com',
        ]);

        // Realizar 3 cambios permitidos
        for ($i = 1; $i <= 3; $i++) {
            $response = $this->actingAs($user)->patch(route('profile.update'), [
                'name' => $user->name,
                'email' => "change{$i}@test.com",
            ]);
            $response->assertSessionHasNoErrors();
            $user->refresh();
            $this->assertEquals("change{$i}@test.com", $user->email);
        }

        // El 4to intento debe ser bloqueado por Rate Limiting
        $blockedResponse = $this->actingAs($user)->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => 'change4@test.com',
        ]);

        $blockedResponse->assertSessionHasErrors('email');
        $this->assertEquals("change3@test.com", $user->fresh()->email);

        // Se verifica que el intento bloqueado fue registrado en la bitácora de seguridad
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'security',
            'causer_id' => $user->id,
        ]);
    }

    /**
     * N6: Reenvío de correo de verificación desde el panel de administración.
     */
    public function test_admin_can_resend_verification_email_for_unverified_user(): void
    {
        Notification::fake();

        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        $unverifiedUser = User::factory()->unverified()->create([
            'email' => 'unverified@test.com',
        ]);

        $response = $this->actingAs($admin)->postJson(route('admin.users.resend-verification', $unverifiedUser));
        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'users',
            'description' => "Enlace de verificación de correo reenviado a '{$unverifiedUser->email}'",
            'causer_id' => $admin->id,
        ]);
    }

    /**
     * S3: UserPolicy bloquea la degradación o eliminación del último SuperAdmin.
     */
    public function test_user_policy_strictly_protects_last_superadmin(): void
    {
        $superAdmin = User::role('SuperAdmin')->first();
        $this->assertNotNull($superAdmin);

        $policy = new \App\Policies\UserPolicy();

        // 1. No se puede autoeliminar
        $deleteSelf = $policy->delete($superAdmin, $superAdmin);
        $this->assertFalse($deleteSelf->allowed());

        // 2. No se puede degradar al último SuperAdmin
        $demote = $policy->updateRole($superAdmin, $superAdmin, 'Admin');
        $this->assertFalse($demote->allowed());
    }

    /**
     * S4: Modelo Activity bloquea eliminación masiva por query builder o truncado.
     */
    public function test_activity_model_blocks_mass_deletion_and_truncation(): void
    {
        Activity::create([
            'log_name' => 'test',
            'description' => 'Test entry',
        ]);

        // Bloqueo de eliminación masiva vía Builder
        try {
            Activity::where('log_name', 'test')->delete();
            $this->fail('Activity mass delete should have thrown a LogicException');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('inmutables', $e->getMessage());
        }

        // Bloqueo de truncado masivo
        try {
            Activity::truncate();
            $this->fail('Activity truncate should have thrown a LogicException');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('inmutables', $e->getMessage());
        }
    }
}
