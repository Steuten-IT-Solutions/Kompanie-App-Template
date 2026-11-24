<?php

declare(strict_types=1);

namespace KompanieApp\Core\Website;

use KompanieApp\Core\App;
use KompanieApp\Core\I18n\I18n;

/**
 * Blocks as HTML for the website (Issue #273), from the data Blocks::forViewer() returns for a guest. Mirrors the App's
 * BlocksView (packages/frontend/src/blocks/BlocksView.tsx) without scripts: links into the App (#/…) point to the App's
 * address, the countdown shows the time left when the page is loaded. Unknown types are skipped.
 */
final class BlockHtml
{
    public function __construct(private readonly App $app)
    {
    }

    /** @param list<array<string, mixed>> $blocks */
    public function blocks(array $blocks): string
    {
        $out = '';
        foreach ($blocks as $b) {
            $html = $this->block($b);
            if ($html !== '') {
                $out .= '<div class="block block-type-' . self::e((string) ($b['type'] ?? '')) . '">' . $html . '</div>';
            }
        }
        return $out;
    }

    /** @param array<string, mixed> $b */
    public function block(array $b): string
    {
        return match ($b['type'] ?? '') {
            'text' => (string) ($b['html'] ?? ''),
            'countdown' => $this->countdown($b),
            'button' => $this->button($b),
            'events' => $this->events($b),
            'contacts' => $this->contacts($b),
            'notice' => $this->notice($b),
            'schedule' => $this->schedule($b),
            'facts' => $this->facts($b),
            'album' => $this->album($b),
            'document' => $this->document($b),
            'sponsors' => $this->sponsors($b),
            'map' => $this->map($b),
            'image' => $this->image($b),
            'divider' => ($b['style'] ?? '') === 'space' ? '<div class="block-space" aria-hidden="true"></div>' : '<hr>',
            // Polls are for members; a guest never gets one (PollBlock). Unknown types: data of a newer version.
            default => '',
        };
    }

    /** @param array<string, mixed> $b */
    private function countdown(array $b): string
    {
        $title = (string) (($b['title'] ?? '') !== '' ? $b['title'] : ($b['event_title'] ?? ''));
        $start = is_string($b['starts_at'] ?? null) ? new \DateTimeImmutable($b['starts_at']) : null;
        $end = is_string($b['ends_at'] ?? null) ? new \DateTimeImmutable($b['ends_at']) : null;
        $now = (new \DateTimeImmutable('@' . $this->app->time()->timestamp()))->setTimezone($this->app->time()->timezone());
        $phase = $start === null ? 'after' : ($now < $start ? 'before' : ($end !== null && $now < $end ? 'running' : 'after'));
        $head = $title !== '' ? '<h2>' . self::e($title) . '</h2>' : '';
        if ($phase === 'before' && $start !== null) {
            $start = $start->setTimezone($this->app->time()->timezone());
            $parts = '';
            foreach (self::remaining($now, $start) as [$value, $unit]) {
                $parts .= '<span class="block-countdown-part"><span class="block-countdown-value">' . $value . '</span><span class="block-countdown-unit">' . $unit . '</span></span>';
            }
            $withTime = $start->format('H:i') !== '00:00';
            return $head . '<div class="block-countdown-parts">' . $parts . '</div>'
                . '<p class="muted">' . self::e(self::date($start) . ($withTime ? ', ' . I18n::t('{time} Uhr', ['time' => $start->format('H:i')]) : '')) . '</p>';
        }
        $text = $phase === 'running' ? (string) (($b['running_text'] ?? '') !== '' ? $b['running_text'] : $title) : (($b['after'] ?? 'text') === 'hide' ? '' : (string) ($b['after_text'] ?? ''));
        return $text === '' ? '' : $head . '<p>' . self::e($text) . '</p>';
    }

    /**
     * Time left, always two values like the App: from one day days and hours, below hours and minutes, below one hour
     * minutes and seconds (counted by the calendar in the installation's time zone).
     *
     * @return list<array{0: int, 1: string}>
     */
    public static function remaining(\DateTimeImmutable $now, \DateTimeImmutable $start): array
    {
        $d = $now->diff($start);
        $days = (int) $d->days;
        $daysLeft = static fn (int $n): array => [$n, I18n::tn('Tag', 'Tage', $n)];
        $hours = static fn (int $n): array => [$n, I18n::tn('Stunde', 'Stunden', $n)];
        $minutes = static fn (int $n): array => [$n, I18n::tn('Minute', 'Minuten', $n)];
        if ($days >= 1) {
            return [$daysLeft($days), $hours($d->h)];
        }
        if ($d->h >= 1) {
            return [$hours($d->h), $minutes($d->i)];
        }
        return [$minutes($d->i), [$d->s, I18n::tn('Sekunde', 'Sekunden', $d->s)]];
    }

