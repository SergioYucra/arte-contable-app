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
     * Lista blanca estricta de tipos MIME permitidos para visualización en línea (inline).
     * Prohibido incluir image/svg+xml o text/html para prevenir Stored XSS.
     *
     * @var list<string>
     */
    public const SAFE_INLINE_MIMES = [
        'image/webp',
        'image/jpeg',
        'image/png',
        'application/pdf',
    ];

    /**
     * Extensiones activas o ejecutables bloqueadas de forma absoluta en almacenamiento.
     *
     * @var list<string>
     */
    private const FORBIDDEN_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar',
        'html', 'htm', 'xhtml', 'svg', 'svgz', 'xml',
        'js', 'mjs', 'cjs', 'sh', 'bash', 'exe', 'bat', 'cmd', 'msi',
    ];

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
     * - Deriva la extensión a partir del contenido binario real (finfo) en lugar de confiar en el nombre del cliente.
     * - Genera un nombre de archivo criptográficamente aleatorio (UUID) para evitar sobrescrituras.
     *
     * @param  UploadedFile  $file  Archivo recibido de una solicitud HTTP validada.
     * @param  string  $subfolder  Subdirectorio lógico dentro del vault (ej. 'payments/2026', 'editions/1').
     * @param  list<string>  $allowedMimes  Lista blanca opcional de tipos MIME reales permitidos (ej. ['image/webp']).
     * @return array{path: string, original_name: string, mime_type: string, size_bytes: int}
     */
    public static function store(UploadedFile $file, string $subfolder = '', array $allowedMimes = []): array
    {
        $safeSubfolder = self::validateSubfolder($subfolder);
        $detectedMime = $file->getMimeType() ?: 'application/octet-stream';

        if ($allowedMimes !== [] && ! in_array($detectedMime, $allowedMimes, true)) {
            abort(422, 'El tipo de archivo no coincide con los formatos permitidos.');
        }

        // Usar exclusivamente la extensión derivada del contenido binario real (finfo) cuando exista
        $finfoExtension = strtolower((string) $file->extension());
        $safeExtension = preg_replace('/[^a-z0-9]/', '', $finfoExtension) ?? '';

        if (in_array($safeExtension, self::FORBIDDEN_EXTENSIONS, true)) {
            abort(422, 'El formato del archivo subido no está permitido por políticas de seguridad.');
        }

        // También rechazar si el nombre original declaraba una extensión ejecutable prohibida
        $clientExtension = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $file->getClientOriginalExtension()));
        if (in_array($clientExtension, self::FORBIDDEN_EXTENSIONS, true)) {
            abort(422, 'El formato del archivo subido no está permitido por políticas de seguridad.');
        }

        $randomFileName = Str::uuid()->toString().($safeExtension !== '' ? '.'.$safeExtension : '');

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
            'mime_type' => $detectedMime,
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
     * Transmite de forma segura en línea (inline) recursos verificados de la bóveda privada
     * (como páginas WebP del lector o comprobantes de pago para revisión administrativa).
     *
     * Aplica denegación por defecto (fail-safe) si el MIME real no pertenece a SAFE_INLINE_MIMES.
     */
    public static function streamInline(string $path, ?string $inlineName = null): StreamedResponse
    {
        $validatedPath = self::validatePath($path);

        if (! Storage::disk(self::DISK)->exists($validatedPath)) {
            abort(404, 'El recurso solicitado no existe en la bóveda privada.');
        }

        $mimeType = Storage::disk(self::DISK)->mimeType($validatedPath) ?: 'application/octet-stream';

        if (! in_array($mimeType, self::SAFE_INLINE_MIMES, true)) {
            abort(415, 'Este tipo de archivo no puede visualizarse directamente en el navegador.');
        }

        $fallbackName = basename($validatedPath);
        $finalName = $inlineName ? basename($inlineName) : $fallbackName;

        // Para PDF se habilita object-src 'self' sin 'sandbox' vacío para no bloquear el visor nativo en Chromium/Edge;
        // para imágenes rasterizadas se aplica sandbox estricto sin ejecución de scripts.
        $cspHeader = $mimeType === 'application/pdf'
            ? "default-src 'none'; object-src 'self'; frame-ancestors 'self'"
            : "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; frame-ancestors 'self'; sandbox";

        $headers = [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline; filename="'.addslashes($finalName).'"',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Content-Security-Policy' => $cspHeader,
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
        ];

        return Storage::disk(self::DISK)->response($validatedPath, $finalName, $headers);
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
