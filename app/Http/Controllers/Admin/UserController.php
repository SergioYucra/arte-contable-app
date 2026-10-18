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
     */
    public function index(Request $request): View
    {
        $currentUser = $request->user();
        $isSuperAdmin = $currentUser?->hasRole('SuperAdmin') ?? false;

        $users = User::with('roles:id,name')
            ->select('id', 'name', 'email', 'created_at')
            ->latest('id')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->roles->first()?->name ?? 'User',
                'created_at' => $user->created_at?->format('d/m/Y H:i') ?? '',
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

        // 3. Protección de seguridad: Evitar dejar el sistema sin ningún SuperAdmin
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
}
