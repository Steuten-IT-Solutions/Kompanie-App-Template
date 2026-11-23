<?php

declare(strict_types=1);

namespace KompanieApp\Core\Mcp;

/**
 * Werkzeuge des MCP-Endpunkts (Plan „MCP-Server für die Kompanie-Apps“, Schritt 2). Jedes Werkzeug ruft genau eine
 * bestehende Route der API auf (Http\Api::handle), damit Rechte, Bereiche, Wartung, Änderungsprotokoll und
 * Nutzungsauswertung dieselben bleiben wie in der App. Nur diese Routen nehmen ein Zugriffstoken an (neben denen mit
 * Route::$token).
 *
 * Bewusst nicht dabei (Plan): Löschen, Rollen und Rechte, Konten, E-Mails, Einstellungen, Updates und Sicherungen.
 * Nachrichten ans Brett gehen nie per Mail hinaus (mail ist fest false), damit Text aus der App, der wie eine Anweisung
 * aussieht, niemanden außerhalb der App erreicht.
 *
 * Namen und Beschreibungen sind englisch und neutral; die Daten kommen in der Sprache der Installation.
 *
 * @phpstan-type ToolDef array{name: string, title: string, description: string, method: string, path: string, write: bool, list: bool, input: array<string, mixed>}
 */
final class Tools
{
    /** Höchstzahl der Einträge einer Liste in einer Antwort, ohne Angabe von limit */
    public const LIMIT = 50;
    public const LIMIT_MAX = 200;

    /** @var list<ToolDef>|null */
    private static ?array $all = null;

    /** @return list<ToolDef> */
    public static function all(): array
    {
        return self::$all ??= self::build();
    }

    /** @return ToolDef|null */
    public static function find(string $name): ?array
    {
        foreach (self::all() as $tool) {
            if ($tool['name'] === $name) {
                return $tool;
            }
        }
        return null;
    }

    /** Nimmt die Route (Methode und Pfad mit Platzhaltern, z. B. „/events/{id}“) ein Zugriffstoken an, weil ein Werkzeug sie aufruft? */
    public static function covers(string $method, string $path): bool
    {
        foreach (self::all() as $tool) {
            if ($tool['method'] === $method && $tool['path'] === $path) {
                return true;
            }
        }
        return false;
    }

    /**
     * Anfrage an die API für einen Aufruf: Methode, Pfad mit eingesetzten Platzhaltern, Abfrage und Inhalt.
     *
     * @param ToolDef $tool
     * @param array<string, mixed> $args geprüft gegen das Schema (McpServer::check)
     * @return array{0: string, 1: string, 2: array<string, mixed>, 3: array<string, mixed>}
     */
    public static function request(array $tool, array $args): array
    {
        $path = $tool['path'];
        foreach (['id', 'key'] as $param) {
            if (str_contains($path, '{' . $param . '}')) {
                $path = str_replace('{' . $param . '}', rawurlencode((string) $args[$param]), $path);
                unset($args[$param]);
            }
        }
        unset($args['limit']);
        if ($tool['name'] === 'post_board_message') {
            $args['mail'] = false;
        }
        return $tool['method'] === 'GET' ? [$tool['method'], $path, [], []] : [$tool['method'], $path, [], $args];
    }

