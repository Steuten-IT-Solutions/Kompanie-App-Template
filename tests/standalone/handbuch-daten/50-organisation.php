<?php
// Fotos fürs Handbuch, Teil „organisation“: Inventar (mehrere Orte, Verlauf), Listen und Anwesenheitslisten.
declare(strict_types=1);

$adminUser = $users->find($admin);
$managerUser = $users->find($manager);

// Inventar: dasselbe Material an mehreren Orten, mit Umlagern und Änderungen im Verlauf.
$inv = $app->inventory();
$inv->create($adminUser, ['title' => 'Biertischgarnitur', 'quantity' => 12, 'unit' => 'Stück', 'location' => 'Vereinsheim', 'category' => 'Festbedarf', 'reason' => 'Inventur']);
$inv->create($adminUser, ['title' => 'Biertischgarnitur', 'quantity' => 6, 'unit' => 'Stück', 'location' => 'Garage Festplatz', 'category' => 'Festbedarf']);
$fahne = $inv->create($adminUser, ['title' => 'Fahne', 'quantity' => 1, 'unit' => 'Stück', 'location' => 'Vereinsheim', 'category' => 'Tradition', 'note' => 'Nur mit Handschuhen anfassen']);
$inv->create($adminUser, ['title' => 'Festzelt 6 × 12 m', 'quantity' => 1, 'unit' => 'Stück', 'location' => 'Garage Festplatz', 'category' => 'Festbedarf']);
$becher = $inv->create($adminUser, ['title' => 'Becher', 'quantity' => 400, 'unit' => 'Stück', 'location' => 'Vereinsheim', 'category' => 'Festbedarf', 'reason' => 'Lieferung']);
$inv->create($adminUser, ['title' => 'Grillkohle', 'quantity' => 12.5, 'unit' => 'kg', 'location' => 'Garage Festplatz', 'category' => 'Verbrauch']);
$inv->create($adminUser, ['title' => 'Uniformjacke', 'quantity' => 8, 'unit' => 'Stück', 'location' => 'Kleiderkammer', 'category' => 'Kleidung', 'note' => 'Größen 48–56']);
$inv->move($adminUser, $becher['id'], ['quantity' => 150, 'location' => 'Garage Festplatz', 'reason' => 'Für das Sommerfest']);
$inv->update($adminUser, $becher['id'], ['adjust' => -1]);
$inv->update($adminUser, $fahne['id'], ['note' => 'Nur mit Handschuhen anfassen, nach Gebrauch trocknen lassen', 'reason' => 'Hinweis ergänzt']);

// Listen: eine Anmeldeliste mit Mitgliedern, Kästchen und leeren Zeilen, dazu eine Unterschriftenliste.
$lists = $app->lists();
$ausflug = $lists->create($adminUser, ['title' => 'Ausflug – Anmeldung', 'subtitle' => 'Bitte bis Freitag eintragen', 'date' => $day(30)]);
$lists->syncMembers($adminUser, $ausflug['id'], true);
$ausflug = $lists->detail($adminUser, $ausflug['id']);
$lists->save($adminUser, $ausflug['id'], [
    'title' => $ausflug['title'],
    'subtitle' => $ausflug['subtitle'],
    'date' => $ausflug['date'],
    'orientation' => 'portrait',
    'columns' => [
        ['kind' => 'field', 'field' => 'nr', 'label' => '', 'width' => 'narrow', 'checkbox' => false],
        ['kind' => 'field', 'field' => 'last_name', 'label' => 'Name', 'width' => 'normal', 'checkbox' => false],
        ['kind' => 'field', 'field' => 'first_name', 'label' => '', 'width' => 'normal', 'checkbox' => false],
        ['kind' => 'blank', 'field' => null, 'label' => 'Bus', 'width' => 'narrow', 'checkbox' => true],
        ['kind' => 'blank', 'field' => null, 'label' => 'Bezahlt', 'width' => 'narrow', 'checkbox' => true],
        ['kind' => 'blank', 'field' => null, 'label' => 'Unterschrift', 'width' => 'wide', 'checkbox' => false],
    ],
    'rows' => array_merge(
        array_map(static fn (array $r): array => $r['kind'] === 'free' ? ['kind' => 'free', 'name' => $r['name']] : ['kind' => 'member', 'user_id' => $r['user_id']], $ausflug['rows']),
        [['kind' => 'free', 'name' => ''], ['kind' => 'free', 'name' => '']],
    ),
]);
$jhv = $lists->create($adminUser, ['title' => 'Jahreshauptversammlung – Unterschriften', 'date' => $day(-40)]);
$lists->syncMembers($adminUser, $jhv['id'], true);

// Anwesenheit: freie Termine mit Einträgen; für eine Liste sind Punkte schon gebucht.
$att = $app->attendance();
$att->save($adminUser, ['source' => 'free', 'title' => 'Jahreshauptversammlung', 'date' => $day(-40), 'entries' => [
    $admin => 'present', $manager => 'present', $m1 => 'present', $m2 => 'excused',
]]);
[$uebung] = $att->save($adminUser, ['source' => 'free', 'title' => 'Übungsabend', 'date' => $day(-24), 'entries' => [
    $admin => 'present', $manager => 'present', $m1 => 'absent', $m2 => 'present',
]]);
$app->scores()->givePoints(['user_ids' => [$manager, $m2], 'points' => 2, 'reason' => 'Übungsabend', 'date' => $day(-24), 'attendance_id' => $uebung['id']], $manager);
$att->save($managerUser, ['source' => 'free', 'title' => 'Arbeitsdienst Vereinsheim', 'date' => $day(-10), 'entries' => [
    $admin => 'excused', $manager => 'present', $m1 => 'present', $m2 => 'absent',
]]);
