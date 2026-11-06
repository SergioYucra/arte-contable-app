<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Núcleo de seguridad del Master Boilerplate.
 *
 * Crea los roles y permisos universales y el usuario SuperAdmin inicial.
 * Es idempotente: puede ejecutarse varias veces sin duplicar datos
 * ni sobrescribir la contraseña de un SuperAdmin existente.
 */
class CoreSecuritySeeder extends Seeder
{
    /**
     * Permisos base del núcleo.
     *
     * P16: los nombres siguen la convención "verbo-recurso" con guion. Un permiso con el mismo nombre
     * que un método de una policy (view, delete, updateRole…) haría que el Gate::before de Spatie lo
     * conceda sin consultar la policy. Lo comprueba PermissionNamingTest.
     */
    public const PERMISSIONS = [
        'access-dashboard',
        'manage-users',
        'manage-roles',
        'view-audit-logs',
    ];

    /**
     * Matriz rol => permisos.
     * SuperAdmin recibe todos los permisos (incluido 'view-audit-logs') y bypass global vía Gate::before,
     * salvo en las habilidades sobre usuarios: esas las decide siempre UserPolicy (último SuperAdmin activo,
     * auto-desactivación), también para el SuperAdmin.
     * Los roles operativos (Admin / Manager) no tienen acceso a la bitácora de auditoría para proteger
     * la trazabilidad y el aislamiento de las cuentas SuperAdmin.
     */
    public const ROLE_MATRIX = [
        'SuperAdmin' => self::PERMISSIONS,
        'Admin' => ['access-dashboard', 'manage-users'],
        'Manager' => ['access-dashboard'],
        'User' => ['access-dashboard'],
    ];

    public function run(): void
    {
        // Limpia la caché de permisos antes de modificar roles/permisos.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        foreach (self::ROLE_MATRIX as $roleName => $permissions) {
            Role::findOrCreate($roleName, 'web')->syncPermissions($permissions);
        }

        $this->seedSuperAdmin();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Crea el SuperAdmin inicial con credenciales tomadas del .env.
     * Nunca se usan contraseñas literales en código.
     */
    private function seedSuperAdmin(): void
    {
        $email = config('app.superadmin.email');

        if (empty($email)) {
            $this->command?->warn('SUPERADMIN_EMAIL no definido en .env: se omite la creación del SuperAdmin.');

            return;
        }

        $existing = User::withTrashed()->where('email', $email)->first();

        if ($existing) {
            // Revisión de seguridad: el seeder ya no asciende a SuperAdmin una cuenta que existía antes
            // con ese correo (por ejemplo, una registrada por el formulario público con un correo de
            // ejemplo que quedó en el .env). Volver a correrlo sobre el SuperAdmin ya creado no cambia nada.
            if ($existing->hasRole('SuperAdmin') && ! $existing->trashed()) {
                $this->command?->info("SuperAdmin existente [{$email}]: rol verificado (contraseña sin cambios).");

                return;
            }

            throw new \RuntimeException(
                "Ya existe una cuenta con SUPERADMIN_EMAIL [{$email}] que no es SuperAdmin activo. "
                .'Por seguridad no se asciende de forma automática: usa otro correo o revisa esa cuenta a mano.'
            );
        }

        $password = config('app.superadmin.password');
        $generated = false;

        if (empty($password)) {
            $password = Str::password(20);
            $generated = true;
        }

        $user = User::create([
            'name' => config('app.superadmin.name', 'Super Admin'),
            'email' => $email,
            'password' => $password, // El cast 'hashed' del modelo aplica bcrypt.
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->assignRole('SuperAdmin');

        $this->command?->info("SuperAdmin creado: {$email}");

        if ($generated) {
            // Se muestra UNA sola vez en consola; no se escribe en logs.
            $this->command?->warn("Contraseña generada (guárdala y cámbiala tras el primer login): {$password}");
        }
    }
}
