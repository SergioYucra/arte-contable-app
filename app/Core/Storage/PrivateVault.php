<?php

namespace App\Core\Storage;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Servicio de Almacenamiento Seguro (PrivateVault).
 *
 * Administra el guardado y entrega de archivos privados fuera de la raíz web pública,
 * aplicando estrictas directrices de seguridad (CWE-22 Path Traversal, CWE-434 Unrestricted Upload).
 */
class PrivateVault
{
    public const DISK = 'private_vault';

    /**
     * Valida estrictamente una ruta relativa contra ataques de Path Traversal (CWE-22).
     * Rechaza secuencias '..', separadores Windows '\', slash inicial '/' y byte nulo '\0'.
     */
    public static function validatePath(string $path): string
    {
        if (
            str_contains($path, '..') ||
            str_contains($path, '\\') ||
            str_starts_with($path, '/') ||
            str_contains($path, "\0")
        ) {
            abort(404, 'Ruta de archivo inválida o intento de acceso no permitido.');
        }

        $trimmed = trim($path);

        if ($trimmed === '') {
            abort(404, 'Ruta de archivo vacía.');
        }

        return $trimmed;
    }

    /**
     * Valida el subdirectorio contra una lista blanca regex estricta.
     */
    public static function validateSubfolder(string $subfolder): string
    {
        $clean = trim($subfolder, '/');

        if ($clean === '') {
            return '';
        }

        if (
            str_contains($clean, '..') ||
            str_contains($clean, '\\') ||
            str_contains($clean, "\0") ||
            ! preg_match('/^[a-z0-9_-]+(\/[a-z0-9_-]+)*$/', $clean)
        ) {
            abort(404, 'Subdirectorio inválido o intento de acceso no permitido.');
        }

        return $clean;
    }

    /**
     * Guarda un archivo subido de forma segura en el disco privado.
     *
     * - Valida el subdirectorio con una lista blanca regex estricta.
     * - Genera un nombre de archivo criptográficamente aleatorio (UUID) para evitar sobrescrituras
     *   y neutralizar ataques de ejecución de nombres maliciosos.
     *
     * @param  UploadedFile  $file  Archivo recibido de una solicitud HTTP validada.
     * @param  string  $subfolder  Subdirectorio lógico dentro del vault (ej. 'invoices', 'contracts').
     * @return array{path: string, original_name: string, mime_type: string, size_bytes: int}
     */
    public static function store(UploadedFile $file, string $subfolder = ''): array
    {
        $safeSubfolder = self::validateSubfolder($subfolder);

        // Nombre de archivo seguro e impredecible
        $extension = strtolower($file->getClientOriginalExtension());
        $safeExtension = preg_replace('/[^a-z0-9]/', '', $extension);
        $randomFileName = Str::uuid()->toString().($safeExtension ? '.'.$safeExtension : '');

        $path = $safeSubfolder !== ''
            ? $safeSubfolder.'/'.$randomFileName
            : $randomFileName;

        Storage::disk(self::DISK)->putFileAs(
            $safeSubfolder,
            $file,
            $randomFileName
        );

        return [
            'path' => $path,
            'original_name' => basename($file->getClientOriginalName()),
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'size_bytes' => $file->getSize() ?: 0,
        ];
    }

    /**
     * Descarga de forma segura un archivo privado transmitiéndolo en streaming
     * con cabeceras de protección estrictas contra XSS y Content Sniffing.
     *
     * @param  string  $path  Ruta relativa dentro de private_vault.
     * @param  string|null  $downloadName  Nombre con el que el usuario descargará el archivo.
     */
    public static function download(string $path, ?string $downloadName = null): StreamedResponse
    {
        $validatedPath = self::validatePath($path);

        if (! Storage::disk(self::DISK)->exists($validatedPath)) {
            abort(404, 'El archivo solicitado no existe en la bóveda privada.');
        }

        $fallbackName = basename($validatedPath);
        $finalName = $downloadName ? basename($downloadName) : $fallbackName;
        $mimeType = Storage::disk(self::DISK)->mimeType($validatedPath) ?: 'application/octet-stream';

        $headers = [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'attachment; filename="'.addslashes($finalName).'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
        ];

        return Storage::disk(self::DISK)->download($validatedPath, $finalName, $headers);
    }

    /**
     * Elimina un archivo del vault previa validación estricta de ruta.
     */
    public static function delete(string $path): bool
    {
        $validatedPath = self::validatePath($path);

        if (Storage::disk(self::DISK)->exists($validatedPath)) {
            return Storage::disk(self::DISK)->delete($validatedPath);
        }

        return false;
    }
}