    /** @return list<ToolDef> */
    private static function build(): array
    {
        $none = self::object([]);
        $limit = ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::LIMIT_MAX, 'description' => 'Maximum number of entries to return (default ' . self::LIMIT . ').']];
        $listOnly = self::object($limit);
        $id = static fn (string $what): array => ['id' => ['type' => 'integer', 'minimum' => 1, 'description' => $what]];
        $event = [
            'title' => ['type' => 'string', 'maxLength' => 191, 'description' => 'Title (one line).'],
            'start_date' => ['type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$', 'description' => 'Start date YYYY-MM-DD.'],
            'start_time' => ['type' => 'string', 'description' => 'Start time HH:MM, empty for all-day.'],
            'end_date' => ['type' => 'string', 'description' => 'End date YYYY-MM-DD or empty.'],
            'end_time' => ['type' => 'string', 'description' => 'End time HH:MM or empty.'],
            'location' => ['type' => 'string'],
            'description' => ['type' => 'string', 'description' => 'Plain text description.'],
            'url' => ['type' => 'string', 'description' => 'Link with more information (https).'],
        ];
        $calendar = $event + ['respond_until' => ['type' => 'string', 'description' => 'Deadline for replies YYYY-MM-DD (at most the start date) or empty.']];
        return [
            self::tool('whoami', 'Who am I', 'Returns the installation (company app), the signed-in person with roles and permissions, and the scope of this access (read or write). Call this first to know where and as whom you act.', 'GET', '/me', false, false, $none),
            self::tool('list_public_events', 'List public events', 'Upcoming public events of the company (the ones shown on the website).', 'GET', '/events', false, true, $listOnly),
            self::tool('list_calendar', 'List internal calendar', 'Upcoming entries of the internal calendar with the own reply (accepted/declined) and, with the permission, counts of replies. The key of an entry is used by get_event_responses and record_attendance.', 'GET', '/calendar', false, true, $listOnly),
            self::tool('get_event_responses', 'Get replies to an event', 'Who accepted or declined an entry of the internal calendar.', 'GET', '/calendar/{key}/responses', false, false, self::object(['key' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9_-]+$', 'description' => 'Key of the calendar entry (from list_calendar).']], ['key'])),
            self::tool('list_members', 'List members', 'Member directory: ids, names, ranks and titles as far as the person may see them. Ids are used by record_attendance.', 'GET', '/directory', false, true, $listOnly),
            self::tool('list_attendance_lists', 'List attendance lists', 'The latest attendance lists with counts.', 'GET', '/attendance', false, true, $listOnly),
            self::tool('get_attendance_list', 'Get attendance list', 'One attendance list with the status of every person (present, excused, absent).', 'GET', '/attendance/{id}', false, false, self::object($id('Id of the attendance list (from list_attendance_lists).'), ['id'])),
            self::tool('list_board_messages', 'List board messages', 'Current messages of the notice board that the person may read.', 'GET', '/board', false, true, $listOnly),
            self::tool('list_polls', 'List polls', 'Polls with their state; results as far as the person may see them.', 'GET', '/polls', false, true, $listOnly),
            self::tool('get_poll', 'Get poll', 'One poll with its options and, if visible, the results.', 'GET', '/polls/{id}', false, false, self::object($id('Id of the poll (from list_polls).'), ['id'])),
            self::tool('get_app_status', 'Get app status', 'Technical status of the installation (version, updates, tasks, errors). Admins only.', 'GET', '/admin/status', false, false, $none),
            self::tool('create_public_event', 'Create public event', 'Creates a public event (shown on the website and in the app). Members may get a notification.', 'POST', '/events', true, false, self::object($event, ['title', 'start_date'])),
            self::tool('update_public_event', 'Update public event', 'Changes fields of a public event; fields not given stay as they are.', 'PATCH', '/events/{id}', true, false, self::object($id('Id of the event (from list_public_events).') + $event, ['id'])),
            self::tool('create_calendar_entry', 'Create calendar entry', 'Creates an entry in the internal calendar of the app (only when the calendar is kept in the app). Members may get a notification.', 'POST', '/calendar/entries', true, false, self::object($calendar, ['title', 'start_date'])),
            self::tool('update_calendar_entry', 'Update calendar entry', 'Changes fields of an entry of the internal calendar kept in the app; fields not given stay as they are.', 'PATCH', '/calendar/entries/{id}', true, false, self::object($id('Numeric id of the entry kept in the app.') + $calendar, ['id'])),
            self::tool('post_board_message', 'Post board message', 'Publishes a message on the notice board. Never sends e-mail. With push true (default) readers get a push notification.', 'POST', '/board', true, false, self::object([
                'title' => ['type' => 'string', 'description' => 'Title, one line (also the notification).'],
                'text' => ['type' => 'string', 'description' => 'Content as simple HTML (paragraphs, lists, links, emphasis) or plain text.'],
                'expires_at' => ['type' => 'string', 'description' => 'Visible until: ISO 8601 with time zone or YYYY-MM-DDTHH:MM in the time zone of the company; must be in the future. Default 24 hours.'],
                'audience' => ['type' => 'string', 'enum' => ['members', 'public', 'company', 'person'], 'description' => 'members (internal, default), public (everyone, also without login), company or person (with audience_ids).'],
                'audience_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Ids of companies (audience company) or accounts (audience person).'],
                'push' => ['type' => 'boolean', 'description' => 'Send a push notification (default true).'],
            ], ['title', 'text'])),
            self::tool('record_attendance', 'Record attendance', 'Creates or changes an attendance list and sets the status per person. With id an existing list; without id a new one: source calendar with event_key, or source free with title and date. In the future only excused is allowed.', 'PUT', '/attendance', true, false, self::object([
                'id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Existing list.'],
                'source' => ['type' => 'string', 'enum' => ['calendar', 'free']],
                'event_key' => ['type' => 'string', 'description' => 'Key of the calendar entry (source calendar).'],
                'title' => ['type' => 'string', 'maxLength' => 191, 'description' => 'Title (source free).'],
                'date' => ['type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$', 'description' => 'Date YYYY-MM-DD (source free).'],
                'entries' => ['type' => 'object', 'description' => 'Account id → status present, excused or absent; null removes the status.', 'additionalProperties' => ['type' => ['string', 'null'], 'enum' => ['present', 'excused', 'absent', null]]],
            ])),
            self::tool('create_poll', 'Create poll', 'Creates a poll. Members may get a notification.', 'POST', '/polls', true, false, self::object([
                'title' => ['type' => 'string', 'maxLength' => 150, 'description' => 'The question.'],
                'description' => ['type' => 'string', 'maxLength' => 1000],
                'type' => ['type' => 'string', 'enum' => ['single', 'multiple', 'text'], 'description' => 'Single choice, multiple choice or free text.'],
                'options' => ['type' => 'array', 'minItems' => 2, 'maxItems' => 20, 'items' => ['type' => 'string', 'maxLength' => 100], 'description' => 'For single and multiple: 2–20 different options.'],
                'anonymous' => ['type' => 'boolean', 'description' => 'Default false.'],
                'results' => ['type' => 'string', 'enum' => ['after_vote', 'after_end', 'managers'], 'description' => 'Who sees results when (default after_vote).'],
                'ends_at' => ['type' => 'string', 'description' => 'End: YYYY-MM-DDTHH:MM in the time zone of the company or ISO 8601 with time zone; in the future.'],
            ], ['title', 'type'])),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return ToolDef
     */
    private static function tool(string $name, string $title, string $description, string $method, string $path, bool $write, bool $list, array $input): array
    {
        return ['name' => $name, 'title' => $title, 'description' => $description, 'method' => $method, 'path' => $path, 'write' => $write, 'list' => $list, 'input' => $input];
    }

    /**
     * @param array<string, mixed> $properties
     * @param list<string> $required
     * @return array<string, mixed>
     */
    private static function object(array $properties, array $required = []): array
    {
        $schema = ['type' => 'object', 'properties' => $properties === [] ? new \stdClass() : $properties, 'additionalProperties' => false];
        return $required === [] ? $schema : $schema + ['required' => $required];
    }
}
