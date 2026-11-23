<?php

namespace App\Modules\LibraryProtection\Services;

use App\Models\Activity;
use App\Models\User;
use App\Modules\LibraryProtection\Models\ReadingLease;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * Un dispositivo a la vez (como Netflix o Spotify).
 *
 * Cada navegador recibe una cookie propia, cifrada y solo-HTTP, con un identificador aleatorio.
 * La cuenta guarda el hash del dispositivo que tiene el turno de lectura:
 * - si el turno está libre, o el dispositivo anterior lleva `device_idle_minutes` sin pedir
 *   páginas, este dispositivo lo toma solo;
 * - si el otro dispositivo sigue leyendo, este recibe "Tu cuenta está abierta en otro
 *   dispositivo" y puede elegir "Leer aquí" (claim), que deja al otro afuera.
 *
 * Se usa una cookie de dispositivo y no el ID de sesión para que cerrar sesión y volver a
 * entrar en el mismo navegador no cuente como "otro dispositivo".
 */
final class ReaderDevices
{
    public const COOKIE = 'lector_dispositivo';

    private const COOKIE_MINUTES = 60 * 24 * 400;

    public function __construct(private readonly ReadingAlerts $alerts) {}

    /** ¿Puede este dispositivo pedir páginas ahora? Toma el turno si está libre. */
    public function allows(User $user, Request $request): bool
    {
        $hash = $this->deviceHash($request);
        $lease = $this->leaseFor($user, $request, $hash);

        if (! $lease->wasRecentlyCreated && ! hash_equals($lease->device_hash, $hash)) {
            $idleLimit = Carbon::now()->subMinutes(max(1, (int) config('library.protection.device_idle_minutes', 10)));
            if ($lease->last_seen_at->greaterThan($idleLimit)) {
                return false;
            }

            $this->switchTo($lease, $user, $request, $hash, automatic: true);
        } elseif (! $lease->wasRecentlyCreated) {
            $this->touch($lease, $request);
        }

        if ($this->rememberIp($user, $request)) {
            $this->checkSharing($user);
        }

        return true;
    }

    /** "Leer aquí": este dispositivo toma el turno aunque el otro siga leyendo. */
    public function claim(User $user, Request $request): void
    {
        $hash = $this->deviceHash($request);
        $lease = $this->leaseFor($user, $request, $hash);

        if ($lease->wasRecentlyCreated) {
            return;
        }

        if (hash_equals($lease->device_hash, $hash)) {
            $this->touch($lease, $request);

            return;
        }

        $this->switchTo($lease, $user, $request, $hash, automatic: false);
    }

    private function deviceHash(Request $request): string
    {
        $value = $request->cookie(self::COOKIE);

        if (! is_string($value) || ! preg_match('/^[A-Za-z0-9]{40}$/', $value)) {
            $value = Str::random(40);
            // secure y same_site salen de config/session.php (null = valor de la sesión).
            Cookie::queue(Cookie::make(self::COOKIE, $value, self::COOKIE_MINUTES, null, null, null, true, false, null));
            $request->cookies->set(self::COOKIE, $value);
        }

        return hash('sha256', $value);
    }

    private function leaseFor(User $user, Request $request, string $hash): ReadingLease
    {
        $existing = ReadingLease::where('user_id', $user->id)->first();
        if ($existing !== null) {
            return $existing;
        }

        $now = Carbon::now();

        // createOrFirst: si dos pedidos del primer uso llegan juntos, ninguno falla.
        return ReadingLease::createOrFirst(
            ['user_id' => $user->id],
            [
                'device_hash' => $hash,
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
                'claimed_at' => $now,
                'last_seen_at' => $now,
            ],
        );
    }

    private function touch(ReadingLease $lease, Request $request): void
    {
        $ipChanged = $request->ip() !== null && $lease->ip_address !== $request->ip();

        // Basta con refrescar cada 20 segundos: el turno se libera recién tras varios minutos.
        if (! $ipChanged && $lease->last_seen_at->greaterThan(Carbon::now()->subSeconds(20))) {
            return;
        }

        $lease->forceFill([
            'ip_address' => $request->ip(),
            'last_seen_at' => Carbon::now(),
        ])->save();
    }

