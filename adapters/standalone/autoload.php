<?php
/**
 * Autoloader ohne Composer. Im fertigen Paket liegt der Kern unter core/,
 * während der Entwicklung unter packages/core/src im Repo. PHPMailer (Mailversand, Issue #172) liegt im Paket unter
 * src/lib/PHPMailer (scripts/package.php), während der Entwicklung unter vendor/ (composer install).
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    static $roots = null;
    if ($roots === null) {
        $core = is_dir(__DIR__ . '/core') ? __DIR__ . '/core/' : dirname(__DIR__, 2) . '/packages/core/src/';
        $roots = [
            'KompanieApp\\Core\\' => $core,
            'KompanieApp\\Standalone\\' => __DIR__ . '/src/',
            'PHPMailer\\PHPMailer\\' => is_dir(__DIR__ . '/src/lib/PHPMailer') ? __DIR__ . '/src/lib/PHPMailer/' : dirname(__DIR__, 2) . '/vendor/phpmailer/phpmailer/src/',
        ];
    }
    foreach ($roots as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});
