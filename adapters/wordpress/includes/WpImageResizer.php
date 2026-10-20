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
        // Plugins für moderne Formate (WebP/AVIF) dürfen die Ausgabe hier nicht umleiten:
        // Dateiname und Auslieferung erwarten JPEG.
        $noConversion = static fn (): array => [];
        add_filter('image_editor_output_format', $noConversion, PHP_INT_MAX);
        try {
            $saved = $editor->save($targetFile, 'image/jpeg');
        } finally {
            remove_filter('image_editor_output_format', $noConversion, PHP_INT_MAX);
        }
        if (is_wp_error($saved)) {
            throw new \RuntimeException($saved->get_error_message());
        }
        if (($saved['path'] ?? $targetFile) !== $targetFile && is_file($saved['path'])) {
            rename($saved['path'], $targetFile);
        }
        $size = $editor->get_size();
        return ['width' => (int) $size['width'], 'height' => (int) $size['height']];
    }

    public function resizeToPng(string $sourceFile, string $targetFile, int $maxEdge): array
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        wp_raise_memory_limit('image');
        $editor = wp_get_image_editor($sourceFile);
        if (is_wp_error($editor)) {
            throw new \RuntimeException($editor->get_error_message());
        }
        // Nie vergrößern: resize() mit $crop = false verkleinert nur; der Editor behält den Alphakanal beim PNG.
        $editor->resize($maxEdge, $maxEdge, false);
        // Wie bei resizeToJpeg: keine Umleitung in andere Formate, Dateiname und Auslieferung erwarten PNG.
        $noConversion = static fn (): array => [];
        add_filter('image_editor_output_format', $noConversion, PHP_INT_MAX);
        try {
            $saved = $editor->save($targetFile, 'image/png');
        } finally {
            remove_filter('image_editor_output_format', $noConversion, PHP_INT_MAX);
        }
        if (is_wp_error($saved)) {
            throw new \RuntimeException($saved->get_error_message());
        }
        if (($saved['path'] ?? $targetFile) !== $targetFile && is_file($saved['path'])) {
            rename($saved['path'], $targetFile);
        }
        $size = $editor->get_size();
        return ['width' => (int) $size['width'], 'height' => (int) $size['height']];
    }
}
