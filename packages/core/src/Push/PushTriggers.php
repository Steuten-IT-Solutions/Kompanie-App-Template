<?php

declare(strict_types=1);

namespace KompanieApp\Core\Push;

use KompanieApp\Core\App;
use KompanieApp\Core\Domain\RankService;

/**
 * Wann eine Benachrichtigung entsteht (Issue #19):
 *
 * - neue Punkte bzw. Strafen: nur an das betroffene Mitglied (bei Strafen Betrag und Grund nur, wenn das Mitglied
 *   „Details anzeigen“ eingeschaltet hat);
 * - öffentlicher Termin neu oder Datum, Uhrzeit oder Ort geändert: an Gäste und Konten ohne Kalender;
 * - interner Kalender neu oder geändert (Abgleich mit dem letzten Stand; beim ersten Lauf nur merken): an
 *   Mitglieder mit Kalender, damit nichts doppelt kommt;
 * - neue Neuigkeiten (Abgleich mit dem letzten Stand): an alle Abonnenten;
 * - Erinnerung an die Termine von morgen, einmal am Tag ab der eingestellten Uhrzeit;
 * - Dienstgrad geändert bzw. Titel neu verliehen: nur an das betroffene Mitglied;
 * - neue Nachricht am Schwarzen Brett (Issue #5): an alle Mitglieder und Anwärter außer dem Verfasser, dringend und
 *   höchstens bis zum Ablauf der Nachricht zustellbar; Ändern löst nichts aus;
 * - neue Umfrage (Issue #4): an alle Mitglieder außer dem, der sie angelegt hat; Erinnerung 24 Stunden vor dem Ende an
 *   alle Mitglieder ohne Antwort (einmal je Umfrage, Aufgabe poll_reminders);
 * - Geburtstag (Issue #1): am Tag selbst ab der eingestellten Stunde (Standard 7 Uhr) an alle Mitglieder außer dem Geburtstagskind, nur für Geburtstage,
 *   die die Person anderen zeigt (einmal je Geburtstag und Tag, Aufgabe birthday_notifications).
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
        $messages = [];
        foreach ($rows as $r) {
            $value = (float) $r['points'];
            $title = $value < 0 ? $label . ' abgezogen' : $label . ' erhalten';
            $body = ($value > 0 ? '+' : '−') . self::number(abs($value)) . ' – ' . (string) $r['reason'];
            $messages[] = [(int) $r['user_id'], PushService::message($title, $body, 'meins/punkte/' . $r['id'], 'punkte-' . $r['id'])];
        }
        // Alle Personen einer Buchung gemeinsam (Abos mit einer Abfrage, ein INSERT in die Warteschlange)
        $this->push->notifyUsers(PushService::TYPE_POINTS, $messages);
    }

    /**
     * Mit Abzeichen des neuen Dienstgrads als Bild (PNG, 256 Pixel), wenn es eins gibt (Issue #17).
     *
     * @param array{to_title: string, kind: string, to_rank_id?: int|null} $entry Verlaufseintrag (RankService::change)
     */
    public function rankChanged(int $userId, array $entry): void
    {
        $label = $this->app->config->rankLabel();
        $title = $entry['kind'] === RankService::KIND_PROMOTION ? 'Beförderung' : $label . ' geändert';
        $body = $entry['to_title'] !== '' ? sprintf('Dein %s: %s', $label, $entry['to_title']) : sprintf('Du hast keinen %s mehr.', $label);
        $image = ($entry['to_rank_id'] ?? null) !== null ? $this->app->ranks()->badgePushImage((int) $entry['to_rank_id']) : null;
        $this->push->notifyUser($userId, PushService::TYPE_RANKS, PushService::message($title, $body, 'meins/dienstgrad', 'dienstgrad', $image));
    }

    /** @param list<string> $titles neu verliehene Titel (TitleService::assign) */
    public function titlesAdded(int $userId, array $titles): void
    {
        if ($titles === []) {
            return;
        }
        $body = count($titles) === 1
            ? sprintf('Dir wurde der Titel „%s“ verliehen.', $titles[0])
            : sprintf('Dir wurden die Titel %s verliehen.', implode(', ', array_map(static fn (string $t): string => '„' . $t . '“', $titles)));
        $this->push->notifyUser($userId, PushService::TYPE_TITLES, PushService::message('Neuer Titel', $body, 'meins', 'titel'));
    }

    /**
     * Neue Nachricht am Brett (Issue #172): Überschrift „<Brett>: <Verfasser>“, Text = Titel der Nachricht. Zustellbar
     * bis zum Enddatum, höchstens PushService::MAX_TTL (Obergrenze der Push-Dienste): Das begrenzt nur, wie lange ein
     * ausgeschaltetes Gerät die Benachrichtigung nachgeliefert bekommt, nicht, wie lange die Nachricht sichtbar ist.
     *
     * @param array{id: int, title: string, author_name: string|null, expires_at: string|null} $item neue Nachricht (BoardService::create)
     */
    public function boardPosted(array $item, int $authorId): int
    {
        $expires = $item['expires_at'] !== null ? (int) strtotime($item['expires_at']) : 0;
        $ttl = min($expires - $this->app->time()->timestamp(), PushService::MAX_TTL);
        $title = $this->app->config->sectionLabel('board', 'Schwarzes Brett') . ($item['author_name'] !== null ? ': ' . $item['author_name'] : '');
        return $this->push->notifyBoard(PushService::message($title, $item['title'], 'brett/' . $item['id'], 'brett-' . $item['id']), $authorId, $ttl);
    }

    /**
     * Neue Umfrage (Issue #4): an alle Mitglieder außer dem, der sie angelegt hat; zustellbar höchstens bis zum Ende.
     *
     * @param array{id: int, title: string, ends_at: string|null} $poll
     */
    public function pollCreated(array $poll, int $creatorId): int
    {
        $title = $this->app->config->sectionLabel('polls', 'Umfragen') . ': neue Umfrage';
        $body = $poll['title'] . ($poll['ends_at'] !== null ? ' (bis ' . $this->pollEnd($poll['ends_at']) . ')' : '');
        return $this->push->notifyAudience(
            PushService::TYPE_POLLS,
            PushService::message($title, $body, 'umfragen/' . $poll['id'], 'umfrage-' . $poll['id']),
            static fn (int $uid): bool => $uid !== $creatorId,
            'normal',
            $this->ttlUntil($poll['ends_at']),
        );
    }

    /**
     * Erinnerung 24 Stunden vor dem Ende (PollService::remind): an alle Mitglieder, die noch nicht teilgenommen haben.
     *
     * @param array{id: int, title: string, ends_at: string|null} $poll
     * @param list<int> $voters
     */
    public function pollReminder(array $poll, array $voters): int
    {
        $voted = array_flip($voters);
        $title = $this->app->config->sectionLabel('polls', 'Umfragen') . ': endet bald';
        $body = $poll['title'] . ($poll['ends_at'] !== null ? ' – noch bis ' . $this->pollEnd($poll['ends_at']) : '') . '. Du hast noch nicht teilgenommen.';
        return $this->push->notifyAudience(
            PushService::TYPE_POLLS,
            PushService::message($title, $body, 'umfragen/' . $poll['id'], 'umfrage-' . $poll['id']),
            static fn (int $uid): bool => !isset($voted[$uid]),
            'normal',
            $this->ttlUntil($poll['ends_at']),
        );
    }

    /**
     * Geburtstag heute (Issue #1, BirthdayService::notifyToday): an alle Mitglieder außer dem Geburtstagskind, das Alter
     * nur, wenn die Person es freigegeben hat; zustellbar höchstens bis Mitternacht (Zeitzone der Kompanie).
     */
    public function birthday(int $userId, string $name, ?int $age): int
    {
        $title = $this->app->config->sectionLabel('birthdays', 'Geburtstage');
        $body = 'Heute hat ' . $name . ' Geburtstag' . ($age !== null ? ' (wird ' . $age . ')' : '');
        $midnight = $this->app->time()->today()->modify('+1 day')->getTimestamp();
        return $this->push->notifyAudience(
            PushService::TYPE_BIRTHDAYS,
            PushService::message($title, $body, 'termine/geburtstage', 'geburtstag-' . $userId),
            static fn (int $uid): bool => $uid !== $userId,
            'normal',
            $midnight - $this->app->time()->timestamp(),
        );
    }

    private function ttlUntil(?string $iso): int
    {
        return $iso === null ? PushService::TTL : (int) strtotime($iso) - $this->app->time()->timestamp();
    }

    private function pollEnd(string $iso): string
    {
        return $this->app->time()->format((int) strtotime($iso), 'd.m.Y, H:i') . ' Uhr';
    }

    /** @param array<int, array<string, mixed>> $rows Strafen (ScoreService::givePenalty) */
    public function penaltiesAdded(array $rows): void
    {
        $title = $this->app->config->sectionLabel('penalties', 'Strafen') . ': neuer Eintrag';
        $messages = [];
        foreach ($rows as $r) {
            $tag = 'strafe-' . $r['id'];
            $messages[] = [
                (int) $r['user_id'],
                PushService::message($title, 'Tippen zum Ansehen.', 'meins/strafen/' . $r['id'], $tag),
                PushService::message($title, self::euro((int) $r['amount_cents']) . ' – ' . (string) $r['reason'], 'meins/strafen/' . $r['id'], $tag),
            ];
        }
        $this->push->notifyUsers(PushService::TYPE_PENALTIES, $messages);
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
        return $this->push->notifyAll(PushService::TYPE_EVENTS, PushService::message($title, $this->eventBody($event), self::eventRoute('p' . $event['id']), 'termin-p' . $event['id']), false);
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
        $this->push->expireGuests();
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
                $count += $this->push->notifyAll(PushService::TYPE_EVENTS, PushService::message('Neuer Termin', $this->eventBody($ev), self::eventRoute((string) $ev['id']), 'termin-' . $ev['id']), true);
            }
        }
        foreach ($diff['changed'] as $ev) {
            $count += $this->push->notifyAll(PushService::TYPE_EVENTS, PushService::message('Termin geändert', $this->eventBody($ev), self::eventRoute((string) $ev['id']), 'termin-' . $ev['id']), true);
        }
        return $count;
    }

    /**
     * Neue Neuigkeiten seit dem letzten Abgleich; beim ersten Lauf nur merken. Neu ist ein Beitrag, dessen Kennung
     * noch nicht vorkam und der nicht älter ist als der neueste bisher gesehene: Wird einer gelöscht, rutschen
     * ältere in die ersten zehn, ohne neu zu sein.
     */
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
        $stored = $this->app->settings->get(PushService::NEWS_SETTING);
        $seen = is_array($stored) && is_array($stored['ids'] ?? null) ? array_map('strval', $stored['ids']) : null;
        $newest = is_array($stored) && is_string($stored['newest'] ?? null) ? $stored['newest'] : '';
        $time = static fn (array $i): int => (int) strtotime((string) ($i['date'] ?? ''));
        $fresh = [];
        foreach (array_reverse($items) as $item) {
            $id = (string) $item['id'];
            if ($seen !== null && !in_array($id, $seen, true) && ($newest === '' || $time($item) === 0 || $time($item) >= (int) strtotime($newest))) {
                $fresh[] = $item;
            }
        }
        $ids = array_values(array_map(static fn (array $i): string => (string) $i['id'], $items));
        foreach ($items as $item) {
            if ($time($item) > (int) strtotime($newest !== '' ? $newest : '@0')) {
                $newest = (string) $item['date'];
            }
        }
        // Die letzten 50 merken: Eine Neuigkeit, die aus den ersten 10 rutscht und wieder auftaucht, kommt nicht doppelt.
        $next = ['ids' => array_values(array_slice(array_unique(array_merge($ids, $seen ?? [])), 0, 50)), 'newest' => $newest];
        if ($next !== $stored) {
            $this->app->settings->set(PushService::NEWS_SETTING, $next);
        }
        $label = $this->app->config->sectionLabel('news', 'News');
        $plain = static fn (array $i): string => html_entity_decode(strip_tags((string) $i['title']), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (count($fresh) > 3) {
            $titles = implode(', ', array_map($plain, array_slice(array_reverse($fresh), 0, 3)));
            return $this->push->notifyAll(PushService::TYPE_NEWS, PushService::message($label, sprintf('%d neue Beiträge, z. B. %s', count($fresh), $titles), 'news', 'news-neu'));
        }
        $count = 0;
        foreach ($fresh as $item) {
            $count += $this->push->notifyAll(PushService::TYPE_NEWS, PushService::message($label, $plain($item), 'news/' . rawurlencode((string) $item['id']), 'news-' . $item['id']));
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
                    $count += $this->push->notifyAll(PushService::TYPE_REMINDER, $this->reminderMessage($list, ''), true);
                }
            } catch (\RuntimeException) {
                // Kalender nicht erreichbar: dann eben keine Erinnerung aus dem Kalender.
            }
        }
        if ($this->app->features()->enabled('events')) {
            $list = $this->eventsOn($this->app->events()->upcoming(), $tomorrow);
            if ($list !== []) {
                $count += $this->push->notifyAll(PushService::TYPE_REMINDER, $this->reminderMessage($list, 'p'), false);
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
     * Ziel einer Termin-Benachrichtigung: die Terminliste, die zu diesem Termin scrollt (Issue #190). Die Kennung ist die
     * der Liste, die die Empfänger sehen: Kalendertermine wie im Kalender, öffentliche Termine mit „p“ davor.
     */
    public static function eventRoute(string $id): string
    {
        return 'termine/termin/' . rawurlencode($id);
    }

    /**
     * Bei genau einem Termin führt die Erinnerung zu diesem, sonst zur Liste.
     *
     * @param list<array<string, mixed>> $events
     * @param string $prefix vor der Kennung: „p“ für öffentliche Termine
     * @return array{title: string, body: string, route: string, tag: string}
     */
    private function reminderMessage(array $events, string $prefix): array
    {
        $parts = [];
        foreach ($events as $ev) {
            $extra = array_filter([empty($ev['all_day']) ? $this->start($ev)->format('H:i') . ' Uhr' : '', trim((string) ($ev['location'] ?? ''))]);
            $parts[] = (string) $ev['title'] . ($extra !== [] ? ' (' . implode(', ', $extra) . ')' : '');
        }
        $title = count($events) === 1 ? 'Morgen: ' . $events[0]['title'] : sprintf('Morgen: %d Termine', count($events));
        $route = count($events) === 1 ? self::eventRoute($prefix . $events[0]['id']) : 'termine';
        return PushService::message($title, implode(' · ', $parts), $route, 'erinnerung');
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
