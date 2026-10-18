<?php

declare(strict_types=1);

namespace KompanieApp\WordPress;

use KompanieApp\Core\Port\ImageResizer;

/** Bildgrößen über den Bildeditor von WordPress (Imagick oder GD). */
final class WpImageResizer implements ImageResizer
{
    public function resizeToJpeg(string $sourceFile, string $targetFile, int $maxEdge, int $quality): array
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        wp_raise_memory_limit('image');
        $editor = wp_get_image_editor($sourceFile);
        if (is_wp_error($editor)) {
            throw new \RuntimeException($editor->get_error_message());
        }
        if (method_exists($editor, 'maybe_exif_rotate')) {
            $editor->maybe_exif_rotate();
        }
        $editor->resize($maxEdge, $maxEdge, false);
        $editor->set_quality($quality);
        $saved = $editor->save($targetFile, 'image/jpeg');
        if (is_wp_error($saved)) {
            throw new \RuntimeException($saved->get_error_message());
        }
        $size = $editor->get_size();
        return ['width' => (int) $size['width'], 'height' => (int) $size['height']];
    }
}
