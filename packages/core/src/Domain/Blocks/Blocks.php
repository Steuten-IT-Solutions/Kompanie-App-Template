<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain\Blocks;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Domain\RichText;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\I18n\I18n;

/**
 * Inhalte aus Blöcken (eigene Seiten, News in der App, Schwarzes Brett): eine geordnete Liste, jeder Block mit id, type,
 * audience (all: alle, die den Inhalt sehen; members: nur Mitglieder) und den Feldern seines Typs (BlockType).
 *
 * Gespeichert als JSON in einer Spalte blocks neben der bisherigen Spalte body: {"v":1,"body":"<sha1 von body>",
 * "blocks":[…]}. body enthält weiter den Text aller Textblöcke für alle, damit ältere Versionen (Zurückrollen, ältere
 * App) den Inhalt ohne Blöcke und ohne Inhalte nur für Mitglieder zeigen. Passt die Prüfsumme nicht mehr (eine ältere
 * Version hat body gespeichert) oder fehlt blocks, gilt body als einzelner Textblock.
 */
final class Blocks
{
    public const MAX = 50;
    public const AUDIENCES = ['all', 'members'];
    private const ID = '/^[a-z0-9]{1,16}$/';

    /**
     * Felder je Typ, die eine Fassung je weiterer Sprache bekommen können (Plan „Mehrsprachigkeit“, PR 9b). Aufbau,
     * Daten, Links und Verweise (Termine, Ämter, Umfragen …) gelten für alle Sprachen; Listen (items) nur als Ganzes und
     * nur, solange sie gleich viele Einträge haben wie auf Deutsch.
     */
    public const TRANSLATABLE = [
        'text' => ['html'],
        'countdown' => ['title', 'running_text', 'after_text'],
        'button' => ['label'],
        'events' => ['title'],
        'contacts' => ['title'],
        'notice' => ['title', 'text'],
        'schedule' => ['title', 'items'],
        'facts' => ['title', 'items'],
        'sponsors' => ['title'],
        'map' => ['title'],
        'image' => ['alt', 'caption'],
    ];

    /** @var array<string, BlockType>|null */
    private ?array $types = null;

    public function __construct(private readonly App $app)
    {
    }

    /** @return array<string, BlockType> */
    public function types(): array
    {
        if ($this->types === null) {
            $this->types = [];
            foreach ([new TextBlock($this->app), new CountdownBlock($this->app), new ButtonBlock($this->app), new EventsBlock($this->app), new ContactsBlock($this->app), new NoticeBlock(), new DividerBlock(), new ScheduleBlock(), new FactsBlock(), new PollBlock($this->app), new AlbumBlock($this->app), new DocumentBlock($this->app), new SponsorsBlock($this->app), new MapBlock(), new ImageBlock($this->app)] as $type) {
                $this->types[$type->type()] = $type;
            }
        }
        return $this->types;
    }

    /**
     * Prüft eine Liste aus der App. Unbekannte Typen und Felder werden abgelehnt bzw. weggelassen; fehlende oder doppelte
     * Kennungen neu vergeben.
     *
     * @param int $textMax Grenze für den Text aller Textblöcke zusammen (Bytes)
     * @return list<array<string, mixed>>
     */
    public function validate(mixed $in, int $textMax): array
    {
        if (!is_array($in) || !array_is_list($in)) {
            throw ApiError::invalid(I18n::t('Ungültige Blöcke.'));
        }
        if (count($in) > self::MAX) {
            throw ApiError::invalid(I18n::t('Höchstens {max} Blöcke.', ['max' => self::MAX]));
        }
        $types = $this->types();
        $out = [];
        $seen = [];
        $text = 0;
        foreach ($in as $block) {
            if (!is_array($block)) {
                throw ApiError::invalid(I18n::t('Ungültiger Block.'));
            }
            $type = $types[$block['type'] ?? ''] ?? null;
            if ($type === null) {
                throw ApiError::invalid(I18n::t('Unbekannter Block: {type}.', ['type' => is_string($block['type'] ?? null) ? $block['type'] : '?']));
            }
            $audience = $block['audience'] ?? 'all';
            if (!in_array($audience, self::AUDIENCES, true)) {
                throw ApiError::invalid(I18n::t('Für wen ist der Block? Erlaubt sind all (alle) und members (Mitglieder).'));
            }
            $id = is_string($block['id'] ?? null) ? $block['id'] : '';
            if (preg_match(self::ID, $id) !== 1 || isset($seen[$id])) {
                $id = self::newId($seen);
            }
            $seen[$id] = true;
            $data = $type->validate($block);
            if ($type instanceof TextBlock) {
                $text += strlen((string) $data['html']);
            }
            $out[] = ['id' => $id, 'type' => $type->type(), 'audience' => $audience] + $data;
        }
        if ($text > $textMax) {
            throw ApiError::invalid(I18n::t('Der Text ist zu lang.'));
        }
        return $out;
    }

