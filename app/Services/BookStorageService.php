<?php

namespace App\Services;

use App\Core\Storage\PrivateVault;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Servicio dedicado exclusivamente a operaciones seguras de almacenamiento
 * y administración de espacio en disco para portadas y páginas de libros.
 */
class BookStorageService
{
    private const ALLOWED_COVER_MIMES = [
        'image/webp' => 'webp',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    /**
     * Verifica en los primeros 12 bytes reales que el archivo tenga firma binaria RIFF....WEBP.
     */
    public static function hasValidWebpSignature(UploadedFile $file): bool
    {
        $realPath = $file->getRealPath();
        if (! is_string($realPath) || $realPath === '' || ! is_readable($realPath)) {
            return false;
        }

        $header = @file_get_contents($realPath, false, null, 0, 12);
        if (! is_string($header) || strlen($header) < 12) {
            return false;
        }

        return substr($header, 0, 4) === 'RIFF' && substr($header, 8, 4) === 'WEBP';
    }

    /**
     * Guarda una portada validada en el disco público con nombre aleatorio (UUID).
     */
    public function storeCover(UploadedFile $file): string
    {
        $detectedMime = $file->getMimeType() ?: '';
        if (! array_key_exists($detectedMime, self::ALLOWED_COVER_MIMES)) {
            throw ValidationException::withMessages([
                'cover' => 'El formato de la imagen de portada no está permitido.',
            ]);
        }

        $extension = self::ALLOWED_COVER_MIMES[$detectedMime];
        $fileName = Str::uuid()->toString().'.'.$extension;

        Storage::disk('public')->putFileAs('covers', $file, $fileName);

        return 'covers/'.$fileName;
    }

    /**
     * Elimina de forma segura una portada anterior para no acumular imágenes huérfanas.
     */
    public function deleteCover(?string $coverPath): void
    {
        if (
            empty($coverPath) ||
            str_contains($coverPath, '..') ||
            ! str_starts_with($coverPath, 'covers/')
        ) {
            return;
        }

        if (Storage::disk('public')->exists($coverPath)) {
            Storage::disk('public')->delete($coverPath);
        }
    }

    /**
     * Guarda un lote de páginas WebP en la bóveda privada y elimina páginas sobrantes al concluir.
     *
     * @param  array<int, array{number: int, file: UploadedFile}>  $pagesBatch
     * @return array{stored_pages_count: int, storage_bytes: int}
     */
    public function storePagesBatch(int $editionId, array $pagesBatch, int $totalPages, bool $isFinalBatch): array
    {
        $subfolder = PrivateVault::validateSubfolder("editions/{$editionId}");
        $disk = Storage::disk(PrivateVault::DISK);

        foreach ($pagesBatch as $item) {
            $pageNumber = (int) $item['number'];
            /** @var UploadedFile $file */
            $file = $item['file'];

            if (($file->getMimeType() ?: '') !== 'image/webp' || ! self::hasValidWebpSignature($file)) {
                throw ValidationException::withMessages([
                    'pages' => "La página {$pageNumber} no corresponde a una imagen optimizada WebP válida.",
                ]);
            }

            $fileName = "page-{$pageNumber}.webp";
            PrivateVault::validatePath("{$subfolder}/{$fileName}");

            $disk->putFileAs($subfolder, $file, $fileName);
        }

        if ($isFinalBatch) {
            $this->cleanupOrphanFiles($subfolder, $totalPages);
        }

        return $this->inspectEditionStorage($editionId);
    }

    /**
     * Vacía todos los archivos físicos de páginas de una edición para liberar espacio en servidor.
     */
    public function purgeEditionPages(int $editionId): void
    {
        $subfolder = PrivateVault::validateSubfolder("editions/{$editionId}");
        $disk = Storage::disk(PrivateVault::DISK);

        if ($disk->exists($subfolder)) {
            $disk->deleteDirectory($subfolder);
        }
    }

    /**
     * @return array{stored_pages_count: int, storage_bytes: int}
     */
    public function inspectEditionStorage(int $editionId): array
    {
        $subfolder = PrivateVault::validateSubfolder("editions/{$editionId}");
        $disk = Storage::disk(PrivateVault::DISK);

        if (! $disk->exists($subfolder)) {
            return ['stored_pages_count' => 0, 'storage_bytes' => 0];
        }

        $count = 0;
        $bytes = 0;

        foreach ($disk->files($subfolder) as $filePath) {
            if (preg_match('/^page-[1-9][0-9]*\.webp$/', basename($filePath))) {
                $count++;
                $bytes += (int) $disk->size($filePath);
            }
        }

        return [
            'stored_pages_count' => $count,
            'storage_bytes' => $bytes,
        ];
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 KB';
        }

        if ($bytes < 1024 * 1024) {
            return max(1, (int) round($bytes / 1024)).' KB';
        }

        return number_format($bytes / (1024 * 1024), 2, '.', '').' MB';
    }

    private function cleanupOrphanFiles(string $subfolder, int $totalPages): void
    {
        $disk = Storage::disk(PrivateVault::DISK);
        if (! $disk->exists($subfolder)) {
            return;
        }

        foreach ($disk->files($subfolder) as $filePath) {
            $base = basename($filePath);
            if (! preg_match('/^page-([1-9][0-9]*)\.webp$/', $base, $matches)) {
                $disk->delete($filePath);

                continue;
            }

            if ((int) $matches[1] > $totalPages) {
                $disk->delete($filePath);
            }
        }
    }
}
