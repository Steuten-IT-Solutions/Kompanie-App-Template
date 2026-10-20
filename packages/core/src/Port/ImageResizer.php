<?php

declare(strict_types=1);

namespace KompanieApp\Core\Port;

/** Bildgrößen erzeugen. WordPress: wp_get_image_editor. Standalone: GD. */
interface ImageResizer
{
    /**
     * Verkleinert ein Bild auf höchstens $maxEdge Pixel an der längeren Kante
     * (nie vergrößern), dreht es nach EXIF und speichert es als JPEG.
     *
     * @return array{width: int, height: int}
     * @throws \RuntimeException wenn das Bild nicht gelesen oder gespeichert werden kann
     */
    public function resizeToJpeg(string $sourceFile, string $targetFile, int $maxEdge, int $quality): array;

    /**
     * Verkleinert ein Bild (PNG, JPEG, WebP) auf höchstens $maxEdge Pixel an der längeren Kante (nie vergrößern,
     * Seitenverhältnis bleibt) und speichert es als PNG mit Alphakanal: Transparente Flächen bleiben transparent
     * (Abzeichen der Dienstgrade, Issue #17).
     *
     * @return array{width: int, height: int}
     * @throws \RuntimeException wenn das Bild nicht gelesen oder gespeichert werden kann
     */
    public function resizeToPng(string $sourceFile, string $targetFile, int $maxEdge): array;
}
