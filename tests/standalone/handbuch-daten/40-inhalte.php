<?php
// Fotos fürs Handbuch, Teil „inhalte“: Dokumente mit Kategorien, eine Nachricht am Schwarzen Brett, laufende und
// beendete Umfragen mit Antworten. Die Nachricht und laufende Umfragen erscheinen im Banner auf allen Seiten (auch auf
// den Fotos anderer Teile): deshalb nur eine kurze Nachricht, und admin, verwalter und mitglied1 haben auf alle
// laufenden Umfragen schon geantwortet (mitglied2 nur auf eine: Mit ihm entstehen die Fotos zum Antworten).
declare(strict_types=1);

/** Kleine echte PDF-Datei (eine Seite A4, Überschrift und einige Zeilen Text). */
$pdf = static function (string $title, array $lines): string {
    $esc = static fn (string $s): string => strtr(mb_convert_encoding($s, 'Windows-1252', 'UTF-8'), ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
    $content = "BT /F1 20 Tf 60 770 Td (" . $esc($title) . ") Tj ET\n";
    $y = 730;
    foreach ($lines as $line) {
        $content .= "BT /F1 12 Tf 60 $y Td (" . $esc($line) . ") Tj ET\n";
        $y -= 20;
    }
    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "endstream",
    ];
    $out = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $i => $obj) {
        $offsets[] = strlen($out);
        $out .= ($i + 1) . " 0 obj\n" . $obj . "\nendobj\n";
    }
    $xref = strlen($out);
    $out .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    foreach ($offsets as $o) {
        $out .= sprintf("%010d 00000 n \n", $o);
    }
    return $out . "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
};

/** Kleines PNG: Lageplan mit Wiese, Zelt und Weg. */
$png = static function (): string {
    $img = imagecreatetruecolor(800, 560);
    imagefill($img, 0, 0, (int) imagecolorallocate($img, 196, 226, 170));
    imagefilledrectangle($img, 0, 470, 800, 520, (int) imagecolorallocate($img, 200, 200, 200));
    imagefilledrectangle($img, 120, 120, 420, 320, (int) imagecolorallocate($img, 245, 245, 245));
    imagerectangle($img, 120, 120, 420, 320, (int) imagecolorallocate($img, 90, 90, 90));
    imagefilledrectangle($img, 520, 160, 700, 300, (int) imagecolorallocate($img, 178, 34, 34));
    imagestring($img, 5, 230, 210, 'Festzelt', (int) imagecolorallocate($img, 40, 40, 40));
    imagestring($img, 5, 560, 222, 'Ausschank', (int) imagecolorallocate($img, 255, 255, 255));
    imagestring($img, 5, 340, 488, 'Zufahrt', (int) imagecolorallocate($img, 40, 40, 40));
    ob_start();
    imagepng($img);
    imagedestroy($img);
    return (string) ob_get_clean();
};

