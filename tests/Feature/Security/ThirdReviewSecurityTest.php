<?php

namespace Tests\Feature\Security;

use App\Http\Controllers\Admin\UserController;
use App\Models\Activity;
use App\Models\User;
use Database\Seeders\CoreSecuritySeeder;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Hallazgos de la tercera revisión (REVISION-3.md): S4, N8, H7, N9 y UX-09.
 */
class ThirdReviewSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreSecuritySeeder::class);
    }

    private function makeUser(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user;
    }

    /**
     * S4: la vía que el modelo no ve (DB::table / SQL directo) la bloquea la base de datos.
     * Se inserta una fila primero porque un trigger de fila no se dispara sobre una tabla vacía.
     */
    public function test_activity_log_rejects_raw_deletes_and_updates_at_database_level(): void
    {
        $table = config('activitylog.table_name');
        $connection = DB::connection(config('activitylog.database_connection'));

        Activity::create(['log_name' => 'db-level', 'description' => 'Fila protegida']);

        $attempts = [
            'delete' => fn () => $connection->table($table)->delete(),
            'update' => fn () => $connection->table($table)->update(['description' => 'Alterada']),
            'truncate' => fn () => $connection->table($table)->truncate(),
        ];

        foreach ($attempts as $operation => $attempt) {
            try {
                $attempt();
                $this->fail("La base de datos permitió '{$operation}' sobre la bitácora de auditoría.");
            } catch (QueryException $e) {
                $this->assertStringContainsString('inmutables', $e->getMessage());
            }
        }

        // RAISE(ABORT) deshace solo la sentencia: la fila sigue intacta y la transacción del test viva
        $this->assertDatabaseHas($table, ['log_name' => 'db-level', 'description' => 'Fila protegida']);

        // La bitácora sigue aceptando escrituras nuevas
        activity('db-level')->log('Registro posterior');
        $this->assertDatabaseCount($table, Activity::query()->count());
        $this->assertDatabaseHas($table, ['description' => 'Registro posterior']);
    }

    /**
     * S4: los triggers solo se omiten de forma explícita (ACTIVITY_LOG_DB_TRIGGERS=false), para hostings
     * que no permiten crearlos. Por defecto la migración los instala.
     */
    public function test_database_triggers_are_only_skipped_when_explicitly_disabled(): void
    {
        $migration = require database_path('migrations/2026_11_05_000001_add_immutability_triggers_to_activity_log_table.php');
        $table = config('activitylog.table_name');
        $countTriggers = fn () => DB::table('sqlite_master')
            ->where('type', 'trigger')
            ->where('tbl_name', $table)
            ->count();

        $this->assertSame(2, $countTriggers());

        $migration->down();
        $this->assertSame(0, $countTriggers());

        config(['activitylog.database_triggers' => false]);
        $migration->up();
        $this->assertSame(0, $countTriggers());

        config(['activitylog.database_triggers' => true]);
        $migration->up();
        $this->assertSame(2, $countTriggers());
    }

    /**
     * N8: las reglas se aplican a través del Gate real. El bypass global de SuperAdmin no se salta
     * UserPolicy (último SuperAdmin activo), pero sigue vigente para el resto de habilidades.
     */
    public function test_user_policy_is_enforced_through_gate_even_for_superadmin(): void
    {
        $superAdmin = User::role('SuperAdmin')->firstOrFail();

        $demote = Gate::forUser($superAdmin)->inspect('updateRole', [$superAdmin, 'Admin']);
        $this->assertTrue($demote->denied());
        $this->assertSame(422, $demote->status());

        $deactivateSelf = Gate::forUser($superAdmin)->inspect('delete', $superAdmin);
        $this->assertTrue($deactivateSelf->denied());
        $this->assertSame(422, $deactivateSelf->status());

        // Fuera de la gestión de usuarios, el SuperAdmin conserva el acceso implícito
        $this->assertTrue($superAdmin->can('habilidad-futura-no-sembrada'));

        // ...también en habilidades que reciben un usuario pero que UserPolicy no define
        $student = $this->makeUser('User');
        $this->assertTrue($superAdmin->can('habilidad-futura-no-sembrada', $student));
        $this->assertTrue($superAdmin->can('habilidad-futura-no-sembrada', User::class));
        $this->assertFalse($this->makeUser('Admin')->can('habilidad-futura-no-sembrada', $student));
    }

    /**
     * N8 (anti-enumeración): sondear un SuperAdmin responde exactamente igual que un ID inexistente,
     * incluso con un payload inválido (la visibilidad se decide antes que la validación).
     */
    public function test_probe_against_superadmin_is_indistinguishable_from_missing_user(): void
    {
        $superAdmin = User::role('SuperAdmin')->firstOrFail();
        $admin = $this->makeUser('Admin');
        $missingId = User::withTrashed()->max('id') + 1000;

        $probeSuperAdmin = $this->actingAs($admin)
            ->patchJson(route('admin.users.update-role', $superAdmin->id), ['role' => 'User']);
        $probeMissing = $this->actingAs($admin)
            ->patchJson(route('admin.users.update-role', $missingId), ['role' => 'User']);
        $probeInvalidPayload = $this->actingAs($admin)
            ->patchJson(route('admin.users.update-role', $superAdmin->id), []);

        $probeSuperAdmin->assertNotFound();
        $probeMissing->assertNotFound();
        $probeInvalidPayload->assertNotFound();

        $this->assertSame($probeMissing->json(), $probeSuperAdmin->json());
        $this->assertSame($probeMissing->json(), $probeInvalidPayload->json());
        $this->assertStringNotContainsString('App\\Models', (string) $probeMissing->getContent());
    }

    /**
     * H7: un Administrador no puede degradar, desactivar ni reactivar a otro Administrador.
     * Sí puede gestionar Estudiantes, y el SuperAdmin sí puede gestionar Administradores.
     */
    public function test_admin_cannot_modify_or_deactivate_another_admin(): void
    {
        $superAdmin = User::role('SuperAdmin')->firstOrFail();
        $admin = $this->makeUser('Admin');
        $otherAdmin = $this->makeUser('Admin');
        $student = $this->makeUser('User');

        $this->actingAs($admin)
            ->patchJson(route('admin.users.update-role', $otherAdmin), ['role' => 'User'])
            ->assertForbidden();
        $this->assertTrue($otherAdmin->fresh()->hasRole('Admin'));

        $this->actingAs($admin)
            ->deleteJson(route('admin.users.destroy', $otherAdmin))
            ->assertForbidden();
        $this->assertFalse($otherAdmin->fresh()->trashed());

        // Con Estudiantes no cambia nada
        $this->actingAs($admin)
            ->patchJson(route('admin.users.update-role', $student), ['role' => 'Manager'])
            ->assertOk();

        // El SuperAdmin sí gestiona Administradores
        $this->actingAs($superAdmin)
            ->deleteJson(route('admin.users.destroy', $otherAdmin))
            ->assertOk();
        $this->assertTrue($otherAdmin->fresh()->trashed());

        // Reactivar a otro Administrador también queda reservado al SuperAdmin
        $this->actingAs($admin)
            ->postJson(route('admin.users.restore', $otherAdmin->id))
            ->assertForbidden();
        $this->assertTrue(User::withTrashed()->findOrFail($otherAdmin->id)->trashed());

        $this->actingAs($superAdmin)
            ->postJson(route('admin.users.restore', $otherAdmin->id))
            ->assertOk();
        $this->assertFalse($otherAdmin->fresh()->trashed());
    }

    /**
     * H7 simétrico: quien no puede quitarle el nivel a otro Administrador tampoco puede crearlo.
     * Solo el SuperAdmin asigna el nivel Administrador, y el modal no se lo ofrece a nadie más.
     */
    public function test_only_superadmin_can_assign_admin_level(): void
    {
        $superAdmin = User::role('SuperAdmin')->firstOrFail();
        $admin = $this->makeUser('Admin');
        $student = $this->makeUser('User');

        $this->actingAs($admin)
            ->patchJson(route('admin.users.update-role', $student), ['role' => 'Admin'])
            ->assertForbidden();
        $this->assertTrue($student->fresh()->hasRole('User'));

        $this->actingAs($superAdmin)
            ->patchJson(route('admin.users.update-role', $student), ['role' => 'Admin'])
            ->assertOk();
        $this->assertTrue($student->fresh()->hasRole('Admin'));

        $adminView = $this->actingAs($admin)->get(route('admin.users.index'))->assertOk();
        $this->assertEqualsCanonicalizing(['Manager', 'User'], $adminView->viewData('assignableRoles'));
        $this->assertContains('Admin', $adminView->viewData('availableRoles'));

        $superAdminView = $this->actingAs($superAdmin)->get(route('admin.users.index'))->assertOk();
        $this->assertEqualsCanonicalizing(
            ['SuperAdmin', 'Admin', 'Manager', 'User'],
            $superAdminView->viewData('assignableRoles')
        );
    }

    /**
     * N9: "Reenviar verificación" admite 3 envíos por hora por usuario destino; el 4.º responde 429,
     * no envía correo y queda en la bitácora de seguridad una sola vez por ventana, por más que se
     * insista. El límite no afecta a otros usuarios.
     */
    public function test_resend_verification_is_rate_limited_per_target_user(): void
    {
        Notification::fake();

        $admin = $this->makeUser('Admin');
        $pending = User::factory()->unverified()->create(['email' => 'pendiente@test.com']);
        $otherPending = User::factory()->unverified()->create(['email' => 'otro-pendiente@test.com']);

        for ($i = 1; $i <= UserController::RESEND_VERIFICATION_MAX_ATTEMPTS; $i++) {
            $this->actingAs($admin)
                ->postJson(route('admin.users.resend-verification', $pending))
                ->assertOk();
        }

        // Insistir sigue bloqueado, pero la bitácora (inmutable) recibe una sola fila por ventana
        for ($i = 1; $i <= 5; $i++) {
            $blocked = $this->actingAs($admin)
                ->postJson(route('admin.users.resend-verification', $pending));

            $blocked->assertStatus(429);
            $this->assertStringContainsString('límite', (string) $blocked->json('message'));
        }

        Notification::assertSentToTimes($pending, VerifyEmail::class, UserController::RESEND_VERIFICATION_MAX_ATTEMPTS);

        $blockedRows = fn () => Activity::query()
            ->where('log_name', 'security')
            ->where('causer_id', $admin->id)
            ->where('subject_id', $pending->id)
            ->count();

        $this->assertSame(1, $blockedRows());

        // En la ventana siguiente, un nuevo bloqueo vuelve a quedar registrado (una vez)
        $this->travel(UserController::RESEND_VERIFICATION_DECAY_SECONDS + 60)->seconds();

        for ($i = 1; $i <= UserController::RESEND_VERIFICATION_MAX_ATTEMPTS + 2; $i++) {
            $this->actingAs($admin)->postJson(route('admin.users.resend-verification', $pending));
        }

        $this->assertSame(2, $blockedRows());

        // La clave es por usuario destino: otra cuenta pendiente no está bloqueada
        $this->actingAs($admin)
            ->postJson(route('admin.users.resend-verification', $otherPending))
            ->assertOk();
    }

    /**
     * UX-09 y H7 en la vista: la fila propia viene marcada y `can_edit` sale de la policy,
     * así la interfaz no ofrece acciones que el servidor rechazaría.
     */
    public function test_users_index_marks_own_row_and_derives_can_edit_from_policy(): void
    {
        $admin = $this->makeUser('Admin');
        $otherAdmin = $this->makeUser('Admin');
        $student = $this->makeUser('User');

        $users = collect(
            $this->actingAs($admin)->get(route('admin.users.index'))->assertOk()->viewData('users')
        )->keyBy('id');

        $this->assertTrue($users[$admin->id]['is_self']);
        $this->assertFalse($users[$student->id]['is_self']);

        $this->assertTrue($users[$admin->id]['can_edit']);
        $this->assertTrue($users[$student->id]['can_edit']);
        $this->assertFalse($users[$otherAdmin->id]['can_edit']);
    }
}
