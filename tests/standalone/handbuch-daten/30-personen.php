<?php
// Fotos fürs Handbuch, Teil „personen“: weitere Mitglieder mit Geburtstag und Eintritt, Ämter mit Amtszeiten und ein
// paar Ehemalige, damit Mitgliederliste, Zeitstrahl und Liste der Ehemaligen wie in einer echten Kompanie aussehen.
// Punkte, Strafen, Dienstgrade und Titel legt der Teil „punkte“ an.
declare(strict_types=1);

use KompanieApp\Core\Auth\Roles;

$actor = $users->find($admin);
$year = (int) date('Y');

// Eintritt der Testbenutzer (Max Muster öffnet die Fotos der Seite eines Mitglieds).
$app->joinYears()->saveFor($actor, $m1, ['date' => '01.04.' . ($year - 12)]);
$app->joinYears()->saveFor($actor, $m2, ['date' => (string) ($year - 7)]);
$app->joinYears()->saveFor($actor, $manager, ['date' => (string) ($year - 21)]);
$app->birthdays()->saveFor($actor, $m1, ['day' => 14, 'month' => 3, 'year' => 1988]);

// Weitere Mitglieder: Name, Geburtstag (Tag, Monat, Jahr) oder null, Eintritt (Datum oder Jahr), Anzeige auf der eigenen Seite
$people = [
    ['Anna Schmitz', [2, 5, 1992], '15.05.' . ($year - 9), 'public'],
    ['Bernd Hoffmann', [23, 11, 1971], (string) ($year - 30), 'members'],
    ['Claudia Wagner', [8, 1, null], (string) ($year - 4), 'members'],
    ['Dieter Becker', [30, 7, 1958], (string) ($year - 41), 'public'],
    ['Frank Schulz', null, (string) ($year - 16), 'members'],
    ['Jana Koch', [19, 9, 1999], '01.03.' . ($year - 2), 'members'],
    ['Lukas Richter', [4, 12, 2001], (string) ($year - 1), 'none'],
    ['Martin Klein', [11, 6, 1983], (string) ($year - 18), 'members'],
    ['Sabine Wolf', [27, 2, 1979], (string) ($year - 22), 'public'],
    ['Thomas Neumann', null, null, 'members'],
];
$ids = ['Max Muster' => $m1, 'Erika Beispiel' => $m2];
foreach ($people as [$name, $birthday, $joined, $profile]) {
    $login = strtolower(str_replace(' ', '.', $name));
    $id = $users->add($name, $login, '', 'test1234', Roles::MEMBER);
    $ids[$name] = $id;
    $user = $users->find($id);
    if ($birthday !== null) {
        $app->birthdays()->saveOwn($user, ['day' => $birthday[0], 'month' => $birthday[1], 'year' => $birthday[2], 'visible' => true, 'show_age' => $birthday[2] !== null, 'profile' => $profile]);
    }
    if ($joined !== null) {
        $app->joinYears()->saveOwn($user, ['date' => $joined, 'profile' => $profile]);
    }
}

// Ehemalige: angelegt und dann als ehemalig eingetragen (wie in der Verwaltung)
$former = [
    ['Heinrich Albers', ['reason' => 'deceased', 'joined_on' => '', 'founder' => true, 'left_on' => '14.03.' . ($year - 3)]],
    ['Ursula Brandt', ['reason' => 'left', 'joined_on' => (string) ($year - 25), 'founder' => false, 'left_on' => '31.12.' . ($year - 6)]],
    ['Gerd Fischer', ['reason' => 'left', 'joined_on' => '01.04.' . ($year - 38), 'founder' => false, 'left_on' => (string) ($year - 10)]],
    ['Walter Krüger', ['reason' => 'deceased', 'joined_on' => (string) ($year - 52), 'founder' => false, 'left_on' => (string) ($year - 8)]],
    ['Monika Lange', ['reason' => 'other', 'joined_on' => (string) ($year - 14), 'founder' => false, 'left_on' => (string) ($year - 2)]],
];
foreach ($former as [$name, $data]) {
    $login = strtolower(str_replace(['ü', ' '], ['ue', '.'], $name));
    $id = $users->add($name, $login, '', bin2hex(random_bytes(12)), Roles::MEMBER);
    $ids[$name] = $id;
    $app->formerMembers()->mark($actor, $id, $data);
}

// Ämter: zwei Gruppen, laufende und frühere Amtszeiten, auch Personen ohne Konto und eine Ehrung
$offices = $app->offices();
$board = $offices->createGroup('Vorstand');
$company = $offices->createGroup('Kompanie');
$office = static fn (string $title, array $group): int => (int) $offices->createOffice(['title' => $title, 'group_id' => $group['id']])['id'];
$who = static fn (string ...$names): array => array_map(static fn (string $n): array => isset($ids[$n]) ? ['user_id' => $ids[$n]] : ['name' => $n], $names);

$chair = $office('1. Vorsitzender', $board);
$offices->createTerm($chair, ['kind' => 'period', 'start_year' => $year - 6, 'holders' => $who('Bernd Hoffmann')]);
$offices->createTerm($chair, ['kind' => 'period', 'start_year' => $year - 18, 'end_year' => $year - 6, 'holders' => $who('Gerd Fischer')]);
$offices->createTerm($chair, ['kind' => 'period', 'start_year' => $year - 31, 'end_year' => $year - 18, 'holders' => $who('Heinrich Albers')]);
$offices->createTerm($chair, ['kind' => 'period', 'start_year' => $year - 44, 'end_year' => $year - 31, 'holders' => $who('Josef Hermes')]);
$offices->createTerm($chair, ['kind' => 'point', 'start_year' => $year - 6, 'note' => 'Ehrenvorsitz', 'holders' => $who('Gerd Fischer')]);

$cash = $office('Kassierer', $board);
$offices->createTerm($cash, ['kind' => 'period', 'start_year' => $year - 9, 'holders' => $who('Sabine Wolf')]);
$offices->createTerm($cash, ['kind' => 'period', 'start_year' => $year - 22, 'end_year' => $year - 9, 'holders' => $who('Ursula Brandt')]);

$writer = $office('Schriftführer', $board);
$offices->createTerm($writer, ['kind' => 'period', 'start_year' => $year - 3, 'holders' => $who('Anna Schmitz')]);
$offices->createTerm($writer, ['kind' => 'period', 'start_year' => $year - 12, 'end_year' => $year - 3, 'holders' => $who('Martin Klein')]);

$captain = $office('Hauptmann', $company);
$offices->createTerm($captain, ['kind' => 'period', 'start_year' => $year - 8, 'holders' => $who('Dieter Becker')]);
$offices->createTerm($captain, ['kind' => 'period', 'start_year' => $year - 20, 'end_year' => $year - 8, 'holders' => $who('Walter Krüger')]);

$flag = $office('Fahnenoffiziere', $company);
$offices->createTerm($flag, ['kind' => 'period', 'start_year' => $year - 5, 'holders' => $who('Max Muster', 'Frank Schulz')]);
$offices->createTerm($flag, ['kind' => 'period', 'start_year' => $year - 12, 'end_year' => $year - 5, 'note' => 'Unter Hauptmann Krüger', 'holders' => $who('Martin Klein', 'Frank Schulz')]);
