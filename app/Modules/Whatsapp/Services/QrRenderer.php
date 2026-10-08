<?php

declare(strict_types=1);

namespace App\Modules\Whatsapp\Services;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use GdImage;
use InvalidArgumentException;

/**
 * Dessin des QR codes WhatsApp : lien wa.me, puis image SVG, PNG ou affiche PDF, toujours recalculés (rien n'est stocké).
 *
 * Lisibilité protégée : correction d'erreur maximale (niveau H), marge blanche de 4 modules, couleurs limitées à quatre teintes
 * sombres sur fond blanc, logo central limité à environ 5 % de la surface.
 */
final class QrRenderer
{
    /** Couleurs autorisées : toutes très sombres (contraste d'au moins 8:1 sur blanc). */
    public const COLORS = ['#0B1220', '#0F3D91', '#0F5132', '#4A1D96'];

    public const QUIET = 4;

    public const PNG_SIDE = 1000;

    /** Part du côté occupée par le logo (en modules, arrondi à l'impair) : ≈ 22 % du côté, soit ≈ 5 % de la surface. */
    private const LOGO_RATIO = 0.22;

    /**
     * Lien wa.me : numéro international en chiffres seulement, message pré-rempli encodé. Null si le numéro n'est pas exploitable.
     */
    public static function link(?string $phone, ?string $message): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        // E.164 : 8 à 15 chiffres, sans zéro initial.
        if (strlen($digits) < 8 || strlen($digits) > 15 || $digits[0] === '0') {
            return null;
        }

        $text = trim((string) $message);

