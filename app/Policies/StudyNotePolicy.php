<?php

namespace App\Policies;

use App\Models\BookEdition;
use App\Models\StudyNote;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Candado del Cuaderno de Estudio.
 *
 * Regla: solo miembros con días vigentes (status=active, expires_at > now) o staff
 * (SuperAdmin/Admin) pueden ver/crear/editar/eliminar apuntes. Al vencer la membresía
 * las notas NO se borran: simplemente se deniega el acceso hasta renovar o canjear.
 *
 * Importante: Gate::before concede todo a SuperAdmin, por lo que la propiedad de la nota
 * se valida TAMBIÉN en StudyNoteController (defensa en profundidad, privacidad estricta).
 */
class StudyNotePolicy
{
    public const DENY_CODE_SUBSCRIPTION = 'subscription_required';

    public const DENY_CODE_OWNERSHIP = 'not_owner';

    public function viewAny(User $user, BookEdition $edition): Response
    {
        return $this->membershipGate($user, (int) $edition->id);
    }

    public function create(User $user, BookEdition $edition): Response
    {
        return $this->membershipGate($user, (int) $edition->id);
    }

    public function view(User $user, StudyNote $note): Response
    {
        return $this->ownedNoteGate($user, $note);
    }

    public function update(User $user, StudyNote $note): Response
    {
        return $this->ownedNoteGate($user, $note);
    }

    public function delete(User $user, StudyNote $note): Response
    {
        return $this->ownedNoteGate($user, $note);
    }

    /**
     * Propiedad primero (comparación en memoria, sin consulta) y luego membresía.
     */
    private function ownedNoteGate(User $user, StudyNote $note): Response
    {
        if ((int) $note->user_id !== (int) $user->id) {
            return Response::denyAsNotFound('El apunte solicitado no existe.', self::DENY_CODE_OWNERSHIP);
        }

        return $this->membershipGate($user, (int) $note->book_edition_id);
    }

    private function membershipGate(User $user, int $editionId): Response
    {
        return $user->canAccessStudyNotes($editionId)
            ? Response::allow()
            : Response::deny(
                'Tu cuaderno es exclusivo para miembros activos. Tus apuntes siguen guardados: renueva tu suscripción o canjea un código para recuperarlos.',
                self::DENY_CODE_SUBSCRIPTION
            );
    }
}
