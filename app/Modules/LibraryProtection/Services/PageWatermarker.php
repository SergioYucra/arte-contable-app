<?php

namespace App\Modules\LibraryProtection\Services;

use App\Modules\LibraryProtection\Exceptions\WatermarkUnavailable;
use GdImage;

/**
 * Marca de agua por persona, dibujada en el servidor en cada pedido de página.
 *
 * - En los márgenes, legible: el código en las dos esquinas superiores y "Copia personal
 *   de …" al pie, para que recortar una esquina no lo borre.
 * - Cerca del centro, pequeño y tenue: "Código: …", en un hueco en blanco para no tapar el
 *   libro. Sobrevive a una captura recortada a los márgenes. (La diagonal en mosaico se quitó
 *   a pedido del revisor: estorbaba la lectura.)
 *
 * Usa solo GD (sin paquetes nuevos). Si GD, WebP o FreeType no están disponibles, lanza
 * WatermarkUnavailable y la página NO se entrega: nunca sale una página sin marca.
 */
final class PageWatermarker
{
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

        $this->drawMargins($image, $width, $height, $stamp);
        $this->drawCenterCode($image, $width, $height, $stamp->centerText());

        ob_start();
        $ok = imagewebp($image, null, $this->quality());
        $output = (string) ob_get_clean();
        unset($image);

        if (! $ok || $output === '') {
            throw new WatermarkUnavailable('No se pudo guardar la página con la marca de agua.');
        }

        return $output;
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

    /**
     * "Código: …" pequeño y tenue cerca del centro, en el hueco en blanco más cercano (entre
     * párrafos, junto a un título corto…): encima del texto no se leería y estorbaría.
     */
    private function drawCenterCode(GdImage $image, int $width, int $height, string $text): void
    {
        $size = max(10, (int) round($width * 0.013));
        // alpha 0 = opaco, 127 = transparente: ~22 % de opacidad, se lee si se busca y no llama la atención.
        $color = imagecolorallocatealpha($image, 70, 70, 70, 99);

        $box = imagettfbbox($size, 0, $this->font(), $text);
        if ($box === false) {
            throw new WatermarkUnavailable('No se pudo medir el texto de la marca de agua.');
        }

        $textWidth = abs($box[2] - $box[0]);
        $textHeight = abs($box[7] - $box[1]);
        [$left, $top] = $this->quietSpotNearCenter($image, $width, $height, $textWidth, $textHeight, (int) ceil($size * 0.6));

        imagettftext($image, $size, 0, $left - min($box[0], $box[6]), $top - min($box[5], $box[7]), $color, $this->font(), $text);
    }

    /**
     * Esquina superior izquierda del rectángulo en blanco (texto + aire alrededor) más cercano
     * al centro de la página, buscando en la franja del medio. Trabaja sobre una copia reducida
     * con sumas acumuladas, así que cuesta unos milisegundos. Si no hay hueco, el más limpio.
     * La posición depende solo de la página: es la misma para todos los lectores.
     *
     * @return array{0: int, 1: int}
     */
    private function quietSpotNearCenter(GdImage $image, int $width, int $height, int $boxWidth, int $boxHeight, int $padding): array
    {
        $centerLeft = (int) round(($width - $boxWidth) / 2);
        $centerTop = (int) round(($height - $boxHeight) / 2);

        $scale = max(1, intdiv($width, 200));
        $w = max(1, intdiv($width, $scale));
        $h = max(1, intdiv($height, $scale));
        $small = imagecreatetruecolor($w, $h);
        if ($small === false || ! imagecopyresampled($small, $image, 0, 0, 0, 0, $w, $h, $width, $height)) {
            return [$centerLeft, $centerTop];
        }

        // Tinta acumulada: $ink[(y)*(w+1)+x] = suma de "oscuridad" del rectángulo (0,0)-(x,y).
        $stride = $w + 1;
        $ink = array_fill(0, $stride * ($h + 1), 0);
        for ($y = 1; $y <= $h; $y++) {
            $row = 0;
            for ($x = 1; $x <= $w; $x++) {
                $rgb = imagecolorat($small, $x - 1, $y - 1);
                $row += 765 - ((($rgb >> 16) & 0xFF) + (($rgb >> 8) & 0xFF) + ($rgb & 0xFF));
                $ink[$y * $stride + $x] = $ink[($y - 1) * $stride + $x] + $row;
            }
        }
        unset($small);

        $bw = min($w, (int) ceil(($boxWidth + 2 * $padding) / $scale));
        $bh = min($h, (int) ceil(($boxHeight + 2 * $padding) / $scale));
        $cx = ($w - $bw) / 2;
        $cy = ($h - $bh) / 2;
        $fromX = (int) round($w * 0.06);
        $toX = max($fromX, (int) round($w * 0.94) - $bw);
        $fromY = (int) round($h * 0.3);
        $toY = max($fromY, (int) round($h * 0.7) - $bh);
        if ($toX + $bw > $w || $toY + $bh > $h) {
            // Página tan chica que el código no entra en la franja del medio.
            return [$centerLeft, $centerTop];
        }

        // Tinta de cada rectángulo candidato (sumas acumuladas: cuatro lecturas por rectángulo).
        $sums = [];
        $cleanest = PHP_INT_MAX;
        for ($y = $fromY; $y <= $toY; $y++) {
            $top = $y * $stride;
            $bottom = ($y + $bh) * $stride;
            for ($x = $fromX; $x <= $toX; $x++) {
                $sum = $ink[$bottom + $x + $bw] - $ink[$top + $x + $bw] - $ink[$bottom + $x] + $ink[$top + $x];
                $sums[$y][$x] = $sum;
                if ($sum < $cleanest) {
                    $cleanest = $sum;
                }
            }
        }

        // "En blanco" se mide contra el rectángulo más limpio de la franja, así sirve también con
        // fondos que no son blanco puro (escaneos, papel crema) y con el ruido del WebP.
        $tolerance = $cleanest + $bw * $bh * 4;

        $spot = [(int) round($cx), (int) round($cy)];
        $nearest = PHP_INT_MAX;
        foreach ($sums as $y => $row) {
            $dy = (($y - $cy) * 2) ** 2;
            foreach ($row as $x => $sum) {
                $distance = (int) (($x - $cx) ** 2 + $dy);
                if ($distance < $nearest && $sum <= $tolerance) {
                    [$spot, $nearest] = [[$x, $y], $distance];
                }
            }
        }
        unset($sums);

        $inkAt = fn (int $x, int $y): int => $ink[($y + $bh) * $stride + $x + $bw] - $ink[$y * $stride + $x + $bw]
            - $ink[($y + $bh) * $stride + $x] + $ink[$y * $stride + $x];

        // Al medio del hueco, no pegado a la línea de arriba o de abajo (si el hueco es grande,
        // como un final de capítulo, se mueve poco para no alejarse del centro).
        [$x, $up, $down] = [$spot[0], $spot[1], $spot[1]];
        while ($up > max(0, $spot[1] - $bh) && $inkAt($x, $up - 1) <= $tolerance) {
            $up--;
        }
        while ($down < min($h - $bh, $spot[1] + $bh) && $inkAt($x, $down + 1) <= $tolerance) {
            $down++;
        }
        $spot[1] = intdiv($up + $down, 2);

        return [
            min($width - $boxWidth, $spot[0] * $scale + $padding),
            min($height - $boxHeight, $spot[1] * $scale + $padding),
        ];
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
