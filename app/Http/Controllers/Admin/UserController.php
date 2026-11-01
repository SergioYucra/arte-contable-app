<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    /**
     * Etiquetas legibles en español para niveles de acceso (cero nombres técnicos en UI).
     *
     * @var array<string, string>
     */
    public const ROLE_LABELS = [
        'SuperAdmin' => 'Superadministrador',
        'Admin' => 'Administrador',
        'Manager' => 'Coordinador',
        'User' => 'Estudiante',
    ];

    public static function roleLabel(string $role): string
    {
        return self::ROLE_LABELS[$role] ?? $role;
    }

    /**
     * Impide la enumeración por ID de cuentas SuperAdmin ante operadores de menor jerarquía,
     * lanzando exactamente la misma excepción 404 que un registro inexistente.
     */
    private function ensureTargetIsVisible(User $targetUser, bool $isSuperAdmin): void
    {
        if (! $isSuperAdmin && $targetUser->hasRole('SuperAdmin')) {
            throw (new ModelNotFoundException)->setModel(User::class, [$targetUser->id]);
        }
    }

    /**
     * Muestra el panel interactivo de administración de usuarios y roles.
     * Incluye usuarios activos y desactivados (SoftDeletes) con estado de verificación.
     * Las cuentas SuperAdmin permanecen completamente invisibles para roles inferiores.
     */
    public function index(Request $request): View
    {
        $currentUser = $request->user();
        $isSuperAdmin = $currentUser?->hasRole('SuperAdmin') ?? false;

        $usersQuery = User::withTrashed()
            ->with('roles:id,name')
            ->select('id', 'name', 'email', 'created_at', 'deleted_at', 'email_verified_at');

        if (! $isSuperAdmin) {
            $usersQuery->whereDoesntHave('roles', function ($query): void {
                $query->where('name', 'SuperAdmin');
            });
        }

        $users = $usersQuery
            ->latest('id')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->roles->first()?->name ?? 'User',
                'created_at' => $user->created_at?->format('d/m/Y H:i') ?? '',
                'deleted_at' => $user->deleted_at?->toISOString(),
                'is_active' => is_null($user->deleted_at),
                'email_verified_at' => $user->email_verified_at?->toISOString(),
                'is_verified' => ! is_null($user->email_verified_at),
                'can_edit' => $isSuperAdmin || ! $user->hasRole('SuperAdmin'),
            ]);

        // Si el usuario autenticado NO es SuperAdmin, no debe ver ni poder asignar 'SuperAdmin'
        $availableRolesQuery = Role::query();
        if (! $isSuperAdmin) {
            $availableRolesQuery->where('name', '!=', 'SuperAdmin');
        }
        $availableRoles = $availableRolesQuery->pluck('name')->values()->all();

        return view('admin.users.index', [
            'users' => $users,
            'availableRoles' => $availableRoles,
            'isSuperAdmin' => $isSuperAdmin,
        ]);
    }

    /**
     * Actualiza el rol de un usuario de forma asíncrona (AJAX / Axios).
     */
    public function updateRole(Request $request, User $user): JsonResponse
    {
        $currentUser = $request->user();
        $isSuperAdmin = $currentUser?->hasRole('SuperAdmin') ?? false;

        // Verificar autorización granular
        if (! $currentUser->can('manage-roles') && ! $currentUser->can('manage-users')) {
            return response()->json([
                'message' => 'No tienes permisos suficientes para modificar niveles de acceso.',
            ], 403);
        }

        // Ocultar existencia de cuentas SuperAdmin (404 idéntico a inexistente)
        $this->ensureTargetIsVisible($user, $isSuperAdmin);

        $validated = $request->validate([
            'role' => ['required', 'string', 'exists:roles,name'],
        ]);

        $newRole = $validated['role'];
        $newRoleLabel = self::roleLabel($newRole);

        // 1. Blindaje contra Escalada de Privilegios: Solo SuperAdmin puede asignar el rol SuperAdmin
        if ($newRole === 'SuperAdmin' && ! $isSuperAdmin) {
            return response()->json([
                'message' => 'Operación denegada: Solo un Superadministrador puede promover cuentas al nivel de Superadministrador.',
            ], 403);
        }

        // 3. Regla de Negocio S3: Evitar dejar el sistema sin ningún SuperAdmin
        if ($user->hasRole('SuperAdmin') && $newRole !== 'SuperAdmin') {
            $otherSuperAdminsCount = User::role('SuperAdmin')
                ->where('id', '!=', $user->id)
                ->count();

            if ($otherSuperAdminsCount === 0) {
                return response()->json([
                    'message' => 'Operación denegada: No es posible quitar el nivel de acceso al único Superadministrador activo.',
                ], 422);
            }
        }

        $previousRole = $user->roles->first()?->name ?? 'Ninguno';
        $user->syncRoles([$newRole]);

        activity('users')
            ->causedBy($request->user())
            ->performedOn($user)
            ->withProperties(['old_role' => $previousRole, 'new_role' => $newRole])
            ->log("Rol de usuario modificado a '{$newRole}'");

        return response()->json([
            'success' => true,
            'message' => "El nivel de acceso de {$user->name} se actualizó a {$newRoleLabel}.",
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $newRole,
            ],
        ]);
    }

    /**
     * Desactiva (SoftDelete) un usuario del sistema, revocando todas sus sesiones activas
     * y protegiendo al último SuperAdmin.
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        $currentUser = $request->user();
        $isSuperAdmin = $currentUser?->hasRole('SuperAdmin') ?? false;

        if (! $currentUser->can('manage-users')) {
            return response()->json([
                'message' => 'No tienes permisos suficientes para desactivar cuentas de usuario.',
            ], 403);
        }

        // Ocultar existencia de cuentas SuperAdmin (404 idéntico a inexistente)
        $this->ensureTargetIsVisible($user, $isSuperAdmin);

        if ($user->id === $currentUser->id) {
            return response()->json([
                'message' => 'Operación denegada: No puedes desactivar tu propia cuenta desde el panel de administración.',
            ], 422);
        }

        if ($user->hasRole('SuperAdmin')) {
            $otherSuperAdminsCount = User::role('SuperAdmin')
                ->where('id', '!=', $user->id)
                ->count();

            if ($otherSuperAdminsCount === 0) {
                return response()->json([
                    'message' => 'Operación denegada: No es posible desactivar al único Superadministrador del sistema.',
                ], 422);
            }
        }

        $userName = $user->name;
        $userEmail = $user->email;

        DB::transaction(function () use ($user): void {
            // 1. Revocar token "Recordarme" sin disparar logs intermedios
            $user->forceFill(['remember_token' => null])->saveQuietly();

            // 2. Invalidar inmediatamente cualquier sesión activa en base de datos (sin consultas a information_schema)
            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->delete();

            // 3. Desactivación lógica (SoftDelete). Los módulos de negocio reaccionan vía eventos de modelo si lo requieren.
            $user->delete();
        });

        activity('users')
            ->causedBy($currentUser)
            ->performedOn($user)
            ->withProperties(['name' => $userName, 'email' => $userEmail])
            ->log("Usuario '{$userName}' ({$userEmail}) desactivado (SoftDelete)");

        return response()->json([
            'success' => true,
            'message' => "La cuenta de {$userName} fue desactivada y sus sesiones activas fueron cerradas.",
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->roles->first()?->name ?? 'User',
                'created_at' => $user->created_at?->format('d/m/Y H:i') ?? '',
                'deleted_at' => now()->toISOString(),
                'is_active' => false,
                'email_verified_at' => $user->email_verified_at?->toISOString(),
                'is_verified' => ! is_null($user->email_verified_at),
                'can_edit' => $isSuperAdmin || ! $user->hasRole('SuperAdmin'),
            ],
        ]);
    }

    /**
     * Reactiva un usuario previamente desactivado (SoftDeletes).
     * Registra la reactivación en la bitácora de auditoría.
     */
    public function restore(Request $request, int|string $id): JsonResponse
    {
        $currentUser = $request->user();
        $isSuperAdmin = $currentUser?->hasRole('SuperAdmin') ?? false;

        if (! $currentUser->can('manage-users')) {
            return response()->json([
                'message' => 'No tienes permisos suficientes para reactivar usuarios.',
            ], 403);
        }

        $user = User::onlyTrashed()->with('roles:id,name')->findOrFail($id);

        $this->ensureTargetIsVisible($user, $isSuperAdmin);

        $user->restore();

        activity('users')
            ->causedBy($currentUser)
            ->performedOn($user)
            ->withProperties(['name' => $user->name, 'email' => $user->email])
            ->log("Usuario '{$user->name}' ({$user->email}) reactivado exitosamente");

        return response()->json([
            'success' => true,
            'message' => "La cuenta de {$user->name} fue reactivada correctamente.",
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->roles->first()?->name ?? 'User',
                'created_at' => $user->created_at?->format('d/m/Y H:i') ?? '',
                'deleted_at' => null,
                'is_active' => true,
                'email_verified_at' => $user->email_verified_at?->toISOString(),
                'is_verified' => ! is_null($user->email_verified_at),
                'can_edit' => $isSuperAdmin || ! $user->hasRole('SuperAdmin'),
            ],
        ]);
    }

    /**
     * Reenvía la notificación de verificación de correo electrónico para un usuario no verificado.
     * Registra el reenvío en la bitácora de auditoría.
     */
    public function resendVerification(Request $request, User $user): JsonResponse
    {
        $currentUser = $request->user();
        $isSuperAdmin = $currentUser?->hasRole('SuperAdmin') ?? false;

        if (! $currentUser->can('manage-users')) {
            return response()->json([
                'message' => 'No tienes permisos suficientes para reenviar correos de verificación.',
            ], 403);
        }

        $this->ensureTargetIsVisible($user, $isSuperAdmin);

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'message' => "La cuenta de {$user->name} ya se encuentra verificada.",
            ], 422);
        }

        $user->sendEmailVerificationNotification();

        activity('users')
            ->causedBy($currentUser)
            ->performedOn($user)
            ->withProperties(['name' => $user->name, 'email' => $user->email])
            ->log("Enlace de verificación de correo reenviado a '{$user->email}'");

        return response()->json([
            'success' => true,
            'message' => "Se ha reenviado el enlace de verificación a {$user->email}.",
        ]);
    }
}
