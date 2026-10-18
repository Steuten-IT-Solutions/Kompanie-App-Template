<?php
// Als config.php kopieren und anpassen. Passwort-Hash erzeugen mit:
//   php -r 'echo password_hash("ein-langes-passwort", PASSWORD_DEFAULT), "\n";'
return [
    'password_hash' => '',
    // Besser außerhalb des Web-Verzeichnisses:
    'data_dir' => __DIR__ . '/data',
];
