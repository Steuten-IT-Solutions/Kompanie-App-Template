<?php

declare(strict_types=1);

namespace KompanieApp\WordPress;

use KompanieApp\Core\App;

/**
 * Shortcodes für die Webseite, in den Farben der Kompanie.
 *
 * [kompanie_termine anzahl="10"]  Kommende öffentliche Termine aus der App.
 * [kompanie_galerie]              Galerie (Jahre → Alben → Bilder).
 */
final class Shortcodes
{
    public static function init(App $app): void
    {
        add_shortcode('kompanie_termine', static fn ($atts) => self::events($app, is_array($atts) ? $atts : []));
        add_shortcode('kompanie_galerie', static fn () => self::gallery($app));
    }

    /**
     * CSS bei jedem Shortcode mitgeben: Plugins (SEO, Open Graph) rendern Inhalte teils vorab
     * und verwerfen sie; eine „nur einmal“-Ausgabe ginge dann verloren. Doppelt schadet nicht.
     */
    private static function styles(App $app): string
    {
        $primary = $app->config->primaryColor();
        $accent = $app->config->accentColor();
        return '<style id="kompanie-app-css">
.ka{--ka-primary:' . esc_attr($primary) . ';--ka-accent:' . esc_attr($accent) . '}
.ka-termine{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:12px}
.ka-termin{display:flex;gap:16px;align-items:flex-start;padding:14px 16px;border:1px solid rgba(0,0,0,.12);border-left:4px solid var(--ka-primary);border-radius:10px}
.ka-datum{flex:none;width:56px;border-radius:8px;overflow:hidden;text-align:center;background:var(--ka-primary);color:#fff;display:flex;flex-direction:column}
.ka-monat{background:rgba(0,0,0,.18);font-size:.72rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;padding:2px 0}
.ka-tag{font-size:1.5rem;font-weight:700;line-height:1;padding:6px 0 8px}
.ka-text{min-width:0}
.ka-titel{margin:0 0 2px;font-size:1.1rem;line-height:1.3}
.ka-titel a{color:var(--ka-primary);text-decoration:none}.ka-titel a:hover{text-decoration:underline}
.ka-wann,.ka-ort{font-size:.92rem;opacity:.8}
.ka-beschreibung{margin:6px 0 0;font-size:.95rem}
.ka-jahr{margin:28px 0 12px;font-size:1.4rem;color:var(--ka-primary);border-bottom:2px solid var(--ka-accent);padding-bottom:4px}
.ka-alben{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px}
.ka-album{display:flex;flex-direction:column;text-decoration:none;color:inherit;border:1px solid rgba(0,0,0,.12);border-radius:10px;overflow:hidden}
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
</style>';
    }

    /** Das Skript schützt sich selbst vor doppelter Ausführung (window.kaLightbox). */
    private static function lightbox(): string
    {
        return '<script>
(function(){if(window.kaLightbox)return;window.kaLightbox=1;
document.addEventListener("click",function(e){var a=e.target.closest("[data-ka-lightbox]");if(!a)return;e.preventDefault();
var links=[].slice.call(document.querySelectorAll("[data-ka-lightbox]")),i=links.indexOf(a);
var box=document.createElement("div");box.className="ka-lb";box.setAttribute("role","dialog");box.setAttribute("aria-modal","true");
box.innerHTML="<img alt=\"\"><button class=\"ka-lb-close\" aria-label=\"Schließen\">✕</button><button class=\"ka-lb-prev\" aria-label=\"Vorheriges Bild\">‹</button><button class=\"ka-lb-next\" aria-label=\"Nächstes Bild\">›</button><div class=\"ka-lb-count\"></div>";
var img=box.querySelector("img"),cnt=box.querySelector(".ka-lb-count");
function show(n){i=(n+links.length)%links.length;img.src=links[i].href;cnt.textContent=(i+1)+" / "+links.length;}
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
}
