<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Única fuente de las reglas de gestión de usuarios (N8).
 *
 * El controlador no repite reglas: autoriza con Gate::authorize(...) y la respuesta de la policy
 * fija el código HTTP que recibe el cliente:
 *  - deny()              → 403: falta de permisos o jerarquía insuficiente (H7).
 *  - denyWithStatus(422) → 422: reglas de negocio (último SuperAdmin activo, auto-desactivación).
 *  - denyAsNotFound()    → 404: anti-enumeración, un SuperAdmin es invisible para niveles inferiores.
 *
 * Gate::before (AppServiceProvider) no aplica el bypass de SuperAdmin a esta policy.
 */
class UserPolicy
{
    private const ADMIN_HIERARCHY_MESSAGE = 'Operación denegada: Solo un Superadministrador puede modificar, desactivar o reactivar a otro Administrador.';

    /**
     * Determina si el usuario puede ver el listado de usuarios.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('manage-users');
    }

    /**
     * Determina si el operador puede siquiera saber que el usuario objetivo existe.
     * Un SuperAdmin es invisible para quien no lo es: 404 idéntico a un registro inexistente.
     */
    public function view(User $currentUser, User $targetUser): Response
    {
        if (! $currentUser->can('manage-users')) {
            return Response::deny('No tienes permisos suficientes para gestionar usuarios.');
        }

        if ($targetUser->hasRole('SuperAdmin') && ! $currentUser->hasRole('SuperAdmin')) {
            return Response::denyAsNotFound();
        }

        return Response::allow();
    }

    /**
     * Cambio de nivel de acceso.
     * S3: impide la escalada a SuperAdmin y degradar al último SuperAdmin activo.
     * H7: solo un SuperAdmin modifica a otro Administrador.
     */
    public function updateRole(User $currentUser, User $targetUser, ?string $newRole = null): Response
    {
        $visible = $this->view($currentUser, $targetUser);

        if ($visible->denied()) {
            return $visible;
        }

        $isSuperAdmin = $currentUser->hasRole('SuperAdmin');

        if ($newRole === 'SuperAdmin' && ! $isSuperAdmin) {
            return Response::deny('Operación denegada: Solo un Superadministrador puede promover cuentas al nivel de Superadministrador.');
        }

        if (! $isSuperAdmin && $this->isAnotherAdmin($currentUser, $targetUser)) {
            return Response::deny(self::ADMIN_HIERARCHY_MESSAGE);
        }

        if ($newRole !== null && $newRole !== 'SuperAdmin' && $this->isLastActiveSuperAdmin($targetUser)) {
            return Response::denyWithStatus(422, 'Operación denegada: No es posible quitar el nivel de acceso al único Superadministrador activo.');
        }

        return Response::allow();
    }

    /**
     * Desactivación (SoftDelete).
     * S3: impide la auto-desactivación y desactivar al último SuperAdmin activo.
     * H7: solo un SuperAdmin desactiva a otro Administrador.
     */
    public function delete(User $currentUser, User $targetUser): Response
    {
        $visible = $this->view($currentUser, $targetUser);

        if ($visible->denied()) {
            return $visible;
        }

        if ($currentUser->id === $targetUser->id) {
            return Response::denyWithStatus(422, 'Operación denegada: No puedes desactivar tu propia cuenta desde el panel de administración.');
        }

        if (! $currentUser->hasRole('SuperAdmin') && $this->isAnotherAdmin($currentUser, $targetUser)) {
            return Response::deny(self::ADMIN_HIERARCHY_MESSAGE);
        }

        if ($this->isLastActiveSuperAdmin($targetUser)) {
            return Response::denyWithStatus(422, 'Operación denegada: No es posible desactivar al único Superadministrador del sistema.');
        }

        return Response::allow();
    }

    /**
     * Reactivación de una cuenta desactivada. H7: solo un SuperAdmin reactiva a otro Administrador.
     */
    public function restore(User $currentUser, User $targetUser): Response
    {
        $visible = $this->view($currentUser, $targetUser);

        if ($visible->denied()) {
            return $visible;
        }

        if (! $currentUser->hasRole('SuperAdmin') && $this->isAnotherAdmin($currentUser, $targetUser)) {
            return Response::deny(self::ADMIN_HIERARCHY_MESSAGE);
        }

        return Response::allow();
    }

    /**
     * Reenvío del correo de verificación desde el panel.
     */
    public function resendVerification(User $currentUser, User $targetUser): Response
    {
        return $this->view($currentUser, $targetUser);
    }

    /**
     * H7: "otro Administrador" excluye al propio operador, que sí puede cambiar su nivel.
     */
    private function isAnotherAdmin(User $currentUser, User $targetUser): bool
    {
        return $targetUser->id !== $currentUser->id && $targetUser->hasRole('Admin');
    }

    /**
     * Solo cuenta SuperAdmins activos: User::role() respeta el scope global de SoftDeletes.
     */
    private function isLastActiveSuperAdmin(User $targetUser): bool
    {
        return $targetUser->hasRole('SuperAdmin')
            && User::role('SuperAdmin')->where('id', '!=', $targetUser->id)->doesntExist();
    }
}