        return 'https://wa.me/'.$digits.($text === '' ? '' : '?text='.rawurlencode($text));
    }

    /**
     * Modules du code : true = foncé.
     *
     * @return list<list<bool>>
     */
    public function matrix(string $data): array
    {
        $qr = new QRCode(new QROptions(['eccLevel' => EccLevel::H, 'addQuietzone' => false, 'outputBase64' => false]));
        $qr->addByteSegment($data);

        /** @var list<list<bool>> $modules */
        $modules = $qr->getQRMatrix()->getMatrix(true);

        return $modules;
    }

    public function svg(string $data, string $color, ?string $logoPng = null): string
    {
        $this->assertColor($color);
        $modules = $this->matrix($data);
        $size = count($modules);
        $total = $size + 2 * self::QUIET;
        [$from, $to] = $logoPng === null ? [PHP_INT_MAX, PHP_INT_MIN] : $this->logoSpan($size);

        $path = '';

        foreach ($modules as $y => $row) {
            foreach ($row as $x => $dark) {
                if ($dark && ! ($x >= $from && $x <= $to && $y >= $from && $y <= $to)) {
                    $path .= 'M'.($x + self::QUIET).' '.($y + self::QUIET).'h1v1h-1z';
                }
            }
        }

        $logo = '';

        if ($logoPng !== null) {
            $start = self::QUIET + $from + 0.5;
            $side = $to - $from;
            $logo = '<rect x="'.(self::QUIET + $from).'" y="'.(self::QUIET + $from).'" width="'.($side + 1).'" height="'.($side + 1).'" fill="#fff"/>'
                .'<image x="'.$start.'" y="'.$start.'" width="'.($side).'" height="'.($side).'" href="data:image/png;base64,'.base64_encode($logoPng).'"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$total.' '.$total.'" width="'.self::PNG_SIDE.'" height="'.self::PNG_SIDE.'" shape-rendering="crispEdges">'
            .'<rect width="'.$total.'" height="'.$total.'" fill="#fff"/>'
            .'<path fill="'.$color.'" d="'.$path.'"/>'
            .$logo
            .'</svg>';
    }

    public function png(string $data, string $color, ?string $logoPng = null, int $side = self::PNG_SIDE): string
    {
        $this->assertColor($color);
        $modules = $this->matrix($data);
        $size = count($modules);
        $total = $size + 2 * self::QUIET;
        [$from, $to] = $logoPng === null ? [PHP_INT_MAX, PHP_INT_MIN] : $this->logoSpan($size);

        $image = imagecreatetruecolor($side, $side);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));
        $ink = (int) imagecolorallocate($image, (int) hexdec(substr($color, 1, 2)), (int) hexdec(substr($color, 3, 2)), (int) hexdec(substr($color, 5, 2)));
        $edge = static fn (int $module): int => (int) floor($module * $side / $total);

        foreach ($modules as $y => $row) {
            foreach ($row as $x => $dark) {
                if ($dark && ! ($x >= $from && $x <= $to && $y >= $from && $y <= $to)) {
                    imagefilledrectangle($image, $edge($x + self::QUIET), $edge($y + self::QUIET), $edge($x + self::QUIET + 1) - 1, $edge($y + self::QUIET + 1) - 1, $ink);
                }
            }
        }

        if ($logoPng !== null) {
            $this->placeLogo($image, $logoPng, $edge($from + self::QUIET), $edge($to + self::QUIET + 1));
        }

        ob_start();
        imagepng($image, null, 9);

        return (string) ob_get_clean();
    }

    /**
     * Affiche imprimable (une page A4 ou A6) : nom de l'établissement, code en vectoriel, légende. Écrite à la main : un PDF d'une
     * page n'a besoin d'aucune bibliothèque.
     */
    public function pdf(string $data, string $color, string $title, string $caption, string $format = 'a4'): string
    {
        $this->assertColor($color);
        [$width, $height, $qrSide, $titleSize, $captionSize] = $format === 'a6' ? [298, 420, 190, 18, 13] : [595, 842, 360, 30, 20];
        $modules = $this->matrix($data);
        $size = count($modules);
        $total = $size + 2 * self::QUIET;
        $unit = $qrSide / $total;
        $left = ($width - $qrSide) / 2;
        $top = $height - ($format === 'a6' ? 110 : 190) - $qrSide;

        [$red, $green, $blue] = [hexdec(substr($color, 1, 2)) / 255, hexdec(substr($color, 3, 2)) / 255, hexdec(substr($color, 5, 2)) / 255];
        $stream = sprintf("%.3F %.3F %.3F rg\n", $red, $green, $blue);

        foreach ($modules as $y => $row) {
            foreach ($row as $x => $dark) {
                if ($dark) {
                    $stream .= sprintf("%.3F %.3F %.3F %.3F re f\n", $left + ($x + self::QUIET) * $unit, $top + $qrSide - ($y + self::QUIET + 1) * $unit, $unit + 0.02, $unit + 0.02);
                }
            }
        }

        $stream .= "0.06 0.1 0.2 rg\n";
        $stream .= $this->text($title, 'F2', $titleSize, $width, $top + $qrSide + ($format === 'a6' ? 50 : 80), $width - 40);
        $stream .= $this->text($caption, 'F1', $captionSize, $width, $top - ($format === 'a6' ? 34 : 56), $width - 40);

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$width} {$height}] /Contents 4 0 R /Resources << /Font << /F1 5 0 R /F2 6 0 R >> >> >>",
            '<< /Length '.strlen($stream)." >>\nstream\n".$stream.'endstream',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $index => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$body."\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf."trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    }

    private function assertColor(string $color): void
    {
        if (! in_array($color, self::COLORS, true)) {
            throw new InvalidArgumentException('Couleur non autorisée.');
        }
    }

    /**
     * Modules (premier, dernier) réservés au logo, au centre.
     *
     * @return array{int, int}
     */
    private function logoSpan(int $size): array
    {
        $span = (int) round($size * self::LOGO_RATIO);
        $span += $span % 2 === 0 ? 1 : 0;
        $from = intdiv($size - $span, 2);

        return [$from, $from + $span - 1];
    }

    private function placeLogo(GdImage $image, string $logoPng, int $start, int $end): void
    {
        $logo = @imagecreatefromstring($logoPng);

        if (! $logo instanceof GdImage) {
            return;
        }

        $white = (int) imagecolorallocate($image, 255, 255, 255);
        $box = $end - $start;
        imagefilledrectangle($image, $start, $start, $end - 1, $end - 1, $white);

        // Le logo garde une petite marge blanche autour de lui.
        $pad = (int) round($box * 0.08);
        imagecopyresampled($image, $logo, $start + $pad, $start + $pad, 0, 0, $box - 2 * $pad, $box - 2 * $pad, imagesx($logo), imagesy($logo));
    }

    /** Texte centré en Helvetica (largeur moyenne estimée), réduit si besoin pour tenir dans la page. */
    private function text(string $text, string $font, int $size, int $pageWidth, float $y, int $maxWidth): string
    {
        $encoded = (string) mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
        $factor = $font === 'F2' ? 0.58 : 0.52;
        $length = max(1, strlen($encoded));
        $fitted = min($size, $maxWidth / ($length * $factor));
        $x = ($pageWidth - $length * $fitted * $factor) / 2;
        $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $encoded);

        return sprintf("BT /%s %.2F Tf %.2F %.2F Td (%s) Tj ET\n", $font, $fitted, max(10, $x), $y, $escaped);
    }
}
