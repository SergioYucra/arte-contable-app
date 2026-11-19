<?php

namespace App\Modules\LibraryProtection\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\LibraryProtection\Services\ReaderDevices;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Leer aquí": el estudiante pasa la lectura a este dispositivo y el otro se detiene.
 */
class ReaderDeviceController extends Controller
{
    public function claim(Request $request, ReaderDevices $devices): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $devices->claim($user, $request);

        return response()->json([
            'success' => true,
            'message' => 'Listo: ahora lees en este dispositivo.',
        ]);
    }
}
