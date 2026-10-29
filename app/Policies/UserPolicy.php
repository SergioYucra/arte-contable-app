<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

class UserPolicy
{
    /**
     * Determina si el usuario puede ver el listado de usuarios.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('manage-users');
    }

    /**
     * Determina si el usuario puede actualizar el rol de un usuario objetivo.
     * S3: Impide degradar al último SuperAdmin activo del sistema y previene escalada de privilegios.
     */
    public function updateRole(User $currentUser, User $targetUser, ?string $newRole = null): Response
    {
        if (! $currentUser->can('manage-roles') && ! $currentUser->can('manage-users')) {
            return Response::deny('No tienes permisos suficientes para modificar roles de usuario.');
        }

        $isSuperAdmin = $currentUser->hasRole('SuperAdmin');

        // Blindaje contra escalada de privilegios
        if ($newRole === 'SuperAdmin' && ! $isSuperAdmin) {
            return Response::deny('Operación denegada: Solo un SuperAdmin puede promover usuarios al rol de SuperAdmin.');
        }

        // Inmunidad del SuperAdmin
        if ($targetUser->hasRole('SuperAdmin') && ! $isSuperAdmin) {
            return Response::deny('Operación denegada: No tienes privilegios para modificar la cuenta de un SuperAdmin.');
        }

        // Regla de Negocio S3: Prohibir degradar al último SuperAdmin
        if ($targetUser->hasRole('SuperAdmin') && $newRole !== null && $newRole !== 'SuperAdmin') {
            $otherSuperAdmins = User::role('SuperAdmin')
                ->where('id', '!=', $targetUser->id)
                ->count();

            if ($otherSuperAdmins === 0) {
                return Response::deny('Operación denegada: No es posible degradar al único SuperAdmin activo.');
            }
        }

        return Response::allow();
    }

    /**
     * Determina si el usuario puede eliminar (SoftDelete) al usuario objetivo.
     * S3: Impide la auto-eliminación y la eliminación del último SuperAdmin activo.
     */
    public function delete(User $currentUser, User $targetUser): Response
    {
        if (! $currentUser->can('manage-users')) {
            return Response::deny('No tienes permisos suficientes para eliminar usuarios.');
        }

        if ($currentUser->id === $targetUser->id) {
            return Response::deny('Operación denegada: No puedes eliminar tu propia cuenta desde el panel de administración.');
        }

        $isSuperAdmin = $currentUser->hasRole('SuperAdmin');

        if ($targetUser->hasRole('SuperAdmin') && ! $isSuperAdmin) {
            return Response::deny('Operación denegada: No tienes privilegios para eliminar la cuenta de un SuperAdmin.');
        }

        // Regla de Negocio S3: Prohibir eliminar al último SuperAdmin
        if ($targetUser->hasRole('SuperAdmin')) {
            $otherSuperAdmins = User::role('SuperAdmin')
                ->where('id', '!=', $targetUser->id)
                ->count();

            if ($otherSuperAdmins === 0) {
                return Response::deny('Operación denegada: No es posible eliminar al único SuperAdmin del sistema.');
            }
        }

        return Response::allow();
    }

    /**
     * Determina si el usuario puede reactivar una cuenta eliminada lógicamente.
     */
    public function restore(User $currentUser, User $targetUser): Response
    {
        if (! $currentUser->can('manage-users')) {
            return Response::deny('No tienes permisos suficientes para reactivar usuarios.');
        }

        return Response::allow();
    }
}
