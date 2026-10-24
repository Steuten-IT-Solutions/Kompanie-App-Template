<?php
// Fotos fürs Handbuch: Einrichtung abgeschlossen (sonst stünde oben auf jeder Seite „Willkommen! Einrichtung starten“).
// Das Foto des Einrichtungsassistenten öffnet ihn direkt über seine Adresse.
declare(strict_types=1);

$app->settings->set(KompanieApp\Core\Http\Api::SETUP_SETTING, true);
