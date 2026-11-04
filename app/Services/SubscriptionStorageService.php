<?php

namespace App\Services;

use App\Core\Storage\PrivateVault;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Servicio de almacenamiento y entrega segura para el módulo de suscripciones:
 * - Códigos QR bancarios públicos por plan (webp/jpg/png con límite de 2 MB y nombre aleatorio).
 * - Comprobantes de pago de alumnos (resguardados en PrivateVault, nunca expuestos públicamente).
 */
class SubscriptionStorageService
{
    private const ALLOWED_QR_MIMES = [
        'image/webp' => 'webp',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    /**
     * Guarda la imagen del código QR bancario en el disco público.
     */
    public function storePlanQr(UploadedFile $file): string
    {
        $detectedMime = $file->getMimeType() ?: '';
        if (! array_key_exists($detectedMime, self::ALLOWED_QR_MIMES)) {
            throw ValidationException::withMessages([
                'qr_image' => 'El código QR debe ser una imagen válida (WebP, JPG o PNG).',
            ]);
        }

        $extension = self::ALLOWED_QR_MIMES[$detectedMime];
        $fileName = Str::uuid()->toString().'.'.$extension;

        Storage::disk('public')->putFileAs('plans-qr', $file, $fileName);

        return 'plans-qr/'.$fileName;
    }

    /**
     * Elimina una imagen de código QR del disco público si no es un archivo por defecto.
     */
    public function deletePlanQr(?string $qrPath): void
    {
        if (
            blank($qrPath) ||
            ! str_starts_with($qrPath, 'plans-qr/') ||
            str_contains($qrPath, '..')
        ) {
            return;
        }

        if (Storage::disk('public')->exists($qrPath)) {
            Storage::disk('public')->delete($qrPath);
        }
    }

    /**
     * Almacena de forma segura un comprobante de pago en la bóveda privada.
     *
     * @return array{path: string, original_name: string, mime_type: string, size_bytes: int}
     */
    public function storePaymentProof(UploadedFile $file): array
    {
        return PrivateVault::store($file, 'receipts');
    }

    /**
     * Transmite en streaming seguro el comprobante de pago para inspección del administrador.
     */
    public function streamProof(string $path): StreamedResponse
    {
        PrivateVault::validatePath($path);

        return PrivateVault::streamInline($path);
    }
}
