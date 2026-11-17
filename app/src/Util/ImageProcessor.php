<?php

declare(strict_types=1);

namespace Garage\Util;

use Garage\Domain\DomainError;
use GdImage;

/**
 * Aus DISPATCH (ADR-005), angepasst (ADR-012): kodiert jedes Bild neu als JPEG und behält nie die Eingangs-Bytes,
 * damit EXIF und GPS wegfallen. Schreibt `original.jpg` und je Breite eine Variante `<w>.jpg`.
 */
final class ImageProcessor
{
    private const ORIENTATION_STEPS = [
        2 => ['flip' => IMG_FLIP_HORIZONTAL, 'angle' => 0],
        3 => ['flip' => null, 'angle' => 180],
        4 => ['flip' => IMG_FLIP_VERTICAL, 'angle' => 0],
        5 => ['flip' => IMG_FLIP_HORIZONTAL, 'angle' => 90],
        6 => ['flip' => null, 'angle' => 270],
        7 => ['flip' => IMG_FLIP_HORIZONTAL, 'angle' => 270],
        8 => ['flip' => null, 'angle' => 90],
    ];

    /** @param array<string, mixed> $documentsConfig */
    public function __construct(private readonly array $documentsConfig)
    {
    }

    /** @return array{width: int, height: int, captured_on: string|null, files: list<string>, peak_bytes: int} */
    public function process(string $bytes, string $mime, string $targetDir): array
    {
        $size = getimagesizefromstring($bytes);
        if ($size === false) {
            throw new DomainError('type_rejected', 'Die Bilddatei ist beschädigt oder kein unterstütztes Bild.');
        }

        [$width, $height] = $size;
        $maxMegapixels = (int) $this->documentsConfig['max_megapixels'];
        // Vor dem Dekodieren prüfen: GD braucht je Pixel rund 4 Byte, ein Riesenbild sprengt sonst den Speicher.
        if ($width * $height > $maxMegapixels * 1_000_000) {
            throw new DomainError(
                'too_many_pixels',
                sprintf('Das Bild hat %.1f Megapixel; erlaubt sind höchstens %d. Bitte verkleinern.', $width * $height / 1_000_000, $maxMegapixels),
                ['width' => $width, 'height' => $height]
            );
        }

        $capturedOn = $mime === 'image/jpeg' ? $this->readCapturedOn($bytes) : null;
        $image = imagecreatefromstring($bytes);
        if ($image === false) {
            throw new DomainError('type_rejected', 'Die Bilddatei lässt sich nicht lesen.');
        }
        imagepalettetotruecolor($image);
        if ($mime === 'image/jpeg') {
            $image = $this->applyOrientation($image, $this->readOrientation($bytes));
        }
        if ($mime !== 'image/jpeg') {
            // Nur Formate mit Transparenz; ein JPEG-Bild nicht zu kopieren spart bei großen Fotos den halben Speicher.
            $image = $this->onWhite($image);
        }
        $image = $this->limitEdge($image, (int) $this->documentsConfig['max_edge']);
        $width = imagesx($image);
        $height = imagesy($image);

        $this->saveJpeg($image, $targetDir . '/original.jpg', (int) $this->documentsConfig['jpeg_quality']);
        $files = ['original.jpg'];
        foreach ((array) $this->documentsConfig['variant_widths'] as $variantEdge) {
            $files[] = $this->writeVariant($image, (int) $variantEdge, $targetDir);
        }
        $peak = memory_get_peak_usage(true);
        Log::info('Bild verarbeitet', ['peak' => $peak, 'mp' => round($width * $height / 1_000_000, 1)]);

        return ['width' => $width, 'height' => $height, 'captured_on' => $capturedOn, 'files' => $files, 'peak_bytes' => $peak];
    }