    /** @param array<string, mixed> $b */
    private function button(array $b): string
    {
        $url = (string) ($b['url'] ?? '');
        $external = str_starts_with($url, 'https://');
        return '<p class="block-button"><a class="site-btn' . (($b['style'] ?? '') === 'secondary' ? ' site-btn-secondary' : '') . '" href="' . self::e($this->href($url)) . '"'
            . ($external ? ' target="_blank" rel="noopener noreferrer"' : '') . '>' . self::e((string) ($b['label'] ?? '')) . '</a></p>';
    }

    /** @param array<string, mixed> $b */
    private function events(array $b): string
    {
        $out = self::heading($b);
        $events = is_array($b['events'] ?? null) ? $b['events'] : [];
        if ($events === []) {
            $out .= '<p class="muted">' . self::e(I18n::t('Zurzeit steht nichts an.')) . '</p>';
        } else {
            $out .= '<ul class="block-events-list">';
            foreach ($events as $e) {
                $out .= '<li><span class="block-events-when">' . self::e(self::eventWhen($e)) . '</span><span><strong>' . self::e((string) $e['title']) . '</strong>'
                    . ((string) ($e['location'] ?? '') !== '' ? '<span class="muted"> · ' . self::e((string) $e['location']) . '</span>' : '') . '</span></li>';
            }
            $out .= '</ul>';
        }
        return $out . '<p><a href="' . self::e($this->href('#/termine')) . '">' . self::e(I18n::t('Alle Termine')) . '</a></p>';
    }

    /** @param array<string, mixed> $b */
    private function contacts(array $b): string
    {
        $offices = is_array($b['offices'] ?? null) ? $b['offices'] : [];
        if ($offices === []) {
            return self::heading($b) . '<p class="muted">' . self::e(I18n::t('Zurzeit keine Ansprechpartner.')) . '</p>';
        }
        $out = self::heading($b) . '<ul class="block-contacts-list">';
        foreach ($offices as $o) {
            // Eigener Name der Sprache vor der deutschen Einzahl, sonst wie im Zeitstrahl: Einzahl, sonst Bezeichnung
            $office = $this->app->translations()->text('office', (string) ($o['id'] ?? ''))
                ?? (string) (($o['holder_title'] ?? '') !== '' && $o['holder_title'] !== null ? $o['holder_title'] : $o['title']);
            $holders = is_array($o['holders'] ?? null) && $o['holders'] !== [] ? $o['holders'] : [null];
            foreach ($holders as $h) {
                $photo = is_array($h) && is_string($h['photo_url'] ?? null)
                    ? '<img src="' . self::e($h['photo_url']) . '" alt="" width="56" height="56" loading="lazy">'
                    : '<span class="block-contact-initials" aria-hidden="true">' . self::e(is_array($h) ? self::initials((string) $h['name']) : '–') . '</span>';
                $out .= '<li class="block-contact">' . $photo . '<span><span class="muted">' . self::e($office) . '</span><strong>'
                    . self::e(is_array($h) ? (string) $h['name'] : I18n::t('zurzeit nicht besetzt')) . '</strong></span></li>';
            }
        }
        return $out . '</ul>';
    }

    /** @param array<string, mixed> $b */
    private function notice(array $b): string
    {
        $tone = in_array($b['tone'] ?? '', ['info', 'success', 'warning'], true) ? (string) $b['tone'] : 'info';
        return '<aside class="block-notice block-notice-' . $tone . '" role="note">'
            . ((string) ($b['title'] ?? '') !== '' ? '<strong>' . self::e((string) $b['title']) . '</strong>' : '')
            . '<p>' . self::e((string) ($b['text'] ?? '')) . '</p></aside>';
    }

    /** @param array<string, mixed> $b */
    private function schedule(array $b): string
    {
        $out = self::heading($b) . '<dl class="block-schedule-list">';
        foreach (is_array($b['items'] ?? null) ? $b['items'] : [] as $i) {
            $out .= '<div><dt>' . self::e((string) ($i['time'] ?? '')) . '</dt><dd>' . self::e((string) ($i['text'] ?? '')) . '</dd></div>';
        }
        return $out . '</dl>';
    }