/** Word-Datei (.docx) mit einem Absatz. */
$docx = static function (string $text): string {
    $file = tempnam(sys_get_temp_dir(), 'docx');
    $zip = new ZipArchive();
    $zip->open($file, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
    $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>' . htmlspecialchars($text) . '</w:t></w:r></w:p></w:body></w:document>');
    $zip->close();
    $bytes = (string) file_get_contents($file);
    unlink($file);
    return $bytes;
};

// Dokumente
$docs = $app->documents();
$satzung = $docs->createCategory('Satzung und Ordnungen');
$protokolle = $docs->createCategory('Protokolle');
$formulare = $docs->createCategory('Formulare');
$doc = static function (string $title, ?array $category, int $days, string $name, string $bytes, string $visibility = 'members') use ($docs, $day, $admin): void {
    $docs->create([
        'title' => $title,
        'category_id' => $category['id'] ?? null,
        'date' => $day($days),
        'visibility' => $visibility,
        'file' => ['name' => $name, 'data' => base64_encode($bytes)],
    ], $admin);
};
$doc('Satzung', $satzung, -420, 'satzung.pdf', $pdf('Satzung', [
    '§ 1 Name und Sitz',
    'Der Verein führt den Namen, der im Vereinsregister eingetragen ist.',
    '§ 2 Zweck',
    'Zweck des Vereins ist die Pflege des Brauchtums und der Geselligkeit.',
    '§ 3 Mitgliedschaft',
    'Mitglied kann jede Person werden, die den Zweck des Vereins unterstützt.',
]));
$doc('Geschäftsordnung', $satzung, -300, 'geschaeftsordnung.pdf', $pdf('Geschäftsordnung', ['Regeln für Versammlungen und Sitzungen.']));
$doc('Protokoll Jahreshauptversammlung', $protokolle, -35, 'protokoll-jhv.pdf', $pdf('Protokoll der Jahreshauptversammlung', [
    'Beginn: 19:30 Uhr',
    'TOP 1: Begrüßung und Feststellung der Beschlussfähigkeit',
    'TOP 2: Bericht des Vorstands',
    'TOP 3: Bericht der Kasse',
    'TOP 4: Wahlen',
    'TOP 5: Verschiedenes',
    'Ende: 21:45 Uhr',
]));
$doc('Protokoll Vorstandssitzung', $protokolle, -90, 'protokoll-vorstand.pdf', $pdf('Protokoll der Vorstandssitzung', ['Planung des Jahresfests.']));
$doc('Kassenbericht', $protokolle, -40, 'kassenbericht.pdf', $pdf('Kassenbericht', ['Einnahmen und Ausgaben des Vorjahres.']), 'managers');
$doc('Aufnahmeantrag', $formulare, -200, 'aufnahmeantrag.docx', $docx('Aufnahmeantrag'));
$doc('Lageplan Festplatz', null, -10, 'lageplan.png', $png());

// Schwarzes Brett: eine kurze Nachricht (steht 24 Stunden, also während aller Fotos).
$app->board()->create($app->users->find($admin), 'Treffen am Samstag beginnt erst um 10 Uhr.');

// Umfragen
$polls = $app->polls();
$user = static fn (int $id) => $app->users->find($id);
$in = static fn (int $days, string $time = '20:00'): string => $day($days) . 'T' . $time;
$grill = $polls->create($user($admin), [
    'title' => 'Grillfest am Samstag oder Sonntag?',
    'description' => 'Wir wollen gemeinsam grillen. Welcher Tag passt dir besser?',
    'type' => 'single',
    'options' => ['Samstag', 'Sonntag'],
    'anonymous' => false,
    'results' => 'after_vote',
    'ends_at' => $in(6),
]);
[$sa, $so] = array_column($grill['options'], 'id');
$polls->vote($user($admin), $grill['id'], ['option_ids' => [$sa]]);
$polls->vote($user($manager), $grill['id'], ['option_ids' => [$so]]);
$polls->vote($user($m1), $grill['id'], ['option_ids' => [$sa]]);
$polls->vote($user($m2), $grill['id'], ['option_ids' => [$so]]);

$ausflug = $polls->create($user($manager), [
    'title' => 'Welche Ausflugsziele interessieren dich?',
    'type' => 'multiple',
    'options' => ['Freizeitpark', 'Schifffahrt', 'Brauereibesichtigung', 'Wanderung'],
    'anonymous' => true,
    'results' => 'after_end',
    'ends_at' => $in(10),
]);
[$park, $schiff, $brauerei, $wandern] = array_column($ausflug['options'], 'id');
$polls->vote($user($admin), $ausflug['id'], ['option_ids' => [$schiff, $brauerei]]);
$polls->vote($user($manager), $ausflug['id'], ['option_ids' => [$schiff]]);
$polls->vote($user($m1), $ausflug['id'], ['option_ids' => [$park, $schiff, $wandern]]);

$jhv = $polls->create($user($admin), [
    'title' => 'Welcher Termin für die Jahreshauptversammlung?',
    'type' => 'single',
    'options' => ['Freitag, 19:30 Uhr', 'Samstag, 15:00 Uhr'],
    'anonymous' => false,
    'results' => 'after_vote',
]);
[$fr, $sam] = array_column($jhv['options'], 'id');
$polls->vote($user($admin), $jhv['id'], ['option_ids' => [$fr]]);
$polls->vote($user($manager), $jhv['id'], ['option_ids' => [$fr]]);
$polls->vote($user($m1), $jhv['id'], ['option_ids' => [$sam]]);
$polls->vote($user($m2), $jhv['id'], ['option_ids' => [$fr]]);
$polls->close($user($admin), $jhv['id']);

$wuensche = $polls->create($user($admin), [
    'title' => 'Was wünschst du dir für das nächste Vereinsjahr?',
    'type' => 'text',
    'anonymous' => true,
    'results' => 'after_vote',
]);
$polls->vote($user($manager), $wuensche['id'], ['text' => 'Mehr gemeinsame Ausflüge']);
$polls->vote($user($m1), $wuensche['id'], ['text' => 'Ein Sommerfest für Familien']);
$polls->vote($user($m2), $wuensche['id'], ['text' => 'Regelmäßige Stammtische']);
$polls->close($user($admin), $wuensche['id']);
