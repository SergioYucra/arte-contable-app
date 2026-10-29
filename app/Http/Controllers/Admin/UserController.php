<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    /**
     * Muestra el panel interactivo de administración de usuarios y roles.
     * Incluye usuarios activos y desactivados (SoftDeletes) con estado de verificación.
     */
    public function index(Request $request): View
    {
        $currentUser = $request->user();
        $isSuperAdmin = $currentUser?->hasRole('SuperAdmin') ?? false;

        $users = User::withTrashed()
            ->with('roles:id,name')
            ->select('id', 'name', 'email', 'created_at', 'deleted_at', 'email_verified_at')
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
                'message' => 'No tienes permisos suficientes para modificar roles de usuario.',
            ], 403);
        }

        $validated = $request->validate([
            'role' => ['required', 'string', 'exists:roles,name'],
        ]);

        $newRole = $validated['role'];

        // 1. Blindaje contra Escalada de Privilegios: Solo SuperAdmin puede asignar el rol SuperAdmin
        if ($newRole === 'SuperAdmin' && ! $isSuperAdmin) {
            return response()->json([
                'message' => 'Operación denegada: Solo un SuperAdmin puede promover usuarios al rol de SuperAdmin.',
            ], 403);
        }

        // 2. Inmunidad del SuperAdmin: Un Admin común no puede modificar el rol de un SuperAdmin
        if ($user->hasRole('SuperAdmin') && ! $isSuperAdmin) {
            return response()->json([
                'message' => 'Operación denegada: No tienes privilegios para modificar la cuenta de un SuperAdmin.',
            ], 403);
        }

        // 3. Regla de Negocio S3: Evitar dejar el sistema sin ningún SuperAdmin
        if ($user->hasRole('SuperAdmin') && $newRole !== 'SuperAdmin') {
            $otherSuperAdminsCount = User::role('SuperAdmin')
                ->where('id', '!=', $user->id)
                ->count();

            if ($otherSuperAdminsCount === 0) {
                return response()->json([
                    'message' => 'Operación denegada: No es posible quitar el rol al único SuperAdmin activo.',
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
            'message' => "El rol de {$user->name} fue actualizado exitosamente a {$newRole}.",
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $newRole,
            ],
        ]);
    }

    /**
     * Desactiva (SoftDelete) un usuario del sistema protegiendo al último SuperAdmin.
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        $currentUser = $request->user();
        $isSuperAdmin = $currentUser?->hasRole('SuperAdmin') ?? false;

        if (! $currentUser->can('manage-users')) {
            return response()->json([
                'message' => 'No tienes permisos suficientes para eliminar usuarios.',
            ], 403);
        }

        if ($user->id === $currentUser->id) {
            return response()->json([
                'message' => 'Operación denegada: No puedes eliminar tu propia cuenta desde el panel de administración.',
            ], 422);
        }

        if ($user->hasRole('SuperAdmin') && ! $isSuperAdmin) {
            return response()->json([
                'message' => 'Operación denegada: No tienes privilegios para eliminar la cuenta de un SuperAdmin.',
            ], 403);
        }

        if ($user->hasRole('SuperAdmin')) {
            $otherSuperAdminsCount = User::role('SuperAdmin')
                ->where('id', '!=', $user->id)
                ->count();

            if ($otherSuperAdminsCount === 0) {
                return response()->json([
                    'message' => 'Operación denegada: No es posible eliminar al único SuperAdmin del sistema.',
                ], 422);
            }
        }

        $userName = $user->name;
        $userEmail = $user->email;

        $user->delete();

        activity('users')
            ->causedBy($currentUser)
            ->performedOn($user)
            ->withProperties(['name' => $userName, 'email' => $userEmail])
            ->log("Usuario '{$userName}' ({$userEmail}) desactivado (SoftDelete)");

        return response()->json([
            'success' => true,
            'message' => "El usuario {$userName} fue desactivado correctamente.",
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

        $user->restore();

        activity('users')
            ->causedBy($currentUser)
            ->performedOn($user)
            ->withProperties(['name' => $user->name, 'email' => $user->email])
            ->log("Usuario '{$user->name}' ({$user->email}) reactivado exitosamente");

        return response()->json([
            'success' => true,
            'message' => "El usuario {$user->name} fue reactivado correctamente.",
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

        if (! $currentUser->can('manage-users')) {
            return response()->json([
                'message' => 'No tienes permisos suficientes para reenviar correos de verificación.',
            ], 403);
        }

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
