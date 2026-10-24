<?php

declare(strict_types=1);

namespace KompanieApp\WordPress;

use KompanieApp\Core\App;

/**
 * Shortcodes für die Webseite, in den Farben der Kompanie.
 *
 * [kompanie_termine anzahl="10"]  Kommende öffentliche Termine aus der App.
 * [kompanie_galerie]              Galerie (Jahre → Alben → Bilder).
 * [kompanie_aemter gruppe="…"]    Ämter mit Inhabern und Chronik (optional nur eine Gruppe).
 * [kompanie_mitglieder]           Mitglieder (ohne Anwärter) als Bildergalerie (Foto mit Namen, Großansicht beim Antippen).
 */
final class Shortcodes
{
    /** Einstellung: Bild aus der Mediathek (ID) für Personen ohne Foto in [kompanie_mitglieder]; leer = Anfangsbuchstaben. */
    public const MEMBERS_PLACEHOLDER = 'members_placeholder';

    public static function init(App $app): void
    {
        add_shortcode('kompanie_termine', static fn ($atts) => self::events($app, is_array($atts) ? $atts : []));
        add_shortcode('kompanie_galerie', static fn () => self::gallery($app));
        add_shortcode('kompanie_aemter', static fn ($atts) => self::offices($app, is_array($atts) ? $atts : []));
        add_shortcode('kompanie_mitglieder', static fn () => self::members($app));
    }

