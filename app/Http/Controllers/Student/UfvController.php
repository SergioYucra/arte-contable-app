<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class UfvController extends Controller
{
    private const BCB_UFV_ENDPOINT = 'https://www.bcb.gob.bo/librerias/charts/ufv.php';

    /**
     * Consulta la UFV diaria desde el servidor (sin exponer la URL de origen al cliente)
     * y cachea respuestas válidas durante 24 horas.
     */
    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'fecha' => ['required', 'date_format:Y-m-d'],
        ]);

        $fechaIso = $validated['fecha'];
        $cacheKey = "ufv:bcb:{$fechaIso}";

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return response()->json([
                'fecha' => $fechaIso,
                'ufv' => $cached === '__NULL__' ? null : (float) $cached,
            ]);
        }

        try {
            $response = Http::timeout(8)
                ->acceptJson()
                ->get(self::BCB_UFV_ENDPOINT, [
                    'cFecIni' => $fechaIso,
                    'cFecFin' => $fechaIso,
                ]);

            if (! $response->successful()) {
                return response()->json([
                    'message' => 'No se pudo consultar la API del BCB.',
                ], 502);
            }

            $payload = $response->json();

            if (! is_array($payload) || empty($payload)) {
                Cache::put($cacheKey, '__NULL__', now()->addHours(2));

                return response()->json([
                    'fecha' => $fechaIso,
                    'ufv' => null,
                ]);
            }

            $expectedDate = Carbon::createFromFormat('Y-m-d', $fechaIso)->format('d/m/Y');
            $matched = collect($payload)->first(fn ($item) => ($item['fecha'] ?? null) === $expectedDate) ?? $payload[0];

            $rawValue = $matched['val_ufv'] ?? null;
            if (! is_numeric($rawValue)) {
                Cache::put($cacheKey, '__NULL__', now()->addHours(2));

                return response()->json([
                    'fecha' => $fechaIso,
                    'ufv' => null,
                ]);
            }

            $ufv = round((float) $rawValue, 5);
            Cache::put($cacheKey, $ufv, now()->addHours(24));

            return response()->json([
                'fecha' => $fechaIso,
                'ufv' => $ufv,
            ]);
        } catch (Throwable) {
            return response()->json([
                'message' => 'No se pudo consultar la API del BCB.',
            ], 502);
        }
    }
}
