<?php
/**
 * Kompanie-App, eigenständige Version: Einstieg für alle Anfragen.
 * Läuft mit und ohne URL-Umschreibung (dann über index.php?r=/…).
 */

declare(strict_types=1);

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Die Kompanie-App braucht PHP 8.1 oder neuer. Auf diesem Server läuft PHP ' . PHP_VERSION . '. Die PHP-Version lässt sich meist im Kundenmenü des Hosters umstellen.';
    exit;
}

// Fehlermeldungen nie im Browser zeigen (auf manchem Webspace standardmäßig an); sie gehen ins Protokoll.
ini_set('display_errors', '0');

require __DIR__ . '/autoload.php';

(new KompanieApp\Standalone\Kernel(__DIR__))->handleHttp();