    /**
     * CSS bei jedem Shortcode mitgeben: Plugins (SEO, Open Graph) rendern Inhalte teils vorab
     * und verwerfen sie; eine „nur einmal“-Ausgabe ginge dann verloren. Doppelt schadet nicht.
     * Schrift in der Textfarbe der Webseite (hell und dunkel lesbar, auch mit dunklem Modus des Themes);
     * die Kompaniefarbe nur für Flächen und Leisten, Linien aus currentColor.
     * Abstände der Listen mit !important: Themes rücken Listen im Inhalt mit spezifischeren Regeln ein (Astra:
     * .entry-content ul{padding-left:20px}); die Galerie stünde sonst nicht mittig.
     */
    private static function styles(App $app): string
    {
        $primary = $app->config->primaryColor();
        $accent = $app->config->accentColor();
        return '<style id="kompanie-app-css">
.ka{--ka-primary:' . esc_attr($primary) . ';--ka-accent:' . esc_attr($accent) . ';--ka-line:color-mix(in srgb,currentColor 15%,transparent)}
.ka-termine{list-style:none;margin:0!important;padding:0!important;display:flex;flex-direction:column;gap:12px}
.ka-termin{display:flex;gap:16px;align-items:flex-start;padding:14px 16px;border:1px solid var(--ka-line);border-left:4px solid var(--ka-primary);border-radius:10px}
.ka-datum{flex:none;width:56px;border-radius:8px;overflow:hidden;text-align:center;background:var(--ka-primary);color:#fff;display:flex;flex-direction:column}
.ka-monat{background:rgba(0,0,0,.18);font-size:.72rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;padding:2px 0}
.ka-tag{font-size:1.5rem;font-weight:700;line-height:1;padding:6px 0 8px}
.ka-text{min-width:0}
.ka-titel{margin:0 0 2px;font-size:1.1rem;line-height:1.3}
.ka-titel a{color:inherit;text-decoration:underline;text-decoration-color:var(--ka-accent);text-underline-offset:3px}.ka-titel a:hover{text-decoration-thickness:2px}
.ka-wann,.ka-ort{font-size:.92rem;opacity:.8}
.ka-beschreibung{margin:6px 0 0;font-size:.95rem}
.ka-jahr{margin:28px 0 12px;font-size:1.4rem;border-bottom:2px solid var(--ka-accent);padding-bottom:4px}
.ka-alben{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px}
.ka-album{display:flex;flex-direction:column;text-decoration:none;color:inherit;border:1px solid var(--ka-line);border-radius:10px;overflow:hidden}
.ka-album img,.ka-platzhalter{display:block;width:100%;aspect-ratio:4/3;object-fit:cover;background:rgba(0,0,0,.06);border-bottom:3px solid var(--ka-accent)}
.ka-album-titel{font-weight:700;padding:10px 12px 0}.ka-album-anzahl{font-size:.85rem;opacity:.7;padding:0 12px 10px}
.ka-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:8px}
.ka-foto{display:block;aspect-ratio:1;overflow:hidden;border-radius:6px;background:rgba(0,0,0,.06)}
.ka-foto img{width:100%;height:100%;object-fit:cover;display:block}
.ka-lb{position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.92);display:flex;align-items:center;justify-content:center}
.ka-lb img{max-width:96vw;max-height:88vh;object-fit:contain}
.ka-lb button{position:absolute;background:rgba(255,255,255,.15);color:#fff;border:0;border-radius:999px;width:48px;height:48px;font-size:1.6rem;cursor:pointer}
.ka-lb .ka-lb-close{top:16px;right:16px}.ka-lb .ka-lb-prev{left:12px}.ka-lb .ka-lb-next{right:12px}
.ka-lb-count{position:absolute;bottom:14px;left:0;right:0;text-align:center;color:#fff;font-size:.9rem}
.ka-gruppe{margin:28px 0 12px;font-size:1.4rem;border-bottom:2px solid var(--ka-accent);padding-bottom:4px}
.ka-aemter{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px;align-items:start}
.ka-amt{border:1px solid var(--ka-line);border-top:4px solid var(--ka-primary);border-radius:10px;padding:14px 16px}
.ka-amt-titel{margin:0 0 10px;font-size:1.1rem}
.ka-inhaber{list-style:none;margin:0!important;padding:0!important;display:flex;flex-direction:column;gap:8px}
.ka-person{display:flex;gap:10px;align-items:center}
.ka-bild{flex:none;width:56px;height:56px;border-radius:50%;object-fit:cover;object-position:50% 0;background:var(--ka-primary);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.95rem}
.ka-name{font-weight:700}.ka-klein{display:block;font-size:.85rem;opacity:.75}
.ka-chronik{margin-top:10px}.ka-chronik summary{cursor:pointer;font-size:.92rem}
.ka-chronik ol{list-style:none;margin:8px 0 0;padding:0 0 0 12px;border-left:2px solid var(--ka-accent);display:flex;flex-direction:column;gap:6px;font-size:.92rem}
.ka-jahre{font-weight:700;margin-right:6px}
.ka-mitglieder{list-style:none;margin:0!important;padding:0!important;display:grid;grid-template-columns:repeat(8,minmax(0,1fr));gap:8px}
@media(max-width:976px){.ka-mitglieder{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(max-width:767px){.ka-mitglieder{grid-template-columns:repeat(2,minmax(0,1fr))}}
.ka-mitglied{position:relative;margin:0!important;overflow:hidden;box-shadow:0 18px 40px -10px rgba(0,0,0,.2);transition:box-shadow .2s}
.ka-mitglied:hover,.ka-mitglied:focus-within{box-shadow:none}
.ka-mitglied a{display:block;color:inherit}
.ka-mitglied img,.ka-mitglied-leer{display:block;width:100%;height:auto;aspect-ratio:2/3;object-fit:cover}
.ka-mitglied-leer{display:flex;align-items:center;justify-content:center;background:var(--ka-primary);color:#fff;font-size:2rem;font-weight:700}
.ka-mitglied-name{position:absolute;left:0;right:0;bottom:0;padding:8px;background:rgba(0,0,0,.75);color:#fff;text-align:center;line-height:1.3}
</style>';
    }

    /** Das Skript schützt sich selbst vor doppelter Ausführung (window.kaLightbox). */
    private static function lightbox(): string
    {
        return '<script>
(function(){if(window.kaLightbox)return;window.kaLightbox=1;
document.addEventListener("click",function(e){var a=e.target.closest("[data-ka-lightbox]");if(!a)return;e.preventDefault();
var g=a.getAttribute("data-ka-lightbox"),links=[].slice.call(document.querySelectorAll("[data-ka-lightbox]")).filter(function(l){return l.getAttribute("data-ka-lightbox")===g}),i=links.indexOf(a);
var box=document.createElement("div");box.className="ka-lb";box.setAttribute("role","dialog");box.setAttribute("aria-modal","true");
box.innerHTML="<img alt=\"\"><button class=\"ka-lb-close\" aria-label=\"Schließen\">✕</button><button class=\"ka-lb-prev\" aria-label=\"Vorheriges Bild\">‹</button><button class=\"ka-lb-next\" aria-label=\"Nächstes Bild\">›</button><div class=\"ka-lb-count\"></div>";
var img=box.querySelector("img"),cnt=box.querySelector(".ka-lb-count");
function show(n){i=(n+links.length)%links.length;img.src=links[i].href;var t=links[i].getAttribute("data-ka-titel");img.alt=t||"";cnt.textContent=(t?t+" · ":"")+(i+1)+" / "+links.length;}
function close(){document.removeEventListener("keydown",key);box.remove();a.focus();}
function key(ev){if(ev.key==="Escape")close();if(ev.key==="ArrowLeft")show(i-1);if(ev.key==="ArrowRight")show(i+1);}
box.addEventListener("click",function(ev){if(ev.target===box||ev.target.classList.contains("ka-lb-close"))close();});
box.querySelector(".ka-lb-prev").onclick=function(){show(i-1)};box.querySelector(".ka-lb-next").onclick=function(){show(i+1)};
var x0=null;box.addEventListener("touchstart",function(ev){x0=ev.touches[0].clientX},{passive:true});
box.addEventListener("touchend",function(ev){if(x0===null)return;var dx=ev.changedTouches[0].clientX-x0;if(Math.abs(dx)>40)show(dx<0?i+1:i-1);x0=null;});
document.addEventListener("keydown",key);document.body.appendChild(box);show(i);box.querySelector(".ka-lb-close").focus();});})();
</script>';
    }

    private static function events(App $app, array $atts): string
    {
        if (!$app->features()->enabled('events')) {
            return '';
        }
        $limit = max(1, min(100, (int) ($atts['anzahl'] ?? 10)));
        $events = $app->events()->upcoming($limit);
        $html = self::styles($app);
        if ($events === []) {
            return $html . '<p class="ka">Derzeit sind keine Termine eingetragen.</p>';
        }
        $tz = wp_timezone();
        $html .= '<ol class="ka ka-termine">';
        foreach ($events as $ev) {
            $start = new \DateTimeImmutable($ev['start_date'], $tz);
            $title = esc_html($ev['title']);
            if ($ev['url'] !== '') {
                $external = !str_starts_with($ev['url'], home_url());
                $title = sprintf('<a href="%s"%s>%s</a>', esc_url($ev['url']), $external ? ' target="_blank" rel="noopener"' : '', $title);
            }
            $html .= '<li class="ka-termin">';
            $html .= sprintf(
                '<div class="ka-datum" aria-hidden="true"><span class="ka-monat">%s</span><span class="ka-tag">%s</span></div>',
                esc_html(wp_date('M', $start->getTimestamp())),
                esc_html($start->format('j')),
            );
            $html .= '<div class="ka-text"><h4 class="ka-titel">' . $title . '</h4>';
            $html .= '<div class="ka-wann">' . esc_html(self::when($ev)) . '</div>';
            if ($ev['location'] !== '') {
                $html .= '<div class="ka-ort">Ort: ' . esc_html($ev['location']) . '</div>';
            }
            if ($ev['description'] !== '') {
                $html .= '<p class="ka-beschreibung">' . nl2br(esc_html($ev['description'])) . '</p>';
            }
            $html .= '</div></li>';
        }
        return $html . '</ol>';
    }

    /** „Sa., 27. Juni 2027 · 19:00–23:00 Uhr“ bzw. „25.–28. Juni 2027“. */
    public static function when(array $ev): string
    {
        $tz = wp_timezone();
        $start = (new \DateTimeImmutable($ev['start_date'], $tz))->getTimestamp();
        $text = wp_date('D., j. F Y', $start);
        if ($ev['end_date']) {
            $end = (new \DateTimeImmutable($ev['end_date'], $tz))->getTimestamp();
            $same = wp_date('Y-m', $start) === wp_date('Y-m', $end);
            $text = ($same ? wp_date('j.', $start) : wp_date('j. F', $start)) . '–' . wp_date('j. F Y', $end);
        }
        if ($ev['start_time']) {
            $text .= ' · ' . $ev['start_time'] . ($ev['end_time'] ? '–' . $ev['end_time'] : '') . ' Uhr';
        }
        return $text;
    }

    private static function gallery(App $app): string
    {
        if (!$app->features()->enabled('gallery')) {
            return '';
        }
        $member = (bool) $app->authContext->currentUser()?->isMember();
        $albumId = isset($_GET['album']) ? absint($_GET['album']) : 0; // phpcs:ignore WordPress.Security.NonceVerification -- reine Navigation
        $html = self::styles($app);
        if ($albumId) {
            $album = $app->gallery()->album($albumId, $member);
            $back = esc_url(remove_query_arg('album'));
            if (!$album) {
                return $html . '<p class="ka"><a href="' . $back . '">‹ Zur Galerie</a></p><p>Dieses Album gibt es nicht (mehr).</p>';
            }
            $html .= self::lightbox() . '<div class="ka"><p><a href="' . $back . '">‹ Zur Galerie</a></p>';
            $html .= '<h2>' . esc_html($album['title']) . ' <small>' . esc_html($album['year']) . '</small></h2><div class="ka-grid">';
            foreach ($album['photos'] as $ph) {
                $html .= sprintf(
                    '<a class="ka-foto" href="%s" data-ka-lightbox><img src="%s" alt="%s" loading="lazy" width="400" height="400" /></a>',
                    esc_url($ph['large']),
                    esc_url($ph['thumb']),
                    esc_attr($album['title']),
                );
            }
            return $html . '</div></div>';
        }
        $years = $app->gallery()->overview($member);
        if ($years === []) {
            return $html . '<p class="ka">Noch keine Bilder in der Galerie.</p>';
        }
        $html .= '<div class="ka">';
        foreach ($years as $year) {
            $html .= '<h2 class="ka-jahr">' . esc_html($year['year']) . '</h2><div class="ka-alben">';
            foreach ($year['albums'] as $al) {
                $html .= sprintf(
                    '<a class="ka-album" href="%s">%s<span class="ka-album-titel">%s</span><span class="ka-album-anzahl">%s</span></a>',
                    esc_url(add_query_arg('album', $al['id'])),
                    $al['cover'] ? '<img src="' . esc_url($al['cover']) . '" alt="" loading="lazy" />' : '<span class="ka-platzhalter"></span>',
                    esc_html($al['title']),
                    esc_html(sprintf('%d %s', $al['count'], $al['count'] === 1 ? 'Bild' : 'Bilder')),
                );
            }
            $html .= '</div>';
        }
        return $html . '</div>';
    }

    private static function offices(App $app, array $atts): string
    {
        if (!$app->features()->enabled('offices')) {
            return '';
        }
        // Immer die Sicht eines Gastes: Fotos nur von Personen, die auch in der öffentlichen Mitgliederliste stehen.
        $overview = $app->offices()->overview(null);
        $photos = $app->memberPhotos()->urls(480);
        // Typografische Anführungszeichen (gruppe=„…“ bzw. ”…”, z. B. aus Word oder dem Editor) erkennt WordPress
        // nicht als Anführungszeichen: Sie landen im Wert, und ein Leerzeichen teilt ihn (der Rest kommt als
        // Attribute ohne Namen). Deshalb wieder zusammensetzen und die Zeichen entfernen.
        $wanted = (string) ($atts['gruppe'] ?? '');
        foreach ($atts as $k => $v) {
            if (is_int($k) && $wanted !== '') {
                $wanted .= ' ' . $v;
            }
        }
        $wanted = trim((string) preg_replace('/^[\s"\'\p{Pi}\p{Pf}„‚]+|[\s"\'\p{Pi}\p{Pf}„‚]+$/u', '', $wanted));
        $groups = [];
        foreach ($overview['groups'] as $g) {
            if ($wanted === '' || mb_strtolower($g['title']) === mb_strtolower($wanted)) {
                $groups[$g['id']] = $g['title'];
            }
        }
        $byGroup = [];
        foreach ($overview['offices'] as $o) {
            if ($o['group_id'] === null ? $wanted === '' : isset($groups[$o['group_id']])) {
                $byGroup[$o['group_id'] ?? 0][] = $o;
            }
        }
        $html = self::styles($app);
        if ($byGroup === []) {
            return $html . '<p class="ka">Noch keine Ämter eingetragen.</p>';
        }
        $html .= '<div class="ka">';
        // Überschrift nur, wenn mehrere Gruppen erscheinen; mit gruppe="…" steht sie meist schon auf der Seite.
        $headings = count($byGroup) > 1;
        foreach ($byGroup as $groupId => $offices) {
            if ($headings) {
                $html .= '<h3 class="ka-gruppe">' . esc_html($groups[$groupId] ?? 'Weitere Ämter') . '</h3>';
            }
            $html .= '<div class="ka-aemter">';
            foreach ($offices as $o) {
                $html .= self::office($o, $photos);
            }
            $html .= '</div>';
        }
        return $html . '</div>';
    }

    /** Ein Amt: laufende Amtszeiten (sonst die letzte) mit Fotos, darunter die übrigen als aufklappbare Chronik. */
    private static function office(array $o, array $photos): string
    {
        $current = array_values(array_filter($o['terms'], static fn (array $t): bool => $t['current']));
        $shown = $current !== [] ? $current : array_slice($o['terms'], 0, 1);
        $rest = array_slice($o['terms'], count($shown));
        $html = '<section class="ka-amt"><h4 class="ka-amt-titel">' . esc_html($o['title']) . '</h4>';
        if ($shown === []) {
            return $html . '<p class="ka-klein">Noch nicht besetzt.</p></section>';
        }
        $html .= '<ul class="ka-inhaber">';
        foreach ($shown as $t) {
            foreach ($t['holders'] as $h) {
                // Nur wer öffentlich ein Foto hat (photo_url), bekommt die größere Fassung (schärfer im Kreis).
                $url = ($h['photo_url'] ?? null) !== null ? ($photos[$h['user_id'] ?? 0] ?? $h['photo_url']) : null;
                $html .= '<li class="ka-person">' . self::photo($h['name'], $url, 56)
                    . '<span><span class="ka-name">' . esc_html($h['name']) . '</span>'
                    . '<span class="ka-klein">' . esc_html(self::years($t)) . ($t['note'] !== '' && $t['note'] !== null ? ' · ' . esc_html($t['note']) : '') . '</span></span></li>';
            }
        }
        $html .= '</ul>';
        if ($rest !== []) {
            $since = min(array_column($o['terms'], 'start_year'));
            $html .= '<details class="ka-chronik"><summary>' . esc_html(sprintf('Chronik seit %d', $since)) . '</summary><ol>';
            foreach ($rest as $t) {
                $names = implode(', ', array_map(static fn (array $h): string => $h['name'], $t['holders']));
                $html .= '<li><span class="ka-jahre">' . esc_html(self::years($t)) . '</span>' . esc_html($names)
                    . ($t['note'] !== '' && $t['note'] !== null ? '<span class="ka-klein">' . esc_html($t['note']) . '</span>' : '') . '</li>';
            }
            $html .= '</ol></details>';
        }
        return $html . '</section>';
    }

    /** „2007–2020“, „seit 2020“ (läuft), „2025“ (einzelnes Jahr), wie in der App (Aemter.tsx, termYears). */
    private static function years(array $t): string
    {
        if ($t['kind'] === 'point' || $t['end_year'] === $t['start_year']) {
            return (string) $t['start_year'];
        }
        return $t['end_year'] === null ? 'seit ' . $t['start_year'] : $t['start_year'] . '–' . $t['end_year'];
    }

    /** Wie eine Bildergalerie: Fotos im Hochformat mit dem Namen als Balken, Großansicht beim Antippen. */
    private static function members(App $app): string
    {
        if (!$app->features()->enabled('directory')) {
            return '';
        }
        // Nur Mitglieder: Anwärter (nur interner Kalender) stehen in der App, aber nicht auf der Webseite.
        $members = $app->members()->directory(true);
        $collator = class_exists(\Collator::class) ? new \Collator('de_DE') : null;
        usort($members, static fn (array $a, array $b): int => $collator !== null ? (int) $collator->compare($a['name'], $b['name']) : strcasecmp($a['name'], $b['name']));
        $html = self::styles($app);
        if ($members === []) {
            return $html . '<p class="ka">Noch keine Mitglieder eingetragen.</p>';
        }
        // Die Liste der API hat nur die kleine Vorschau (128 Pixel); für die Galerie die größeren Fassungen. Alte Fotos
        // ohne 1024er (Issue #109): Großansicht mit der 480er, dann ohne srcset (dieselbe Datei, keine falsche Breite).
        $medium = $app->memberPhotos()->urls(480);
        $large = $app->memberPhotos()->urls(1024);
        $placeholder = self::membersPlaceholder($app);
        $html .= self::lightbox() . '<ul class="ka ka-mitglieder">';
        foreach ($members as $m) {
            $name = esc_html($m['name']);
            $caption = '<span class="ka-mitglied-name">' . $name . '</span>';
            if (isset($medium[$m['id']], $large[$m['id']])) {
                $srcset = $large[$m['id']] !== $medium[$m['id']]
                    ? sprintf(' srcset="%1$s 320w, %2$s 683w" sizes="(min-width: 977px) 13vw, (min-width: 768px) 33vw, 50vw"', esc_url($medium[$m['id']]), esc_url($large[$m['id']]))
                    : '';
                $html .= sprintf(
                    '<li class="ka-mitglied"><a href="%1$s" data-ka-lightbox="mitglieder" data-ka-titel="%2$s"><img src="%3$s"%5$s alt="%2$s" loading="lazy" width="320" height="480" />%4$s</a></li>',
                    esc_url($large[$m['id']]),
                    esc_attr($m['name']),
                    esc_url($medium[$m['id']]),
                    $caption,
                    $srcset,
                );
            } elseif ($placeholder !== '') {
                // Ohne Großansicht: Das Platzhalterbild zeigt nicht die Person.
                $html .= '<li class="ka-mitglied">' . $placeholder . $caption . '</li>';
            } else {
                $html .= '<li class="ka-mitglied"><span class="ka-mitglied-leer" aria-hidden="true">' . esc_html(self::initials($m['name'])) . '</span>' . $caption . '</li>';
            }
        }
        return $html . '</ul>';
    }

    /**
     * Platzhalterbild aus den Einstellungen als <img> (mit srcset der Mediathek), leer ohne Einstellung oder wenn das Bild
     * nicht mehr in der Mediathek ist; dann bleiben die Anfangsbuchstaben.
     */
    private static function membersPlaceholder(App $app): string
    {
        $id = (int) $app->settings->get(self::MEMBERS_PLACEHOLDER, 0);
        if ($id <= 0 || !wp_attachment_is_image($id)) {
            return '';
        }
        return wp_get_attachment_image($id, 'medium_large', false, [
            'class' => 'ka-mitglied-platzhalter',
            'alt' => '',
            'loading' => 'lazy',
            'sizes' => '(min-width: 977px) 13vw, (min-width: 768px) 33vw, 50vw',
        ]);
    }

    /** Foto rund (Hochformat oben ausgerichtet), ohne Foto die Initialen auf der Kompaniefarbe. */
    private static function photo(string $name, ?string $url, int $size): string
    {
        if ($url) {
            return sprintf('<img class="ka-bild" src="%s" alt="" loading="lazy" width="%2$d" height="%2$d" />', esc_url($url), $size);
        }
        return '<span class="ka-bild" aria-hidden="true">' . esc_html(self::initials($name)) . '</span>';
    }

    private static function initials(string $name): string
    {
        $initials = '';
        foreach (array_slice(preg_split('/\s+/u', trim($name)) ?: [], 0, 2) as $part) {
            $initials .= mb_strtoupper(mb_substr($part, 0, 1));
        }
        return $initials;
    }
}
