<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Rules\Turnstile;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * V5 (A07:2025): todo login fallido tarda al menos esto (microsegundos). Cubre el intento normal
     * (hasta ~250 ms de bcrypt) más la comprobación de cuenta desactivada (otro bcrypt), así el tiempo
     * no distingue "no existe", "contraseña incorrecta" y "cuenta desactivada".
     */
    public const FAILED_LOGIN_MIN_MICROSECONDS = 800_000;

    /** P5 (H3): fallos permitidos por correo + IP antes de bloquear 1 minuto. */
    public const MAX_ATTEMPTS_PER_ACCOUNT = 5;

    /**
     * P5 (H3): fallos permitidos por IP, con cualquier correo, en la ventana de abajo. Frena probar una
     * misma contraseña contra muchas cuentas. Es alto a propósito: un aula entera puede salir por una IP.
     */
    public const MAX_ATTEMPTS_PER_IP = 50;

    public const IP_DECAY_SECONDS = 900;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            ...Turnstile::rules(),
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * Devuelve la cuenta si la contraseña es correcta pero falta el segundo paso (P4: 2FA activo); en
     * ese caso NO se inicia sesión. Devuelve null si la sesión ya quedó iniciada.
     *
     * @throws ValidationException
     */
    public function authenticate(): ?User
    {
        $this->ensureIsNotRateLimited();

        return (new Timebox)->call(function (Timebox $timebox): ?User {
            $credentials = $this->only('email', 'password');
            $candidate = User::where('email', (string) $this->string('email'))->first();

            if ($candidate?->hasEnabledTwoFactorAuthentication()) {
                // P4: con 2FA solo se valida la contraseña; la sesión se inicia después del código.
                if (Auth::guard('web')->getProvider()->validateCredentials($candidate, $credentials)) {
                    RateLimiter::clear($this->throttleKey());
                    $timebox->returnEarly();

                    return $candidate;
                }

                event(new Failed('web', $candidate, $credentials));
            } elseif (Auth::attempt($credentials, $this->boolean('remember'))) {
                RateLimiter::clear($this->throttleKey());
                $timebox->returnEarly();

                return null;
            }

            RateLimiter::hit($this->throttleKey());
            RateLimiter::hit($this->ipThrottleKey(), self::IP_DECAY_SECONDS);

            $this->rejectDeactivatedAccount();

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }, self::FAILED_LOGIN_MIN_MICROSECONDS);
    }

    /**
     * N1: el aviso de cuenta desactivada solo aparece con la contraseña correcta.
     *
     * V5: la comparación bcrypt se hace SIEMPRE, contra la cuenta desactivada si existe o contra un
     * hash de relleno si no; antes solo se hacía cuando la cuenta existía y eso se notaba en el tiempo.
     *
     * @throws ValidationException
     */
    private function rejectDeactivatedAccount(): void
    {
        $trashedUser = User::onlyTrashed()
            ->where('email', (string) $this->string('email'))
            ->first();

        $matches = Hash::check((string) $this->string('password'), $trashedUser?->password ?? self::dummyHash());

        if ($trashedUser && $matches) {
            throw ValidationException::withMessages([
                'email' => __('Esta cuenta ha sido desactivada. Por favor, comunícate con el administrador del sistema.'),
            ]);
        }
    }

    /**
     * Hash bcrypt de relleno con el mismo costo que las contraseñas reales (BCRYPT_ROUNDS).
     * Se calcula una vez y queda en caché.
     */
    private static function dummyHash(): string
    {
        $rounds = (int) config('hashing.bcrypt.rounds', 12);

        return Cache::rememberForever('auth:dummy-hash:'.$rounds, fn (): string => Hash::make(Str::random(40)));
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        $key = match (true) {
            RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS_PER_ACCOUNT) => $this->throttleKey(),
            RateLimiter::tooManyAttempts($this->ipThrottleKey(), self::MAX_ATTEMPTS_PER_IP) => $this->ipThrottleKey(),
            default => null,
        };

        if ($key === null) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($key);

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }

    public function ipThrottleKey(): string
    {
        return 'login-ip:'.$this->ip();
    }
}
