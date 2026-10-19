<?php

declare(strict_types=1);

namespace KompanieApp\Core\Push;

use KompanieApp\Core\App;

/**
 * Wann eine Benachrichtigung entsteht (Issue #19, Gleichstand mit Lank):
 *
 * - neue Punkte bzw. Strafen: nur an das betroffene Mitglied (bei Strafen Betrag und Grund nur, wenn das Mitglied
 *   „Details anzeigen“ eingeschaltet hat);
 * - öffentlicher Termin neu oder Datum, Uhrzeit oder Ort geändert: an Gäste und Konten ohne Kalender;
 * - interner Kalender neu oder geändert (Abgleich mit dem letzten Stand; beim ersten Lauf nur merken): an
 *   Mitglieder mit Kalender, damit nichts doppelt kommt;
 * - neue Neuigkeiten (Abgleich mit dem letzten Stand): an alle Abonnenten;
 * - Erinnerung an die Termine von morgen, einmal am Tag ab der eingestellten Uhrzeit.
 */
final class PushTriggers
{
    public function __construct(private readonly App $app, private readonly PushService $push)
    {
    }

    /** @param array<int, array<string, mixed>> $rows gebuchte Punkte (ScoreService::givePoints) */
    public function pointsAdded(array $rows): void
    {
        $label = $this->app->config->sectionLabel('points', 'Punkte');
        foreach ($rows as $r) {
            $value = (float) $r['points'];
            $title = $value < 0 ? $label . ' abgezogen' : $label . ' erhalten';
            $body = ($value > 0 ? '+' : '−') . self::number(abs($value)) . ' – ' . (string) $r['reason'];
            $this->push->notifyUser((int) $r['user_id'], PushService::TYPE_POINTS, PushService::message($title, $body, 'meins/punkte', 'punkte-' . $r['id']));
        }
    }

    /** @param array<int, array<string, mixed>> $rows Strafen (ScoreService::givePenalty) */
    public function penaltiesAdded(array $rows): void
    {
        $title = $this->app->config->sectionLabel('penalties', 'Strafen') . ': neuer Eintrag';
        foreach ($rows as $r) {
            $tag = 'strafe-' . $r['id'];
            $this->push->notifyUser(
                (int) $r['user_id'],
                PushService::TYPE_PENALTIES,
                PushService::message($title, 'Tippen zum Ansehen.', 'meins/strafen', $tag),
                PushService::message($title, self::euro((int) $r['amount_cents']) . ' – ' . (string) $r['reason'], 'meins/strafen', $tag),
            );
        }
    }

    /**
     * Öffentlicher Termin angelegt oder geändert (EventService::format). Änderungen nur an Titel oder Beschreibung
     * lösen nichts aus.
     *
     * @param array<string, mixed> $event
     * @param array<string, mixed>|null $before
     */
    public function publicEventSaved(array $event, ?array $before = null): int
    {
        if (!$this->isUpcoming($event) || ($before !== null && self::signature($before) === self::signature($event))) {
            return 0;
        }
        $title = $before !== null ? 'Termin geändert' : 'Neuer Termin';
        return $this->push->notifyAll(PushService::TYPE_EVENTS, PushService::message($title, $this->eventBody($event), 'termine', 'termin-p' . $event['id']), false);
    }

    /** Alle 15 Minuten (Aufgabe push_watch). */
    public function watch(?\DateTimeImmutable $now = null): void
    {
        if (!$this->push->supported()) {
            return;
        }
        if ($this->app->features()->enabled('calendar') && $this->app->calendar()->isConfigured()) {
            $this->checkCalendar();
        }
        if ($this->app->features()->enabled('news')) {
            $this->checkNews();
        }
        $this->sendReminders($now);
    }

    /**
     * Vergleicht die Termine mit dem letzten Stand.
     *
     * @param array<string, mixed> $seen id → Signatur
     * @param array<int, array<string, mixed>> $events
     * @return array{new: list<array<string, mixed>>, changed: list<array<string, mixed>>, seen: array<string, mixed>}
     */
    public function calendarChanges(array $seen, array $events, int $now): array
    {
        $new = [];
        $changed = [];
        $next = [];
        foreach ($events as $ev) {
            $id = (string) $ev['id'];
            $sig = self::signature($ev);
            $next[$id] = $sig;
            if (!$this->isUpcoming($ev)) {
                continue;
            }
            if (!isset($seen[$id])) {
                if ($this->start($ev)->getTimestamp() <= $now + 300 * 86400) {
                    $new[] = $ev;
                }
            } elseif ($seen[$id] !== $sig) {
                $changed[] = $ev;
            }
        }
        return ['new' => $new, 'changed' => $changed, 'seen' => $next];
    }

