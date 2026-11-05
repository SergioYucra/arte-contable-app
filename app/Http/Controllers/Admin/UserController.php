<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

/**
 * Administración de usuarios y niveles de acceso (Capa Core).
 *
 * Las reglas de autorización viven en App\Policies\UserPolicy (N8): aquí solo se autoriza con
 * Gate::authorize(...) y la policy fija el código HTTP (403 permisos, 404 anti-enumeración, 422 reglas).
 */
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

    /** N9: reenvíos del correo de verificación permitidos por usuario destino dentro de la ventana. */
    public const RESEND_VERIFICATION_MAX_ATTEMPTS = 3;

    /** N9: ventana del límite de reenvíos, en segundos (1 hora). */
    public const RESEND_VERIFICATION_DECAY_SECONDS = 3600;

    public static function roleLabel(string $role): string
    {
        return self::ROLE_LABELS[$role] ?? $role;
    }

    /**
     * Muestra el panel interactivo de administración de usuarios y roles.
     * Incluye usuarios activos y desactivados (SoftDeletes) con estado de verificación.
     * Las cuentas SuperAdmin permanecen completamente invisibles para roles inferiores.
     */
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', User::class);

        $currentUser = $request->user();
        $isSuperAdmin = $currentUser->hasRole('SuperAdmin');

        $usersQuery = User::withTrashed()
            ->with('roles:id,name')
            ->select('id', 'name', 'email', 'created_at', 'deleted_at', 'email_verified_at');

        // Mismo criterio que UserPolicy::view(): un SuperAdmin no existe para quien no lo es
        if (! $isSuperAdmin) {
            $usersQuery->whereDoesntHave('roles', function ($query): void {
                $query->where('name', 'SuperAdmin');
            });
        }

        $users = $usersQuery
            ->latest('id')
            ->get()
            ->map(fn (User $user) => $this->presentUser($user, $currentUser));

        // Si el usuario autenticado NO es SuperAdmin, no debe ver ni poder asignar 'SuperAdmin'
        $availableRolesQuery = Role::query();
        if (! $isSuperAdmin) {
            $availableRolesQuery->where('name', '!=', 'SuperAdmin');
        }
        $availableRoles = $availableRolesQuery->pluck('name')->values()->all();

        // H7: el modal solo ofrece los niveles que UserPolicy::updateRole() permite asignar.
        // availableRoles se mantiene completo porque alimenta los filtros de la tabla.
        $assignableRoles = $isSuperAdmin
            ? $availableRoles
            : array_values(array_diff($availableRoles, UserPolicy::SUPERADMIN_ONLY_ROLES));

        return view('admin.users.index', [
            'users' => $users,
            'availableRoles' => $availableRoles,
            'assignableRoles' => $assignableRoles,
            'isSuperAdmin' => $isSuperAdmin,
        ]);
    }

    /**
     * Actualiza el rol de un usuario de forma asíncrona (AJAX / Axios).
     */
    public function updateRole(Request $request, User $user): JsonResponse
    {
        $currentUser = $request->user();

        // El 404 anti-enumeración se decide ANTES de validar: un payload inválido contra un
        // SuperAdmin no debe responder 422 y delatar que la cuenta existe.
        Gate::authorize('view', $user);

        $validated = $request->validate([
            'role' => ['required', 'string', 'exists:roles,name'],
        ]);

        $newRole = $validated['role'];

        Gate::authorize('updateRole', [$user, $newRole]);

        $previousRole = $user->roles->first()?->name ?? 'Ninguno';
        $user->syncRoles([$newRole]);
        $user->load('roles:id,name');

        activity('users')
            ->causedBy($currentUser)
            ->performedOn($user)
            ->withProperties(['old_role' => $previousRole, 'new_role' => $newRole])
            ->log("Rol de usuario modificado a '{$newRole}'");

        return response()->json([
            'success' => true,
            'message' => "El nivel de acceso de {$user->name} se actualizó a ".self::roleLabel($newRole).'.',
            'user' => $this->presentUser($user, $currentUser),
        ]);
    }

    /**
     * Desactiva (SoftDelete) un usuario del sistema, revocando todas sus sesiones activas.
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        $currentUser = $request->user();

        Gate::authorize('delete', $user);

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
            'user' => $this->presentUser($user, $currentUser),
        ]);
    }

    /**
     * Reactiva un usuario previamente desactivado (SoftDeletes).
     * Registra la reactivación en la bitácora de auditoría.
     */
    public function restore(Request $request, int|string $id): JsonResponse
    {
        $currentUser = $request->user();

        $user = User::onlyTrashed()->with('roles:id,name')->findOrFail($id);

        Gate::authorize('restore', $user);

        $user->restore();

        activity('users')
            ->causedBy($currentUser)
            ->performedOn($user)
            ->withProperties(['name' => $user->name, 'email' => $user->email])
            ->log("Usuario '{$user->name}' ({$user->email}) reactivado exitosamente");

        return response()->json([
            'success' => true,
            'message' => "La cuenta de {$user->name} fue reactivada correctamente.",
            'user' => $this->presentUser($user, $currentUser),
        ]);
    }

    /**
     * Reenvía la notificación de verificación de correo electrónico para un usuario no verificado.
     * N9: máximo 3 reenvíos por hora por usuario destino. Registra cada reenvío y el primer bloqueo de cada ventana.
     */
    public function resendVerification(Request $request, User $user): JsonResponse
    {
        $currentUser = $request->user();

        Gate::authorize('resendVerification', $user);

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'message' => "La cuenta de {$user->name} ya se encuentra verificada.",
            ], 422);
        }

        $throttleKey = 'resend-verification:'.$user->id;

        if (RateLimiter::tooManyAttempts($throttleKey, self::RESEND_VERIFICATION_MAX_ATTEMPTS)) {
            $secondsLeft = max(1, RateLimiter::availableIn($throttleKey));
            $minutes = (int) ceil($secondsLeft / 60);

            // La bitácora es inmutable y no se depura: solo se registra el primer bloqueo de la ventana,
            // así repetir el clic no la llena de filas iguales.
            if (Cache::add($throttleKey.':blocked-logged', true, $secondsLeft)) {
                activity('security')
                    ->causedBy($currentUser)
                    ->performedOn($user)
                    ->withProperties(['email' => $user->email, 'ip' => $request->ip()])
                    ->log("Reenvío de verificación a '{$user->email}' bloqueado por exceder el límite (máximo ".self::RESEND_VERIFICATION_MAX_ATTEMPTS.' por hora).');
            }

            return response()->json([
                'message' => 'Se alcanzó el límite de '.self::RESEND_VERIFICATION_MAX_ATTEMPTS." reenvíos por hora para {$user->email}. Intenta de nuevo en {$minutes} minutos.",
            ], 429);
        }

        RateLimiter::hit($throttleKey, self::RESEND_VERIFICATION_DECAY_SECONDS);

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

    /**
     * Representación de un usuario para la isla Vue.
     *
     * `can_edit` sale de UserPolicy (N8/H7) para que la interfaz nunca ofrezca una acción que el
     * servidor rechazaría; `is_self` marca la fila del operador conectado (UX-09).
     *
     * @return array<string, mixed>
     */
    private function presentUser(User $user, User $currentUser): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->roles->first()?->name ?? 'User',
            'created_at' => $user->created_at?->format('d/m/Y H:i') ?? '',
            'deleted_at' => $user->deleted_at?->toISOString(),
            'is_active' => is_null($user->deleted_at),
            'email_verified_at' => $user->email_verified_at?->toISOString(),
            'is_verified' => ! is_null($user->email_verified_at),
            'is_self' => $user->id === $currentUser->id,
            'can_edit' => Gate::forUser($currentUser)->allows('updateRole', $user),
        ];
    }
}
