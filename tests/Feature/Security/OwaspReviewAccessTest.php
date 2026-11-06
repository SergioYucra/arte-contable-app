<?php

namespace Tests\Feature\Security;

use App\Models\Activity;
use App\Models\User;
use App\Notifications\SecurityAlertNotification;
use Carbon\CarbonImmutable;
use Database\Seeders\CoreSecuritySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Revisión de seguridad OWASP Top 10:2025 (REVISION-SEGURIDAD.pdf): control de acceso y bitácora.
 * V1 (A01), P18 (A10), P8 (A09), P7 (A09, 403), P16 (A01) y el seeder.
 */
class OwaspReviewAccessTest extends TestCase
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

    private function superAdmin(): User
    {
        return User::role('SuperAdmin')->firstOrFail();
    }

    /**
     * V1: el nombre del nivel se compara exacto. En MySQL (utf8mb4_unicode_ci) 'superadmin' coincidía
     * con 'SuperAdmin' en la validación y en syncRoles(), pero no en la regla de la policy.
     */
    public function test_role_name_case_variants_are_rejected_before_reaching_the_policy(): void
    {
        $admin = $this->makeUser('Admin');
        $student = $this->makeUser('User');

        foreach (['superadmin', 'SUPERADMIN', 'superAdmin', 'admin', 'ADMIN', 'manager'] as $variant) {
            $this->actingAs($admin)
                ->patchJson(route('admin.users.update-role', $admin), ['role' => $variant])
                ->assertStatus(422)
                ->assertJsonValidationErrors('role');

            $this->actingAs($admin)
                ->patchJson(route('admin.users.update-role', $student), ['role' => $variant])
                ->assertStatus(422);
        }

        $this->assertTrue($admin->fresh()->hasRole('Admin'));
        $this->assertFalse($admin->fresh()->hasRole('SuperAdmin'));
        $this->assertTrue($student->fresh()->hasRole('User'));
    }

    public function test_exact_role_names_still_work_for_allowed_changes(): void
    {
        $admin = $this->makeUser('Admin');
        $student = $this->makeUser('User');

        $this->actingAs($admin)
            ->patchJson(route('admin.users.update-role', $student), ['role' => 'Manager'])
            ->assertOk();

        $this->assertTrue($student->fresh()->hasRole('Manager'));
    }

    /**
     * P18: si la bitácora no se puede escribir, el cambio de nivel tampoco se aplica (falla cerrada).
     */
    public function test_role_change_is_rolled_back_when_the_audit_log_cannot_be_written(): void
    {
        $superAdmin = $this->superAdmin();
        $student = $this->makeUser('User');

        $this->actingAs($superAdmin);

        Activity::creating(function (): void {
            throw new RuntimeException('Bitácora no disponible');
        });

        $this->patchJson(route('admin.users.update-role', $student), ['role' => 'Manager'])->assertStatus(500);

        $this->assertTrue($student->fresh()->hasRole('User'));
        $this->assertFalse($student->fresh()->hasRole('Manager'));
    }

    public function test_deactivation_is_rolled_back_when_the_audit_log_cannot_be_written(): void
    {
        $superAdmin = $this->superAdmin();
        $student = $this->makeUser('User');

        $this->actingAs($superAdmin);

        Activity::creating(function (): void {
            throw new RuntimeException('Bitácora no disponible');
        });

        $this->deleteJson(route('admin.users.destroy', $student))->assertStatus(500);

        $this->assertFalse($student->fresh()->trashed());
    }

    /**
     * P8: el visor de la bitácora es solo para quien tiene 'view-audit-logs' (SuperAdmin) y filtra.
     */
    public function test_superadmin_can_read_and_filter_the_audit_log(): void
    {
        $superAdmin = $this->superAdmin();

        activity('security')->log('Evento de seguridad de prueba');
        activity('users')->log('Evento de usuarios de prueba');

        $this->actingAs($superAdmin)
            ->get(route('admin.audit-logs.index'))
            ->assertOk()
            ->assertSeeText('Evento de seguridad de prueba')
            ->assertSeeText('Evento de usuarios de prueba');

        $this->actingAs($superAdmin)
            ->get(route('admin.audit-logs.index', ['log' => 'users']))
            ->assertOk()
            ->assertSeeText('Evento de usuarios de prueba')
            ->assertDontSeeText('Evento de seguridad de prueba');

        $this->actingAs($superAdmin)
            ->get(route('admin.audit-logs.index', ['q' => 'seguridad de prueba']))
            ->assertOk()
            ->assertSeeText('Evento de seguridad de prueba')
            ->assertDontSeeText('Evento de usuarios de prueba');
    }

    public function test_audit_log_content_is_escaped(): void
    {
        $superAdmin = $this->superAdmin();
        activity('security')->withProperties(['nota' => '<script>alert(1)</script>'])->log('<img src=x onerror=alert(1)>');

        $response = $this->actingAs($superAdmin)->get(route('admin.audit-logs.index'))->assertOk();

        $response->assertDontSee('<img src=x onerror=alert(1)>', false);
        $response->assertDontSee('<script>alert(1)</script>', false);
        $response->assertSee('&lt;img src=x onerror=alert(1)&gt;', false);
    }

    /**
     * P8 + P7: un Administrador no ve la bitácora, y el 403 queda registrado (una vez por ventana).
     */
    public function test_admin_cannot_read_the_audit_log_and_the_denial_is_logged_once(): void
    {
        $admin = $this->makeUser('Admin');

        $this->actingAs($admin)->get(route('admin.audit-logs.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('admin.audit-logs.index'))->assertForbidden();

        $this->assertSame(1, Activity::query()
            ->where('log_name', 'security')
            ->where('description', 'Acceso denegado (403).')
            ->where('causer_id', $admin->id)
            ->count());
    }

    /**
     * P8: subir a alguien a un nivel de administración avisa a los Superadministradores; un cambio
     * entre niveles comunes, no.
     */
    public function test_admin_level_changes_alert_superadmins(): void
    {
        Notification::fake();

        $superAdmin = $this->superAdmin();
        $student = $this->makeUser('User');
        $other = $this->makeUser('User');

        $this->actingAs($superAdmin)
            ->patchJson(route('admin.users.update-role', $other), ['role' => 'Manager'])
            ->assertOk();

        Notification::assertNothingSent();

        $this->actingAs($superAdmin)
            ->patchJson(route('admin.users.update-role', $student), ['role' => 'Admin'])
            ->assertOk();

        Notification::assertSentTo($superAdmin, SecurityAlertNotification::class, function (SecurityAlertNotification $alert) use ($student): bool {
            return str_contains($alert->summary, $student->email);
        });
    }

    /**
     * El aviso usa una vista Blade escapada: un nombre con formato Markdown o HTML no se vuelve enlace.
     */
    public function test_security_alert_email_escapes_user_controlled_text(): void
    {
        $superAdmin = $this->superAdmin();

        $html = (string) (new SecurityAlertNotification(
            'Prueba',
            'La cuenta [Haz clic aquí](https://phishing.example) <b>cambió</b>.',
            ['IP' => '203.0.113.9'],
            CarbonImmutable::now(),
        ))->toMail($superAdmin)->render();

        $this->assertStringNotContainsString('href="https://phishing.example"', $html);
        $this->assertStringNotContainsString('<b>cambió</b>', $html);
        $this->assertStringContainsString('&lt;b&gt;cambió&lt;/b&gt;', $html);
    }

    /**
     * P16: ningún permiso se llama como un método de una policy (el Gate::before de Spatie lo concedería
     * sin consultar la policy) y todos siguen la convención "verbo-recurso".
     */
    public function test_permission_names_never_collide_with_policy_methods(): void
    {
        $policyMethods = collect(File::files(app_path('Policies')))
            ->flatMap(fn ($file) => get_class_methods('App\\Policies\\'.$file->getFilenameWithoutExtension()))
            ->unique()
            ->all();

        $permissions = collect(CoreSecuritySeeder::PERMISSIONS)->merge(Permission::pluck('name'))->unique();

        foreach ($permissions as $permission) {
            $this->assertStringContainsString('-', $permission, "El permiso '{$permission}' no sigue la convención verbo-recurso.");
            $this->assertNotContains($permission, $policyMethods);
            $this->assertNotContains(Str::camel($permission), $policyMethods);
        }
    }

    /**
     * El seeder ya no asciende a SuperAdmin una cuenta que existía antes con SUPERADMIN_EMAIL.
     */
    public function test_seeder_refuses_to_promote_a_preexisting_account(): void
    {
        config(['app.superadmin.email' => 'ya-existia@example.com']);
        $preexisting = $this->makeUser('User', ['email' => 'ya-existia@example.com']);

        try {
            app(CoreSecuritySeeder::class)->run();
            $this->fail('El seeder ascendió una cuenta preexistente a SuperAdmin.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('no se asciende', $e->getMessage());
        }

        $this->assertFalse($preexisting->fresh()->hasRole('SuperAdmin'));
    }

    public function test_seeder_is_still_idempotent_for_the_existing_superadmin(): void
    {
        app(CoreSecuritySeeder::class)->run();

        $this->assertSame(1, User::role('SuperAdmin')->count());
    }
}
