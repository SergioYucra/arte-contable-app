<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\RegistrationAttemptNotification;
use App\Rules\Turnstile;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;
use Throwable;

class RegisteredUserController extends Controller
{
    /**
     * V2 (A07:2025): respuesta única del registro, exista o no una cuenta con ese correo.
     */
    public const NEUTRAL_STATUS = 'Si los datos son correctos, te enviamos un correo para activar tu cuenta. Ábrelo y después inicia sesión.';

    /** V2: el aviso "alguien intentó registrarse con tu correo" sale como mucho una vez por hora por correo. */
    public const ATTEMPT_NOTICE_SECONDS = 3600;

    /**
     * Display the registration view.
     */
    public function create(): View
    {
        return view('auth.register');
    }

    /**
     * Handle an incoming registration request.
     *
     * V2: el formulario ya no dice "ya ha sido registrado". Con o sin cuenta previa, la respuesta es la
     * misma y llega después del mismo trabajo (el hash de la contraseña se calcula siempre). El resto
     * ocurre después de responder (defer), para que el tiempo de respuesta tampoco delate nada:
     *  - correo nuevo: se crea la cuenta con el rol base y se envía la verificación;
     *  - correo existente (activo o desactivado): no se crea nada y el dueño recibe un aviso.
     *
     * Tampoco se inicia sesión al registrarse (V3): hay que confirmar el correo y entrar con la contraseña.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            ...Turnstile::rules(),
        ]);

        $hashedPassword = Hash::make($validated['password']);

        defer(function () use ($validated, $hashedPassword): void {
            try {
                self::completeRegistration($validated['name'], $validated['email'], $hashedPassword);
            } catch (Throwable $e) {
                report($e);
            }
        });

        return redirect()->route('login')->with('status', self::NEUTRAL_STATUS);
    }

    private static function completeRegistration(string $name, string $email, string $hashedPassword): void
    {
        $existing = User::withTrashed()->where('email', $email)->first();

        if ($existing) {
            if (Cache::add('registration-attempt-notice:'.sha1($existing->email), true, self::ATTEMPT_NOTICE_SECONDS)) {
                $existing->notify(new RegistrationAttemptNotification(deactivated: $existing->trashed()));
            }

            return;
        }

        try {
            $user = DB::transaction(function () use ($name, $email, $hashedPassword): User {
                $user = User::create([
                    'name' => $name,
                    'email' => $email,
                    'password' => $hashedPassword,
                ]);

                // Principio de mínimo privilegio: el registro público solo otorga el rol base.
                Role::findOrCreate('User', 'web');
                $user->assignRole('User');

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            // Dos registros simultáneos con el mismo correo: el otro ya creó la cuenta.
            return;
        }

        event(new Registered($user));
    }
}