    /** @param array<string, mixed> $b */
    private function facts(array $b): string
    {
        $out = self::heading($b) . '<ul class="block-facts-list">';
        foreach (is_array($b['items'] ?? null) ? $b['items'] : [] as $i) {
            $out .= '<li><strong>' . self::e((string) ($i['value'] ?? '')) . '</strong><span>' . self::e((string) ($i['label'] ?? '')) . '</span></li>';
        }
        return $out . '</ul>';
    }

    /** @param array<string, mixed> $b */
    private function album(array $b): string
    {
        $a = $b['album'] ?? null;
        if (!is_array($a)) {
            return '';
        }
        $link = self::e($this->href('#/galerie/' . (int) $a['id']));
        $out = '<h2>' . self::e((string) $a['title']) . '</h2><a class="block-album-grid" href="' . $link . '" aria-label="' . self::e(I18n::t('Album {title} öffnen', ['title' => (string) $a['title']])) . '">';
        foreach (is_array($a['photos'] ?? null) ? $a['photos'] : [] as $p) {
            $size = (int) ($p['width'] ?? 0) > 0 && (int) ($p['height'] ?? 0) > 0 ? ' width="' . (int) $p['width'] . '" height="' . (int) $p['height'] . '"' : '';
            $out .= '<img src="' . self::e((string) $p['thumb']) . '" alt="" loading="lazy"' . $size . '>';
        }
        return $out . '</a><p><a href="' . $link . '">' . self::e(I18n::t('Alle {count} im Album ansehen', ['count' => (int) ($a['total'] ?? 0)])) . '</a></p>';
    }

    /** @param array<string, mixed> $b */
    private function document(array $b): string
    {
        $d = $b['document'] ?? null;
        if (!is_array($d)) {
            return '';
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', substr((string) $d['date'], 0, 10));
        return '<div class="block-document"><strong>' . self::e((string) $d['title']) . '</strong><span class="muted">'
            . self::e(strtoupper((string) $d['ext']) . ' · ' . self::bytes((int) $d['size']) . ($date !== false ? ' · ' . I18n::t('Stand {date}', ['date' => $date->format('d.m.Y')]) : '')) . '</span>'
            . '<a class="site-btn site-btn-secondary" href="' . self::e((string) $d['url']) . '" download>' . self::e(I18n::t('Herunterladen')) . '</a></div>';
    }

    /** @param array<string, mixed> $b */
    private function sponsors(array $b): string
    {
        $sponsors = is_array($b['sponsors'] ?? null) ? $b['sponsors'] : [];
        if ($sponsors === []) {
            return '';
        }
        $out = self::heading($b) . '<ul class="block-sponsors-list">';
        foreach ($sponsors as $s) {
            $name = (string) $s['name'];
            $logo = is_string($s['logo_url'] ?? null) && $s['logo_url'] !== ''
                ? '<img src="' . self::e($s['logo_url']) . '" alt="' . self::e($name) . '" loading="lazy">'
                : '<span>' . self::e($name) . '</span>';
            $url = (string) ($s['url'] ?? '');
            $out .= '<li>' . ($url !== '' ? '<a href="' . self::e($url) . '" target="_blank" rel="sponsored noopener noreferrer" title="' . self::e($name) . '">' . $logo . '</a>' : $logo) . '</li>';
        }
        return $out . '</ul>';
    }

    /**
     * Place with a link to a map (only a link: the website loads no map from another server).
     *
     * @param array<string, mixed> $b
     */
    private function map(array $b): string
    {
        $place = trim((string) ($b['place'] ?? ''));
        if ($place === '') {
            return '';
        }
        return self::heading($b) . '<p>' . self::e($place) . '</p><p><a class="site-btn site-btn-secondary" href="'
            . self::e('https://www.openstreetmap.org/search?query=' . rawurlencode($place)) . '" target="_blank" rel="noopener noreferrer">' . self::e(I18n::t('Karte')) . '</a></p>';
    }

    /**
     * Own image with caption; small and medium are narrower and centred, never wider than the image itself.
     *
     * @param array<string, mixed> $b
     */
    private function image(array $b): string
    {
        $url = $b['url'] ?? null;
        if (!is_string($url) || $url === '') {
            return '';
        }
        $size = in_array($b['size'] ?? null, ['small', 'medium'], true) ? (string) $b['size'] : 'full';
        $dims = (int) ($b['width'] ?? 0) > 0 && (int) ($b['height'] ?? 0) > 0 ? ' width="' . (int) $b['width'] . '" height="' . (int) $b['height'] . '"' : '';
        $caption = (string) ($b['caption'] ?? '');
        return '<figure class="block-image block-image-' . $size . '"><img src="' . self::e($url) . '" alt="' . self::e((string) ($b['alt'] ?? '')) . '" loading="lazy"' . $dims . '>'
            . ($caption !== '' ? '<figcaption>' . self::e($caption) . '</figcaption>' : '') . '</figure>';
    }

    /** Pages of the App (#/…) at the App's address; other links as they are. */
    private function href(string $url): string
    {
        return str_starts_with($url, '#/') ? $this->app->env->appUrl() . $url : $url;
    }

    /**
     * „Sa., 27. Juni 2027 · 19:00–23:00 Uhr“ or „25.–28. Juni 2027“ (like the WordPress shortcodes).
     *
     * @param array<string, mixed> $e
     */
    public static function eventWhen(array $e): string
    {
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $e['start_date']);
        if ($start === false) {
            return '';
        }
        $text = self::date($start);
        $end = is_string($e['end_date'] ?? null) && $e['end_date'] !== $e['start_date'] ? \DateTimeImmutable::createFromFormat('!Y-m-d', $e['end_date']) : false;
        if ($end !== false) {
            $text = $start->format('Y-m') === $end->format('Y-m')
                ? I18n::t('{from}.–{to}. {month} {year}', ['from' => $start->format('j'), 'to' => $end->format('j'), 'month' => self::month($end), 'year' => $end->format('Y')])
                : I18n::t('{from}. {fromMonth}–{to}. {month} {year}', [
                    'from' => $start->format('j'),
                    'fromMonth' => self::month($start),
                    'to' => $end->format('j'),
                    'month' => self::month($end),
                    'year' => $end->format('Y'),
                ]);
        }
        $time = is_string($e['start_time'] ?? null) ? substr($e['start_time'], 0, 5) : '';
        if ($time !== '' && empty($e['all_day'])) {
            $endTime = is_string($e['end_time'] ?? null) ? substr($e['end_time'], 0, 5) : '';
            $text .= ' · ' . I18n::t('{time} Uhr', ['time' => $time . ($endTime !== '' ? '–' . $endTime : '')]);
        }
        return $text;
    }

