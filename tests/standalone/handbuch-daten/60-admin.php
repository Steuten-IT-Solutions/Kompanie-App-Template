<?php
// Fotos fürs Handbuch, Teil „admin“: Einträge im Fehlerprotokoll (Einstellungen → Zustand und Updates) und eine vor
// ein paar Tagen heruntergeladene Datensicherung (sonst stünde die Ampel bei „Datensicherung“ auf Gelb).
declare(strict_types=1);

use KompanieApp\Core\Domain\StatusService;
use KompanieApp\Core\Telemetry\Code;
use KompanieApp\Core\Telemetry\Detail;

$app->settings->set(StatusService::BACKUP_SETTING, time() - 5 * 86400);
$log = $app->telemetry();
$calendarError = 'Der Kalender antwortet nicht (HTTP 404). Ist die iCal-Adresse noch gültig?';
$log->record(Code::CalendarFetch, Detail::of('ics', Detail::fromMessage($calendarError)), $calendarError);
$log->record(Code::CalendarFetch, Detail::of('ics', Detail::fromMessage($calendarError)), $calendarError);
$galleryError = 'Google Drive: Zeitüberschreitung beim Laden der Albenliste.';
$log->record(Code::GallerySync, Detail::fromMessage($galleryError), $galleryError);