    /** Neue und geänderte Kalendertermine; beim ersten Lauf nur merken, bei einem Abruffehler unverändert lassen. */
    public function checkCalendar(): int
    {
        try {
            $events = $this->app->calendar()->events();
        } catch (\RuntimeException) {
            return 0;
        }
        $seen = $this->app->settings->get(PushService::CALENDAR_SETTING);
        $diff = $this->calendarChanges(is_array($seen) ? $seen : [], $events, $this->app->time()->timestamp());
        if ($diff['seen'] !== $seen) {
            $this->app->settings->set(PushService::CALENDAR_SETTING, $diff['seen']);
        }
        if (!is_array($seen)) {
            return 0;
        }
        $count = 0;
        if (count($diff['new']) >= 4) {
            $titles = implode(', ', array_map(static fn (array $e): string => (string) $e['title'], array_slice($diff['new'], 0, 3)));
            $count += $this->push->notifyAll(PushService::TYPE_EVENTS, PushService::message('Neue Termine', sprintf('%d neue Termine im Kalender, z. B. %s', count($diff['new']), $titles), 'termine', 'termine-neu'), true);
        } else {
            foreach ($diff['new'] as $ev) {
                $count += $this->push->notifyAll(PushService::TYPE_EVENTS, PushService::message('Neuer Termin', $this->eventBody($ev), 'termine', 'termin-' . $ev['id']), true);
            }
        }
        foreach ($diff['changed'] as $ev) {
            $count += $this->push->notifyAll(PushService::TYPE_EVENTS, PushService::message('Termin geändert', $this->eventBody($ev), 'termine', 'termin-' . $ev['id']), true);
        }
        return $count;
    }

