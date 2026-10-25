<?php
// Fotos fürs Handbuch, Teil „punkte“: Punktekatalog, Punkte, Strafen, Dienstgrade mit Abzeichen und Titel für die vier
// Testpersonen, damit Listen, Verlauf und Beförderungen realistisch gefüllt sind. Nur über die Dienste des Kerns.
// Punktestände am Ende: Vera 52 (Schwelle für den nächsten Dienstgrad erreicht), Max 22 (noch 3), Erika 7 (unter der
// Schwelle ihres Dienstgrads), Alex 120.
declare(strict_types=1);

$scores = $app->scores();
$ranks = $app->ranks();
$titles = $app->titles();
$actor = $app->users->find($admin) ?? throw new RuntimeException('Admin fehlt');

/* Punktekatalog (dazu „Teilnahme Sommerfest“ und „Aufbauhilfe“ aus e2e-setup.php) */
$katalog = [];
foreach (['Schießtraining' => 2, 'Arbeitsdienst' => 4, 'Jahreshauptversammlung' => 3, 'Fahnenwache' => 5, 'Altpapiersammlung' => 2] as $title => $points) {
    $katalog[$title] = $scores->createCatalogItem(['title' => $title, 'points' => $points]);
}
$scores->updateCatalogItem($katalog['Altpapiersammlung']['id'], ['active' => false]);

/* Dienstgrade mit Schwellen, die unteren fünf mit Abzeichen (PNG mit transparentem Hintergrund) */
$badge = static function (int $chevrons, bool $star): string {
    $img = imagecreatetruecolor(256, 256);
    imagesavealpha($img, true);
    imagealphablending($img, false);
    imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
    imagealphablending($img, true);
    imagesetthickness($img, 1);
    $gold = imagecolorallocate($img, 212, 160, 23);
    $edge = imagecolorallocate($img, 90, 60, 10);
    $light = imagecolorallocate($img, 255, 244, 214);
    // Schild als Grund, hell umrandet: gut sichtbar auf hellem und dunklem Hintergrund
    $shield = [128, 8, 236, 48, 220, 170, 128, 248, 36, 170, 20, 48];
    imagefilledpolygon($img, $shield, $light);
    $inner = [128, 22, 222, 56, 208, 164, 128, 232, 48, 164, 34, 56];
    imagefilledpolygon($img, $inner, $edge);
    for ($i = 0; $i < $chevrons; $i++) {
        $y = 120 + $i * 34 - ($chevrons - 1) * 17;
        imagefilledpolygon($img, [60, $y, 128, $y + 40, 196, $y, 196, $y + 20, 128, $y + 60, 60, $y + 20], $gold);
    }
    if ($chevrons === 0) {
        imagefilledrectangle($img, 64, 118, 192, 142, $gold);
    }
    if ($star) {
        $p = [];
        for ($k = 0; $k < 10; $k++) {
            $r = $k % 2 === 0 ? 26 : 11;
            $a = -M_PI / 2 + $k * M_PI / 5;
            $p[] = (int) round(128 + $r * cos($a));
            $p[] = (int) round(62 + $r * sin($a));
        }
        imagefilledpolygon($img, $p, $gold);
    }
    ob_start();
    imagepng($img);
    return (string) ob_get_clean();
};
$rank = [];
foreach ([
    ['Schütze', 0, 0, false],
    ['Gefreiter', 10, 1, false],
    ['Obergefreiter', 25, 2, false],
    ['Unteroffizier', 50, 3, false],
    ['Feldwebel', 100, 3, true],
    ['Hauptfeldwebel', 200, null, false],
] as [$title, $min, $chevrons, $star]) {
    $rank[$title] = $ranks->create(['title' => $title, 'min_points' => $min]);
    if ($chevrons !== null) {
        $file = ['name' => strtolower(str_replace('ü', 'ue', $title)) . '.png', 'data' => base64_encode($badge($chevrons, $star))];
        $ranks->uploadBadge($rank[$title]['id'], $file);
    }
}