    /**
     * Inhalt aus einer Anfrage: blocks (hat Vorrang) oder body (ältere App, ein Textblock); null, wenn beides fehlt.
     *
     * @param array<string, mixed> $in
     * @return list<array<string, mixed>>|null
     */
    public function fromInput(array $in, int $textMax): ?array
    {
        if (array_key_exists('blocks', $in)) {
            return $this->validate($in['blocks'], $textMax);
        }
        if (!array_key_exists('body', $in)) {
            return null;
        }
        if ($in['body'] !== null && !is_string($in['body'])) {
            throw ApiError::invalid(I18n::t('Ungültiger Text.'));
        }
        $html = RichText::sanitize((string) $in['body']);
        $html = RichText::isEmpty($html) ? '' : $html;
        if (strlen($html) > $textMax) {
            throw ApiError::invalid(I18n::t('Der Text ist zu lang.'));
        }
        return self::fromBody($html);
    }

    /**
     * Spalten blocks und body zum Speichern; Bilder der Textblöcke gehören danach $ownerType/$ownerId, nicht mehr
     * verwendete werden gelöscht ($prune; am Schwarzen Brett nicht, dort braucht sie der Verlauf; $keep wie bei claim).
     *
     * @param list<array<string, mixed>> $blocks
     * @param list<string> $keep
     * @return array{blocks: string, body: string}
     */
    public function columns(array $blocks, User $actor, string $ownerType, int $ownerId, bool $prune = true, array $keep = []): array
    {
        $blocks = $this->claim($blocks, $actor, $ownerType, $ownerId, $prune, $keep);
        $body = self::publicBody($blocks);
        return ['blocks' => self::encode($blocks, $body), 'body' => $body];
    }

    /**
     * Bisheriger Text als Liste mit einem Textblock (leer: keine Blöcke).
     *
     * @return list<array<string, mixed>>
     */
    public static function fromBody(string $body): array
    {
        return $body === '' ? [] : [['id' => 'b1', 'type' => TextBlock::TYPE, 'audience' => 'all', 'html' => $body]];
    }

    /**
     * Gespeicherte Blöcke; ohne (oder veraltet gegenüber body) der Text als ein Textblock.
     *
     * @return list<array<string, mixed>>
     */
    public static function decode(mixed $json, string $body): array
    {
        $data = is_string($json) && $json !== '' ? json_decode($json, true) : null;
        if (!is_array($data) || ($data['body'] ?? null) !== sha1($body) || !is_array($data['blocks'] ?? null)) {
            return self::fromBody($body);
        }
        return array_values(array_filter($data['blocks'], 'is_array'));
    }

