<?php

namespace App\Support\Security;

use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Stringable;
use Illuminate\Validation\NotPwnedVerifier;
use Illuminate\Validation\ValidationException;

/**
 * P17 (A10:2025, falla cerrada): verificador de contraseñas filtradas (Have I Been Pwned).
 *
 * El verificador de Laravel, si HIBP no responde, anota el error y deja pasar la contraseña sin
 * avisar. En un hosting que bloquea las conexiones salientes, eso significa que la regla nunca
 * funciona y nadie se entera. Este verificador hace lo contrario: si no puede comprobar, rechaza la
 * contraseña con un mensaje claro, así el problema se ve en el primer registro.
 *
 * Solo se consulta en producción (Password::defaults() en AppServiceProvider) y se puede volver al
 * comportamiento de Laravel con PWNED_PASSWORDS_FAIL_CLOSED=false.
 */
class FailClosedPwnedVerifier extends NotPwnedVerifier
{
    public const UNAVAILABLE_MESSAGE = 'No pudimos comprobar si esta contraseña aparece en filtraciones conocidas. Intenta de nuevo en unos minutos.';

    /**
     * @param  string  $hashPrefix
     * @return Collection<int, string>
     *
     * @throws ValidationException
     */
    protected function search($hashPrefix)
    {
        try {
            $response = $this->factory->withHeaders([
                'Add-Padding' => true,
            ])->timeout($this->timeout)->get(
                'https://api.pwnedpasswords.com/range/'.$hashPrefix
            );
        } catch (Exception $e) {
            report($e);

            throw ValidationException::withMessages(['password' => self::UNAVAILABLE_MESSAGE]);
        }

        if (! $response->successful()) {
            report(new Exception('Have I Been Pwned respondió HTTP '.$response->status().'.'));

            throw ValidationException::withMessages(['password' => self::UNAVAILABLE_MESSAGE]);
        }

        return (new Stringable($response->body()))->trim()->explode("\n")->filter(function ($line) {
            return str_contains($line, ':');
        });
    }
}
