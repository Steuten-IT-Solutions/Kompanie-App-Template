<?php

declare(strict_types=1);

namespace KompanieApp\Core\Port;

/** Bildgrößen erzeugen. WordPress: wp_get_image_editor. Standalone: GD oder Imagick. */
interface ImageResizer
{
    /**
     * Verkleinert ein Bild auf höchstens $maxEdge Pixel an der längeren Kante.
     *
     * @return array{width: int, height: int}
     * @throws \RuntimeException wenn das Bild nicht gelesen werden kann
     */
    public function resize(string $sourceFile, string $targetFile, int $maxEdge, int $quality = 82): array;
}