    /** „Sa., 27. Juni 2027“ */
    public static function date(\DateTimeImmutable $d): string
    {
        return I18n::t('{weekday}, {day}. {month} {year}', ['weekday' => self::weekday($d), 'day' => $d->format('j'), 'month' => self::month($d), 'year' => $d->format('Y')]);
    }

    /** „Sa.“ */
    private static function weekday(\DateTimeImmutable $d): string
    {
        return match ((int) $d->format('w')) {
            0 => I18n::t('So.'),
            1 => I18n::t('Mo.'),
            2 => I18n::t('Di.'),
            3 => I18n::t('Mi.'),
            4 => I18n::t('Do.'),
            5 => I18n::t('Fr.'),
            default => I18n::t('Sa.'),
        };
    }

    /** „Juni“ */
    private static function month(\DateTimeImmutable $d): string
    {
        return match ((int) $d->format('n')) {
            1 => I18n::t('Januar'),
            2 => I18n::t('Februar'),
            3 => I18n::t('März'),
            4 => I18n::t('April'),
            5 => I18n::t('Mai'),
            6 => I18n::t('Juni'),
            7 => I18n::t('Juli'),
            8 => I18n::t('August'),
            9 => I18n::t('September'),
            10 => I18n::t('Oktober'),
            11 => I18n::t('November'),
            default => I18n::t('Dezember'),
        };
    }

    /** @param array<string, mixed> $b */
    private static function heading(array $b): string
    {
        $title = (string) ($b['title'] ?? '');
        return $title !== '' ? '<h2>' . self::e($title) . '</h2>' : '';
    }

    private static function bytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024) . ' KB';
        }
        return str_replace('.', ',', (string) round($bytes / 1048576, 1)) . ' MB';
    }

    private static function initials(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name)) ?: [];
        $first = $parts[0] ?? '';
        $last = count($parts) > 1 ? $parts[count($parts) - 1] : '';
        return mb_strtoupper(mb_substr($first, 0, 1) . mb_substr($last, 0, 1));
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
