<?php

namespace App\Services;

use App\Exceptions\GoogleAuthException;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class GoogleAuthService
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const USERINFO_URL = 'https://openidconnect.googleapis.com/v1/userinfo';

    /**
     * Indica si las credenciales OAuth de Google están configuradas.
     */
    public function isConfigured(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'));
    }

    /**
     * URI de callback configurada o derivada de la ruta actual.
     */
    public function redirectUri(): string
    {
        $configured = config('services.google.redirect');

        return filled($configured) ? (string) $configured : route('auth.google.callback');
    }

    /**
     * Construye la URL de autorización OAuth 2.0 de Google con protección CSRF (state).
     */
    public function authorizationUrl(string $state): string
    {
        $query = http_build_query([
            'client_id' => (string) config('services.google.client_id'),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'prompt' => 'select_account',
        ]);

        return self::AUTH_URL.'?'.$query;
    }

    /**
     * Intercambia el código OAuth por un token y obtiene el perfil verificado de Google.
     *
     * @return array{google_id: string, email: string, name: string}
     */
    public function fetchVerifiedProfile(string $code): array
    {
        try {
            $tokenResponse = Http::asForm()
                ->timeout(10)
                ->connectTimeout(5)
                ->post(self::TOKEN_URL, [
                    'code' => $code,
                    'client_id' => (string) config('services.google.client_id'),
                    'client_secret' => (string) config('services.google.client_secret'),
                    'redirect_uri' => $this->redirectUri(),
                    'grant_type' => 'authorization_code',
                ]);
        } catch (ConnectionException) {
            throw new GoogleAuthException('No se pudo conectar con Google. Inténtalo nuevamente en unos momentos.');
        }

        if (! $tokenResponse->successful()) {
            throw new GoogleAuthException('No se pudo validar tu acceso con Google. Inténtalo nuevamente.');
        }

        $accessToken = $tokenResponse->json('access_token');
        if (! is_string($accessToken) || trim($accessToken) === '') {
            throw new GoogleAuthException('Google no devolvió un token de acceso válido.');
        }

        try {
            $profileResponse = Http::withToken($accessToken)
                ->timeout(10)
                ->connectTimeout(5)
                ->get(self::USERINFO_URL);
        } catch (ConnectionException) {
            throw new GoogleAuthException('No se pudo obtener tu perfil desde Google. Inténtalo nuevamente.');
        }

        if (! $profileResponse->successful()) {
            throw new GoogleAuthException('No se pudo consultar tu información en Google.');
        }

        $payload = $profileResponse->json();
        if (! is_array($payload)) {
            throw new GoogleAuthException('La respuesta de Google no tiene un formato válido.');
        }

        $googleId = trim((string) ($payload['sub'] ?? ''));
        $email = Str::lower(trim((string) ($payload['email'] ?? '')));
        $emailVerified = filter_var($payload['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;

        if ($googleId === '' || $email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new GoogleAuthException('Google no proporcionó una cuenta con correo válido.');
        }

        if (! $emailVerified) {
            throw new GoogleAuthException('Tu cuenta de Google no tiene el correo electrónico verificado.');
        }

        $name = Str::limit(trim((string) ($payload['name'] ?? '')), 255, '');
        if ($name === '') {
            $name = Str::limit(Str::before($email, '@'), 255, '') ?: 'Usuario';
        }

        return [
            'google_id' => $googleId,
            'email' => $email,
            'name' => $name,
        ];
    }

    /**
     * Resuelve, verifica, vincula o crea al usuario según su estado actual en la plataforma.
     *
     * @param  array{google_id: string, email: string, name: string}  $profile
     */
    public function authenticateOrRegister(array $profile, ?string $ipAddress = null): User
    {
        $googleId = $profile['google_id'];
        $email = $profile['email'];
        $name = $profile['name'];

        $dispatchedEvent = null;

        $user = DB::transaction(function () use ($googleId, $email, $name, $ipAddress, &$dispatchedEvent): User {
            $byGoogleId = User::withTrashed()->where('google_id', $googleId)->first();
            $byEmail = User::withTrashed()->where('email', $email)->first();

            if (($byGoogleId !== null && $byGoogleId->trashed()) || ($byEmail !== null && $byEmail->trashed())) {
                activity('security')
                    ->withProperties([
                        'email' => $email,
                        'ip' => $ipAddress,
                    ])
                    ->log('Intento de acceso con Google sobre una cuenta desactivada.');

                throw new GoogleAuthException('Esta cuenta ha sido desactivada. Por favor, comunícate con el administrador del sistema.');
            }

            if ($byGoogleId !== null && $byEmail !== null && $byGoogleId->id !== $byEmail->id) {
                throw new GoogleAuthException('Esta cuenta de Google y este correo pertenecen a cuentas distintas. Comunícate con soporte.');
            }

            // 1. Usuario ya vinculado previamente con su google_id.
            if ($byGoogleId !== null) {
                if (! $byGoogleId->hasVerifiedEmail() && $byGoogleId->email === $email) {
                    $byGoogleId->forceFill(['email_verified_at' => now()])->save();
                    $dispatchedEvent = new Verified($byGoogleId);
                }

                return $byGoogleId;
            }

            // 2. Usuario registrado por correo (verificado o pendiente de verificación), aún sin google_id.
            if ($byEmail !== null) {
                if ($byEmail->google_id !== null && $byEmail->google_id !== $googleId) {
                    throw new GoogleAuthException('Este correo electrónico ya está vinculado a otra cuenta de Google.');
                }

                $wasVerified = $byEmail->hasVerifiedEmail();
                $updates = ['google_id' => $googleId];

                if (! $wasVerified) {
                    $updates['email_verified_at'] = now();
                    $dispatchedEvent = new Verified($byEmail);
                }

                $byEmail->forceFill($updates)->save();

                if ($byEmail->roles()->doesntExist()) {
                    Role::findOrCreate('User', 'web');
                    $byEmail->assignRole('User');
                }

                activity('users')
                    ->causedBy($byEmail)
                    ->performedOn($byEmail)
                    ->log($wasVerified
                        ? 'Cuenta vinculada con acceso de Google'
                        : 'Correo verificado y cuenta vinculada mediante Google');

                return $byEmail;
            }

            // 3. Usuario inicial: nunca había accedido antes; entra ya verificado sin pedir confirmación por correo.
            $created = new User([
                'name' => $name,
                'email' => $email,
            ]);
            $created->forceFill([
                'google_id' => $googleId,
                'email_verified_at' => now(),
                'password' => Hash::make(Str::random(64)),
                'has_password' => false,
            ]);
            $created->save();

            Role::findOrCreate('User', 'web');
            $created->assignRole('User');

            $dispatchedEvent = new Registered($created);

            return $created;
        });

        if ($dispatchedEvent !== null) {
            event($dispatchedEvent);
        }

        return $user;
    }
}
