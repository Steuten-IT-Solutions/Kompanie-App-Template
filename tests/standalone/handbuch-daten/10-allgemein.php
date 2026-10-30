<?php
// Fotos fürs Handbuch, Teil „allgemein“: mehr öffentliche Termine (Termine, Öffentliche Termine pflegen) und
// Geburtstage (Anstehende Geburtstage bei den Terminen, Mein Konto).
declare(strict_types=1);

use KompanieApp\Core\Domain\EventService;

$events = $app->events();
$events->create(EventService::input(['title' => 'Arbeitseinsatz am Vereinsheim', 'start_date' => $day(4), 'start_time' => '09:00', 'end_time' => '13:00', 'location' => 'Vereinsheim', 'description' => "Wir bereiten das Vereinsheim auf den Winter vor. Für Essen und Getränke ist gesorgt.\nBitte Arbeitshandschuhe mitbringen."]), $admin);
$events->create(EventService::input(['title' => 'Kranzniederlegung', 'start_date' => $day(24), 'start_time' => '10:30', 'location' => 'Ehrenmal am Kirchplatz', 'description' => 'Gemeinsames Gedenken mit anschließendem Frühschoppen.']), $admin);
$events->create(EventService::input(['title' => 'Konzert des Musikzugs', 'start_date' => $day(31), 'start_time' => '19:00', 'end_time' => '22:00', 'location' => 'Stadthalle', 'description' => 'Karten gibt es an der Abendkasse und im Vorverkauf.', 'url' => 'https://example.org/konzert']), $admin);

// Geburtstage: einer heute, einer in drei Tagen (mit Alter), die übrigen später im Jahr.
$birthday = static function (int $userId, int $inDays, ?int $year, bool $showAge) use ($app, $users): void {
    $date = $app->time()->today()->modify('+' . $inDays . ' days');
    // Ein 29. Februar mit Jahr geht nur in Schaltjahren: dann ohne Jahr.
    $leap = $date->format('m-d') === '02-29';
    $app->birthdays()->saveOwn($users->find($userId), ['month' => (int) $date->format('n'), 'day' => (int) $date->format('j'), 'year' => $leap ? null : $year, 'show_age' => $showAge]);
};
$birthday($m2, 0, null, false);
$birthday($m1, 3, 1981, true);
$birthday($manager, 45, 1975, false);
$birthday($admin, 120, null, false);