    /**
     * Guarda las conexiones (IP) desde donde leyó la cuenta en la ventana del aviso.
     * Devuelve true si esta IP es nueva en la ventana.
     */
    private function rememberIp(User $user, Request $request): bool
    {
        $ip = $request->ip();
        if ($ip === null || $ip === '') {
            return false;
        }

        $windowHours = max(1, (int) config('library.protection.sharing_alert.window_hours', 24));
        $key = "library-protection:ips:{$user->id}";
        $now = Carbon::now()->getTimestamp();
        $cutoff = $now - $windowHours * 3600;

        $ips = array_filter((array) Cache::get($key, []), fn ($seenAt) => (int) $seenAt >= $cutoff);
        $isNew = ! array_key_exists($ip, $ips);

        // Se escribe solo si la IP es nueva o su fecha tiene más de una hora (pocas escrituras).
        if ($isNew || (int) $ips[$ip] < $now - 3600) {
            $ips[$ip] = $now;
            Cache::put($key, $ips, now()->addHours($windowHours));
        }

        return $isNew;
    }

    private function switchTo(ReadingLease $lease, User $user, Request $request, string $hash, bool $automatic): void
    {
        $previousIp = $lease->ip_address;
        $now = Carbon::now();

        $lease->forceFill([
            'device_hash' => $hash,
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
            'claimed_at' => $now,
            'last_seen_at' => $now,
        ])->save();

        activity('security')
            ->causedBy($user)
            ->event('cambio_de_dispositivo')
            ->withProperties([
                'automatico' => $automatic,
                'ip' => $request->ip(),
                'ip_anterior' => $previousIp,
                'navegador' => Str::limit((string) $request->userAgent(), 120, ''),
            ])
            ->log($automatic
                ? 'El lector pasó a otro dispositivo (el anterior llevaba un rato sin leer).'
                : 'La persona eligió "Leer aquí" en otro dispositivo.');

        $this->checkSharing($user);
    }

    /** Aviso (sin pausa) cuando una cuenta salta mucho entre dispositivos o conexiones. */
    private function checkSharing(User $user): void
    {
        $config = (array) config('library.protection.sharing_alert', []);
        $windowHours = max(1, (int) ($config['window_hours'] ?? 24));
        $since = Carbon::now()->subHours($windowHours);

        $switches = Activity::query()
            ->where('log_name', 'security')
            ->where('event', 'cambio_de_dispositivo')
            ->where('causer_type', $user->getMorphClass())
            ->where('causer_id', $user->id)
            ->where('created_at', '>=', $since)
            ->count();

        $cutoff = $since->getTimestamp();
        $ips = count(array_filter(
            (array) Cache::get("library-protection:ips:{$user->id}", []),
            fn ($seenAt) => (int) $seenAt >= $cutoff,
        ));

        $tooManySwitches = $switches >= max(1, (int) ($config['device_switches'] ?? 6));
        $tooManyIps = $ips >= max(1, (int) ($config['distinct_ips'] ?? 5));

        if (! $tooManySwitches && ! $tooManyIps) {
            return;
        }

        // Un aviso por cuenta y por ventana: la bitácora es permanente y no debe llenarse de repetidos.
        if (! Cache::add("library-protection:sharing-flag:{$user->id}", true, now()->addHours($windowHours))) {
            return;
        }

        activity('security')
            ->causedBy($user)
            ->event('posible_cuenta_compartida')
            ->withProperties([
                'cambios_de_dispositivo' => $switches,
                'conexiones_distintas' => $ips,
                'ventana_horas' => $windowHours,
            ])
            ->log("Posible cuenta compartida: {$switches} cambios de dispositivo y {$ips} conexiones distintas en {$windowHours} horas.");

        $this->alerts->possibleSharing($user, $switches, $ips, $windowHours);
    }
}