    /** Neue Neuigkeiten seit dem letzten Abgleich; beim ersten Lauf nur merken. */
    public function checkNews(): int
    {
        if (!$this->app->news->available()) {
            return 0;
        }
        try {
            $items = $this->app->news->page(1, 10)['items'];
        } catch (\RuntimeException) {
            return 0;
        }
        $ids = array_values(array_map(static fn (array $i): string => (string) $i['id'], $items));
        $seen = $this->app->settings->get(PushService::NEWS_SETTING);
        if ($ids !== $seen) {
            // Die letzten 50 merken: Eine Neuigkeit, die aus den ersten 10 rutscht und wieder auftaucht, kommt nicht doppelt.
            $this->app->settings->set(PushService::NEWS_SETTING, array_values(array_slice(array_unique(array_merge($ids, is_array($seen) ? $seen : [])), 0, 50)));
        }
        if (!is_array($seen)) {
            return 0;
        }
        $count = 0;
        foreach (array_reverse($items) as $item) {
            if (!in_array((string) $item['id'], $seen, true)) {
                $title = html_entity_decode(strip_tags((string) $item['title']), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $count += $this->push->notifyAll(PushService::TYPE_NEWS, PushService::message($this->app->config->sectionLabel('news', 'News'), $title, 'news/' . rawurlencode((string) $item['id']), 'news-' . $item['id']));
            }
        }
        return $count;
    }

    /** Erinnerung an die Termine von morgen, einmal am Tag ab der eingestellten Uhrzeit (Zeitzone der Kompanie). */
    public function sendReminders(?\DateTimeImmutable $now = null): int
    {
        $now ??= $this->app->time()->today()->setTimestamp($this->app->time()->timestamp());
        $now = $now->setTimezone($this->app->time()->timezone());
        $hour = (int) $now->format('G');
        $from = $this->push->reminderHour();
        if ($hour < $from || $hour >= $from + 4 || $this->app->settings->get(PushService::REMINDER_DATE_SETTING) === $now->format('Y-m-d')) {
            return 0;
        }
        $this->app->settings->set(PushService::REMINDER_DATE_SETTING, $now->format('Y-m-d'));
        $tomorrow = $now->modify('+1 day')->format('Y-m-d');
        $count = 0;
        if ($this->app->features()->enabled('calendar') && $this->app->calendar()->isConfigured()) {
            try {
                $list = $this->eventsOn($this->app->calendar()->events(), $tomorrow);
                if ($list !== []) {
                    $count += $this->push->notifyAll(PushService::TYPE_REMINDER, $this->reminderMessage($list), true);
                }
            } catch (\RuntimeException) {
                // Kalender nicht erreichbar: dann eben keine Erinnerung aus dem Kalender.
            }
        }
        if ($this->app->features()->enabled('events')) {
            $list = $this->eventsOn($this->app->events()->upcoming(), $tomorrow);
            if ($list !== []) {
                $count += $this->push->notifyAll(PushService::TYPE_REMINDER, $this->reminderMessage($list), false);
            }
        }
        return $count;
    }

    /**
     * @param array<int, array<string, mixed>> $events
     * @return list<array<string, mixed>>
     */
    private function eventsOn(array $events, string $date): array
    {
        return array_values(array_filter($events, fn (array $ev): bool => $this->start($ev)->format('Y-m-d') === $date));
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return array{title: string, body: string, route: string, tag: string}
     */
    private function reminderMessage(array $events): array
    {
        $parts = [];
        foreach ($events as $ev) {
            $extra = array_filter([empty($ev['all_day']) ? $this->start($ev)->format('H:i') . ' Uhr' : '', trim((string) ($ev['location'] ?? ''))]);
            $parts[] = (string) $ev['title'] . ($extra !== [] ? ' (' . implode(', ', $extra) . ')' : '');
        }
        $title = count($events) === 1 ? 'Morgen: ' . $events[0]['title'] : sprintf('Morgen: %d Termine', count($events));
        return PushService::message($title, implode(' · ', $parts), 'termine', 'erinnerung');
    }

    /** @param array<string, mixed> $ev */
    private function start(array $ev): \DateTimeImmutable
    {
        $tz = $this->app->time()->timezone();
        return (new \DateTimeImmutable((string) $ev['start'], $tz))->setTimezone($tz);
    }

    /** Liegt der Termin (oder sein Ende) heute oder später? Ganztägig: Ende exklusiv. */
    private function isUpcoming(array $ev): bool
    {
        $tz = $this->app->time()->timezone();
        $end = (new \DateTimeImmutable((string) $ev['end'], $tz))->setTimezone($tz);
        if (!empty($ev['all_day'])) {
            $end = $end->modify('-1 day');
        }
        return $end->format('Y-m-d') >= $this->app->time()->today()->format('Y-m-d');
    }

    /** „Sa 05.09.2026, 14:00 Uhr“ bzw. „Fr 04.09.2026 – Mo 07.09.2026“ */
    private function when(array $ev): string
    {
        $days = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
        $day = static fn (\DateTimeImmutable $d): string => $days[(int) $d->format('w')] . ' ' . $d->format('d.m.Y');
        $start = $this->start($ev);
        if (!empty($ev['all_day'])) {
            $last = (new \DateTimeImmutable((string) $ev['end'], $this->app->time()->timezone()))->modify('-1 day');
            return $last > $start ? $day($start) . ' – ' . $day($last) : $day($start);
        }
        return $day($start) . ', ' . $start->format('H:i') . ' Uhr';
    }

    /** @param array<string, mixed> $ev */
    private function eventBody(array $ev): string
    {
        $location = trim((string) ($ev['location'] ?? ''));
        return (string) $ev['title'] . ' – ' . $this->when($ev) . ($location !== '' ? ' – ' . $location : '');
    }

    /**
     * Für Nachrichten wichtige Angaben: Beginn, Ende, Ort.
     *
     * @param array<string, mixed> $ev
     * @return list<string>
     */
    private static function signature(array $ev): array
    {
        return [(string) $ev['start'], (string) $ev['end'], trim((string) ($ev['location'] ?? ''))];
    }

    private static function number(float $value): string
    {
        $text = rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',');
        return $text;
    }

    private static function euro(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '.') . ' €';
    }
}
