<?php

namespace App\Modules\LibraryProtection\Services;

use App\Modules\LibraryProtection\Exceptions\WatermarkUnavailable;
use GdImage;

/**
 * Marca de agua por persona, dibujada en el servidor en cada pedido de página.
 *
 * - En diagonal, en mosaico y muy tenue: nombre, correo a medias, fecha y código.
 * - En los márgenes, más legible: "Copia personal de …" al pie y el código en las dos
 *   esquinas superiores, para que recortar una esquina no lo borre.
 *
 * Usa solo GD (sin paquetes nuevos). Si GD, WebP o FreeType no están disponibles, lanza
 * WatermarkUnavailable y la página NO se entrega: nunca sale una página sin marca.
 */
final class PageWatermarker
{
    private const ANGLE = 30;

    /** Motivo por el que no se puede marcar, o null si todo está disponible. */
    public function unavailableReason(): ?string
    {
        if (! extension_loaded('gd') || ! function_exists('imagecreatefromstring')) {
            return 'La extensión GD de PHP no está instalada.';
        }

        $info = gd_info();
        if (empty($info['WebP Support']) || ! function_exists('imagewebp')) {
            return 'La extensión GD de PHP no tiene soporte WebP.';
        }

        if (empty($info['FreeType Support']) || ! function_exists('imagettftext')) {
            return 'La extensión GD de PHP no tiene soporte FreeType (texto con fuentes).';
        }

        if (! is_readable($this->font())) {
            return 'No se encuentra la fuente de la marca de agua ('.basename($this->font()).').';
        }

        return null;
    }

    /**
     * @throws WatermarkUnavailable
     */
    public function stamp(string $imageBytes, WatermarkStamp $stamp): string
    {
        $reason = $this->unavailableReason();
        if ($reason !== null) {
            throw new WatermarkUnavailable($reason);
        }

        $image = @imagecreatefromstring($imageBytes);
        if (! $image instanceof GdImage) {
            throw new WatermarkUnavailable('La página guardada no es una imagen válida.');
        }

        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }
        imagealphablending($image, true);

        $width = imagesx($image);
        $height = imagesy($image);

        $this->drawDiagonalTiles($image, $width, $height, $stamp->diagonalText());
        $this->drawMargins($image, $width, $height, $stamp);

        ob_start();
        $ok = imagewebp($image, null, $this->quality());
        $output = (string) ob_get_clean();
        unset($image);

        if (! $ok || $output === '') {
            throw new WatermarkUnavailable('No se pudo guardar la página con la marca de agua.');
        }

        return $output;
    }

    private function drawDiagonalTiles(GdImage $image, int $width, int $height, string $text): void
    {
        $size = max(11, (int) round($width * 0.021));
        // alpha 0 = opaco, 127 = transparente: ~15 % de opacidad, se lee sin tapar el libro.
        $color = imagecolorallocatealpha($image, 60, 72, 90, 108);

        $box = imagettfbbox($size, 0, $this->font(), $text);
        if ($box === false) {
            throw new WatermarkUnavailable('No se pudo medir el texto de la marca de agua.');
        }

        $textWidth = max(1, abs($box[2] - $box[0]));
        $radians = deg2rad(self::ANGLE);
        $stepX = (int) ceil($textWidth * cos($radians)) + $size * 4;
        $stepY = $size * 7;
        $rise = (int) ceil($textWidth * sin($radians));

        $row = 0;
        for ($y = 0; $y <= $height + $rise; $y += $stepY) {
            // Cada fila corrida media vuelta para que el mosaico no deje franjas limpias.
            $offset = ($row++ % 2) * (int) ($stepX / 2);
            for ($x = -$stepX + $offset; $x < $width; $x += $stepX) {
                imagettftext($image, $size, self::ANGLE, $x, $y, $color, $this->font(), $text);
            }
        }
    }

    private function drawMargins(GdImage $image, int $width, int $height, WatermarkStamp $stamp): void
    {
        $size = max(9, (int) round($width * 0.0105));
        $margin = max(6, (int) round($width * 0.03));
        $color = imagecolorallocatealpha($image, 70, 70, 70, 60);

        // Pie de página.
        imagettftext($image, $size, 0, $margin, $height - (int) round($margin * 0.6), $color, $this->font(), $stamp->footerText());

        // Código en las dos esquinas superiores.
        $code = 'Código '.$stamp->code;
        $top = $margin + $size;
        imagettftext($image, $size, 0, $margin, $top, $color, $this->font(), $code);

        $box = imagettfbbox($size, 0, $this->font(), $code);
        $codeWidth = $box === false ? 0 : abs($box[2] - $box[0]);
        imagettftext($image, $size, 0, max(0, $width - $margin - $codeWidth), $top, $color, $this->font(), $code);
    }

    private function font(): string
    {
        return (string) config('library.protection.watermark.font');
    }

    private function quality(): int
    {
        return max(40, min(95, (int) config('library.protection.watermark.quality', 80)));
    }
}