/* Punkte (vor den Dienstgraden: Beförderungen prüfen die Schwelle) */
$give = static function (array $userIds, string $item, int $daysAgo) use ($scores, $katalog, $manager, $day): void {
    $scores->givePoints(['user_ids' => $userIds, 'catalog_id' => $katalog[$item]['id'], 'date' => $day(-$daysAgo)], $manager);
};
$give([$m1], 'Schießtraining', 90);
$give([$m1, $m2, $manager], 'Arbeitsdienst', 60);
$give([$m1, $manager], 'Jahreshauptversammlung', 45);
$give([$m2], 'Schießtraining', 40);
$give([$m1], 'Fahnenwache', 25);
$give([$m1], 'Schießtraining', 12);
$give([$manager], 'Fahnenwache', 100);
$scores->givePoints(['user_ids' => [$m1], 'points' => -2, 'reason' => 'Arbeitsdienst unentschuldigt verlassen', 'date' => $day(-35)], $manager);

/* Ersteinstufung (wie beim Anlegen mit Anfangsbestand) und Beförderungen */
foreach ([[$manager, 'Obergefreiter', 40, 400], [$admin, 'Feldwebel', 120, 700], [$m1, 'Schütze', 0, 0], [$m2, 'Schütze', 0, 0]] as [$uid, $title, $start, $daysAgo]) {
    $plan = $ranks->prepareInitial($actor, ['initial_points' => $start, 'initial_points_date' => $day(-$daysAgo), 'rank_id' => $rank[$title]['id']]);
    $ranks->applyInitial($actor, $uid, $plan);
}
$ranks->change($actor, $m1, $rank['Gefreiter']['id'], 'Fleißig beim Arbeitsdienst');
$ranks->change($actor, $m2, $rank['Gefreiter']['id'], '');
// Danach ein Abzug: Erika liegt unter der Schwelle ihres Dienstgrads (Ansicht „Kandidaten zum Degradieren“).
$scores->givePoints(['user_ids' => [$m2], 'points' => -4, 'reason' => 'Unentschuldigt beim Schießtraining gefehlt', 'date' => $day(-8)], $manager);

/* Strafen (dazu „Zu spät gekommen“ von Max aus e2e-setup.php) */
$penalty = static function (int $uid, int $cents, string $reason, int $daysAgo, bool $paid) use ($scores, $manager, $day): void {
    $row = $scores->givePenalty(['user_ids' => [$uid], 'amount_cents' => $cents, 'reason' => $reason, 'date' => $day(-$daysAgo)], $manager)[0];
    if ($paid) {
        $scores->setPenaltyStatus($row['id'], 'paid', $manager);
    }
};
$penalty($m1, 500, 'Handy klingelte bei der Versammlung', 45, true);
$penalty($m1, 150, 'Uniform unvollständig', 12, false);
$penalty($m2, 300, 'Ohne Entschuldigung gefehlt', 8, false);
$penalty($m2, 200, 'Zu spät gekommen', 70, true);
$penalty($manager, 100, 'Hut vergessen', 30, true);
$penalty($admin, 250, 'Zu spät gekommen', 20, false);

/* Titel */
$title = [];
foreach (['Ehrenmitglied', 'Schützenkönig', 'Fahnenträger', 'Jubilar 25 Jahre', 'Kronprinz 2024', 'Ehrenvorsitz'] as $t) {
    $title[$t] = $titles->create(['title' => $t]);
}
$titles->assign($m1, [$title['Schützenkönig']['id'], $title['Fahnenträger']['id']]);
$titles->assign($admin, [$title['Ehrenmitglied']['id']]);
$titles->assign($manager, [$title['Jubilar 25 Jahre']['id']]);
$titles->assign($m2, [$title['Kronprinz 2024']['id']]);
$titles->update($title['Kronprinz 2024']['id'], ['active' => false]);