    /**
     * Seitenbild eines PDFs (vom Browser gerendert, E12): nur JPEG, längste Kante höchstens `max_edge`-Variante 1568,
     * genau eine Datei. Die Seite kommt aus einem Canvas und hat weder EXIF noch Drehung.
     *
     * @return array{width: int, height: int}
     */
    public function processPage(string $bytes, string $targetPath, int $maxEdge): array
    {
        $size = getimagesizefromstring($bytes);
        if ($size === false || $size[2] !== IMAGETYPE_JPEG) {
            throw new DomainError('invalid_value', 'Seitenbilder (pages[]) sind JPEG-Dateien.', ['field' => 'pages']);
        }
        if ($size[0] * $size[1] > (int) $this->documentsConfig['max_megapixels'] * 1_000_000) {
            throw new DomainError('too_many_pixels', 'Ein Seitenbild hat zu viele Pixel.', ['field' => 'pages']);
        }
        $image = imagecreatefromstring($bytes);
        if ($image === false) {
            throw new DomainError('invalid_value', 'Ein Seitenbild lässt sich nicht lesen.', ['field' => 'pages']);
        }
        $image = $this->limitEdge($image, $maxEdge);
        $this->saveJpeg($image, $targetPath, (int) $this->documentsConfig['variant_quality']);

        return ['width' => imagesx($image), 'height' => imagesy($image)];
    }

    /** Aufnahmedatum aus `DateTimeOriginal`; ohne Angabe, ohne Erweiterung oder bei Fehler `null`. */
    public function readCapturedOn(string $bytes): ?string
    {
        $exif = $this->readExif($bytes, 'EXIF');
        $raw = $exif['DateTimeOriginal'] ?? null;
        if (!is_string($raw) || preg_match('/^(\d{4}):(\d{2}):(\d{2}) /', $raw, $parts) !== 1) {
            return null;
        }
        if (!checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        return sprintf('%s-%s-%s', $parts[1], $parts[2], $parts[3]);
    }

    private function writeVariant(GdImage $image, int $edge, string $targetDir): string
    {
        $name = $edge . '.jpg';
        $longest = max(imagesx($image), imagesy($image));
        $scaled = $longest > $edge ? $this->scaleToEdge($image, $edge) : $image;
        $this->saveJpeg($scaled, $targetDir . '/' . $name, (int) $this->documentsConfig['variant_quality']);

        return $name;
    }

    private function limitEdge(GdImage $image, int $maxEdge): GdImage
    {
        if (max(imagesx($image), imagesy($image)) <= $maxEdge) {
            return $image;
        }

        return $this->scaleToEdge($image, $maxEdge);
    }

    private function scaleToEdge(GdImage $image, int $edge): GdImage
    {
        $isLandscape = imagesx($image) >= imagesy($image);
        $scaled = $isLandscape ? imagescale($image, $edge, -1, IMG_BICUBIC) : imagescale($image, (int) max(1, round(imagesx($image) * $edge / imagesy($image))), $edge, IMG_BICUBIC);
        if ($scaled === false) {
            throw new DomainError('type_rejected', 'Das Bild lässt sich nicht verkleinern.');
        }

        return $scaled;
    }

    /** Belege brauchen keine Transparenz; durchsichtige Flächen werden weiß, sonst werden sie in JPEG schwarz. */
    private function onWhite(GdImage $image): GdImage
    {
        $canvas = imagecreatetruecolor(imagesx($image), imagesy($image));
        if ($canvas === false) {
            throw new DomainError('type_rejected', 'Das Bild lässt sich nicht verarbeiten.');
        }
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));

        return $canvas;
    }

    private function saveJpeg(GdImage $image, string $path, int $quality): void
    {
        imageinterlace($image, true);
        if (!imagejpeg($image, $path, $quality)) {
            throw new DomainError('store_failed', 'Die Bilddatei lässt sich nicht schreiben.', ['file' => basename($path)]);
        }
    }

    private function readOrientation(string $bytes): int
    {
        $exif = $this->readExif($bytes, 'IFD0');

        return (int) ($exif['Orientation'] ?? 1);
    }

    /** @return array<string, mixed> */
    private function readExif(string $bytes, string $sections): array
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'gar');
        if ($tempFile === false) {
            return [];
        }

        try {
            file_put_contents($tempFile, $bytes);
            $exif = exif_read_data($tempFile, $sections);

            return is_array($exif) ? $exif : [];
        } catch (\Throwable) {
            return [];
        } finally {
            unlink($tempFile);
        }
    }

    private function applyOrientation(GdImage $image, int $orientation): GdImage
    {
        $steps = self::ORIENTATION_STEPS[$orientation] ?? null;
        if ($steps === null) {
            return $image;
        }
        if ($steps['flip'] !== null) {
            imageflip($image, $steps['flip']);
        }
        if ($steps['angle'] === 0) {
            return $image;
        }

        // imagerotate dreht gegen den Uhrzeigersinn.
        $rotated = imagerotate($image, $steps['angle'], 0);

        return $rotated === false ? $image : $rotated;
    }
}