    /**
     * Für die Spalte blocks, mit der Prüfsumme des zugehörigen body.
     *
     * @param list<array<string, mixed>> $blocks
     */
    public static function encode(array $blocks, string $body): string
    {
        return (string) json_encode(['v' => 1, 'body' => sha1($body), 'blocks' => $blocks], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Text aller Textblöcke für alle (für body).
     *
     * @param list<array<string, mixed>> $blocks
     */
    public static function publicBody(array $blocks): string
    {
        $html = '';
        foreach ($blocks as $b) {
            if (($b['type'] ?? '') === TextBlock::TYPE && ($b['audience'] ?? 'all') === 'all') {
                $html .= (string) ($b['html'] ?? '');
            }
        }
        return $html;
    }

    /**
     * Bilder der Textblöcke und Bild-Blöcke gehören danach $ownerType/$ownerId (RichImageService::claim je Block); Bilder
     * des Besitzers, die in keinem Block mehr stehen, werden gelöscht (außer ohne $prune; $keep: Bilder, die woanders
     * stehen, z. B. in der Fassung einer weiteren Sprache).
     *
     * @param list<array<string, mixed>> $blocks
     * @param list<string> $keep
     * @return list<array<string, mixed>>
     */
    public function claim(array $blocks, User $actor, string $ownerType, int $ownerId, bool $prune = true, array $keep = []): array
    {
        foreach ($blocks as $i => $b) {
            if (($b['type'] ?? '') === ImageBlock::TYPE) {
                // Eigene Entwürfe umhängen, Bilder anderer Inhalte kopieren (wie im Text); fremde, nicht sichtbare fallen weg.
                $claimed = RichText::imageUids($this->app->richImages()->claim($actor, '<img data-image="' . (string) ($b['image'] ?? '') . '">', $ownerType, $ownerId));
                if ($claimed === []) {
                    throw ApiError::invalid(I18n::t('Das Bild wurde nicht gefunden. Bitte noch einmal hochladen.'));
                }
                $blocks[$i]['image'] = $claimed[0];
                $keep[] = $claimed[0];
                continue;
            }
            if (($b['type'] ?? '') !== TextBlock::TYPE || ($b['html'] ?? '') === '') {
                continue;
            }
            $html = $this->app->richImages()->claim($actor, (string) $b['html'], $ownerType, $ownerId);
            $blocks[$i]['html'] = $html;
            array_push($keep, ...RichText::imageUids($html));
        }
        if ($prune) {
            $this->app->richImages()->prune($ownerType, $ownerId, $keep);
        }
        return $blocks;
    }

    /**
     * Blöcke für $viewer: nur members-Blöcke für Mitglieder ($member), jeder Typ bereitet sich auf (render); unbekannte
     * Typen (Daten einer neueren Version) fallen weg.
     *
     * @param list<array<string, mixed>> $blocks
     * @return list<array<string, mixed>>
     */
    public function forViewer(array $blocks, ?User $viewer, bool $member, bool $manager): array
    {
        $types = $this->types();
        $out = [];
        foreach ($blocks as $b) {
            $type = $types[$b['type'] ?? ''] ?? null;
            $audience = ($b['audience'] ?? 'all') === 'members' ? 'members' : 'all';
            if ($type === null || ($audience === 'members' && !$member && !$manager)) {
                continue;
            }
            $data = $b;
            unset($data['id'], $data['type'], $data['audience']);
            $shown = $type->render($data, $viewer, $manager);
            if ($shown !== null) {
                $out[] = ['id' => (string) ($b['id'] ?? ''), 'type' => $type->type(), 'audience' => $audience] + $shown;
            }
        }
        return $out;
    }

    /**
     * Reiner Text der Blöcke für $viewer (Blöcke nur für Mitglieder nur mit $member; E-Mail, Textauszug), durch
     * Leerzeilen getrennt.
     *
     * @param list<array<string, mixed>> $blocks
     */
    public function plain(array $blocks, bool $member, ?User $viewer = null): string
    {
        $types = $this->types();
        $parts = [];
        foreach ($blocks as $b) {
            $type = $types[$b['type'] ?? ''] ?? null;
            if ($type === null || (($b['audience'] ?? 'all') === 'members' && !$member)) {
                continue;
            }
            $text = trim($type->plain($b, $viewer));
            if ($text !== '') {
                $parts[] = $text;
            }
        }
        return implode("\n\n", $parts);
    }

    /**
     * Formatierter Text der Blöcke für $viewer in einem Stück (E-Mail, Verlauf): Textblöcke wie gespeichert (mit den
     * Bildern als data-image, RichImageService::render bzw. die Mail setzen sie ein), andere Blöcke als Absatz mit
     * ihrem reinen Text. Blöcke nur für Mitglieder nur mit $member, mit $mark davor als solche gekennzeichnet.
     *
     * @param list<array<string, mixed>> $blocks
     */
    public function html(array $blocks, bool $member, ?User $viewer = null, bool $mark = false): string
    {
        $types = $this->types();
        $html = '';
        foreach ($blocks as $b) {
            $type = $types[$b['type'] ?? ''] ?? null;
            $members = ($b['audience'] ?? 'all') === 'members';
            if ($type === null || ($members && !$member)) {
                continue;
            }
            if ($type instanceof TextBlock) {
                $part = (string) ($b['html'] ?? '');
            } elseif ($type instanceof ImageBlock) {
                $part = $type->html($b);
            } else {
                $text = trim($type->plain($b, $viewer));
                $part = $text === '' ? '' : '<p>' . nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false) . '</p>';
            }
            if ($part !== '' && $members && $mark) {
                $part = '<p><em>' . htmlspecialchars(I18n::t('Nur für Mitglieder:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</em></p>' . $part;
            }
            $html .= $part;
        }
        return $html;
    }

    /**
     * Gespeicherter Inhalt in einem Feld (Verlauf am Schwarzen Brett): JSON wie encode (ohne Prüfung der Prüfsumme) als
     * Blöcke, sonst null (dann ist der Wert der bisherige formatierte Text).
     *
     * @return list<array<string, mixed>>|null
     */
    public static function stored(?string $value): ?array
    {
        if ($value === null || !str_starts_with($value, '{"v":')) {
            return null;
        }
        $data = json_decode($value, true);
        if (!is_array($data) || !is_array($data['blocks'] ?? null)) {
            return null;
        }
        return array_values(array_filter($data['blocks'], 'is_array'));
    }

    /**
     * Bilder aller Blöcke (Textblöcke und Bild-Blöcke), z. B. zum Aufräumen nicht mehr verwendeter.
     *
     * @param list<array<string, mixed>> $blocks
     * @return list<string>
     */
    public static function imageUids(array $blocks): array
    {
        $uids = [];
        foreach ($blocks as $b) {
            array_push($uids, ...self::blockImageUids($b));
        }
        return $uids;
    }

    /**
     * @param array<string, mixed> $b
     * @return list<string>
     */
    private static function blockImageUids(array $b): array
    {
        return match ($b['type'] ?? '') {
            TextBlock::TYPE => RichText::imageUids((string) ($b['html'] ?? '')),
            ImageBlock::TYPE => is_string($b['image'] ?? null) && preg_match(RichText::IMAGE_UID, $b['image']) === 1 ? [$b['image']] : [],
            default => [],
        };
    }

    /**
     * Steht das Bild nur in Blöcken für Mitglieder? (Dann sehen es andere nicht, auch wenn der Inhalt öffentlich ist.)
     *
     * @param list<array<string, mixed>> $blocks
     */
    public static function imageOnlyForMembers(array $blocks, string $uid): bool
    {
        $found = false;
        foreach ($blocks as $b) {
            if (!in_array($uid, self::blockImageUids($b), true)) {
                continue;
            }
            if (($b['audience'] ?? 'all') !== 'members') {
                return false;
            }
            $found = true;
        }
        return $found;
    }

    /**
     * Blöcke in einer weiteren Sprache: Felder aus $tr (Kennung des Blocks => Feld => Wert), sonst die deutschen. Dazu,
     * wie viele übersetzbare Felder (mit deutschem Inhalt) es gibt und wie viele davon übersetzt sind.
     *
     * @param list<array<string, mixed>> $blocks
     * @param array<string, array<string, mixed>> $tr
     * @return array{0: list<array<string, mixed>>, 1: int, 2: int} Blöcke, übersetzbare Felder, davon übersetzt
     */
    public static function translate(array $blocks, array $tr): array
    {
        $total = 0;
        $done = 0;
        foreach ($blocks as $i => $b) {
            $own = $tr[(string) ($b['id'] ?? '')] ?? [];
            foreach (self::TRANSLATABLE[$b['type'] ?? ''] ?? [] as $field) {
                if (self::isEmptyValue($b[$field] ?? null)) {
                    continue;
                }
                $total++;
                $value = $own[$field] ?? null;
                if (self::isEmptyValue($value) || (is_array($b[$field]) && (!is_array($value) || count($value) !== count($b[$field])))) {
                    continue;
                }
                $blocks[$i][$field] = $value;
                $done++;
            }
        }
        return [$blocks, $total, $done];
    }

    /**
     * Fassung in einer weiteren Sprache aus der App prüfen: nur Blöcke, die es auf Deutsch gibt, nur übersetzbare
     * Felder, leere fallen weg. Jeder Block wird mit den deutschen Feldern zusammen von seinem Typ geprüft (Längen).
     *
     * @param list<array<string, mixed>> $blocks die deutschen Blöcke
     * @return array<string, array<string, mixed>> Kennung => Feld => Wert
     */
    public function validateTranslation(array $blocks, mixed $in, int $textMax): array
    {
        if ($in === null || $in === []) {
            return [];
        }
        if (!is_array($in)) {
            throw ApiError::invalid(I18n::t('Ungültige Blöcke.'));
        }
        $types = $this->types();
        $out = [];
        $text = 0;
        foreach ($blocks as $b) {
            $id = (string) ($b['id'] ?? '');
            $given = $in[$id] ?? null;
            $type = $types[$b['type'] ?? ''] ?? null;
            $fields = self::TRANSLATABLE[$b['type'] ?? ''] ?? [];
            if (!is_array($given) || $type === null || $fields === []) {
                continue;
            }
            $merged = $b;
            foreach ($fields as $field) {
                if (array_key_exists($field, $given)) {
                    $merged[$field] = $given[$field];
                }
            }
            $clean = $type->validate($merged);
            $own = [];
            foreach ($fields as $field) {
                if (array_key_exists($field, $given) && !self::isEmptyValue($clean[$field] ?? null)) {
                    $own[$field] = $clean[$field];
                }
            }
            if ($own !== []) {
                $out[$id] = $own;
                $text += strlen((string) ($own['html'] ?? ''));
            }
        }
        if ($text > $textMax) {
            throw ApiError::invalid(I18n::t('Der Text ist zu lang.'));
        }
        return $out;
    }

    /**
     * Bilder in den Textblöcken einer Fassung gehören danach $ownerType/$ownerId (wie claim, ohne zu löschen).
     *
     * @param array<string, array<string, mixed>> $tr
     * @return array<string, array<string, mixed>>
     */
    public function claimTranslation(array $tr, User $actor, string $ownerType, int $ownerId): array
    {
        foreach ($tr as $id => $fields) {
            if (is_string($fields['html'] ?? null) && $fields['html'] !== '') {
                $tr[$id]['html'] = $this->app->richImages()->claim($actor, $fields['html'], $ownerType, $ownerId);
            }
        }
        return $tr;
    }

    /**
     * Bilder in den Textblöcken einer Fassung.
     *
     * @param array<string, array<string, mixed>> $tr
     * @return list<string>
     */
    public static function translationImages(array $tr): array
    {
        $uids = [];
        foreach ($tr as $fields) {
            array_push($uids, ...RichText::imageUids(is_string($fields['html'] ?? null) ? $fields['html'] : ''));
        }
        return $uids;
    }

    /**
     * Fassung zum Bearbeiten: Bilder als Adressen wie bei den deutschen Blöcken (RichImageService::render).
     *
     * @param array<string, array<string, mixed>> $tr
     * @return array<string, array<string, mixed>>
     */
    public function renderTranslation(array $tr): array
    {
        foreach ($tr as $id => $fields) {
            if (is_string($fields['html'] ?? null) && $fields['html'] !== '') {
                $tr[$id]['html'] = $this->app->richImages()->render($fields['html']);
            }
        }
        return $tr;
    }

    /**
     * Gespeicherte Fassung (JSON) als Kennung => Feld => Wert.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function decodeTranslation(?string $json): array
    {
        $data = is_string($json) && $json !== '' ? json_decode($json, true) : null;
        return is_array($data) ? array_filter($data, 'is_array') : [];
    }

    /** @param array<string, array<string, mixed>> $tr */
    public static function encodeTranslation(array $tr): string
    {
        return $tr === [] ? '' : (string) json_encode($tr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function isEmptyValue(mixed $v): bool
    {
        return $v === null || $v === '' || $v === [];
    }

    /** @param array<string, bool> $seen */
    private static function newId(array $seen): string
    {
        do {
            $id = 'b' . bin2hex(random_bytes(4));
        } while (isset($seen[$id]));
        return $id;
    }
}
