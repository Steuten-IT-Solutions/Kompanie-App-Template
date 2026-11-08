<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain\Blocks;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Domain\RichText;
use KompanieApp\Core\Http\ApiError;

/**
 * Inhalte aus Blöcken (eigene Seiten, später News und Schwarzes Brett): eine geordnete Liste, jeder Block mit id, type,
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
            foreach ([new TextBlock($this->app), new CountdownBlock($this->app)] as $type) {
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
            throw ApiError::invalid('Ungültige Blöcke.');
        }
        if (count($in) > self::MAX) {
            throw ApiError::invalid(sprintf('Höchstens %d Blöcke.', self::MAX));
        }
        $types = $this->types();
        $out = [];
        $seen = [];
        $text = 0;
        foreach ($in as $block) {
            if (!is_array($block)) {
                throw ApiError::invalid('Ungültiger Block.');
            }
            $type = $types[$block['type'] ?? ''] ?? null;
            if ($type === null) {
                throw ApiError::invalid('Unbekannter Block: ' . (is_string($block['type'] ?? null) ? $block['type'] : '?') . '.');
            }
            $audience = $block['audience'] ?? 'all';
            if (!in_array($audience, self::AUDIENCES, true)) {
                throw ApiError::invalid('Für wen ist der Block? Erlaubt sind all (alle) und members (Mitglieder).');
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
            throw ApiError::invalid('Der Text ist zu lang.');
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
            throw ApiError::invalid('Ungültiger Text.');
        }
        $html = RichText::sanitize((string) $in['body']);
        $html = RichText::isEmpty($html) ? '' : $html;
        if (strlen($html) > $textMax) {
            throw ApiError::invalid('Der Text ist zu lang.');
        }
        return self::fromBody($html);
    }

    /**
     * Spalten blocks und body zum Speichern; Bilder der Textblöcke gehören danach $ownerType/$ownerId, nicht mehr
     * verwendete werden gelöscht.
     *
     * @param list<array<string, mixed>> $blocks
     * @return array{blocks: string, body: string}
     */
    public function columns(array $blocks, User $actor, string $ownerType, int $ownerId): array
    {
        $blocks = $this->claim($blocks, $actor, $ownerType, $ownerId);
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
     * Bilder der Textblöcke gehören danach $ownerType/$ownerId (RichImageService::claim je Block); Bilder des Besitzers,
     * die in keinem Block mehr stehen, werden gelöscht.
     *
     * @param list<array<string, mixed>> $blocks
     * @return list<array<string, mixed>>
     */
    public function claim(array $blocks, User $actor, string $ownerType, int $ownerId): array
    {
        $keep = [];
        foreach ($blocks as $i => $b) {
            if (($b['type'] ?? '') !== TextBlock::TYPE || ($b['html'] ?? '') === '') {
                continue;
            }
            $html = $this->app->richImages()->claim($actor, (string) $b['html'], $ownerType, $ownerId);
            $blocks[$i]['html'] = $html;
            array_push($keep, ...RichText::imageUids($html));
        }
        $this->app->richImages()->prune($ownerType, $ownerId, $keep);
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
     * Reiner Text aller Blöcke für alle (E-Mail, Textauszug), durch Leerzeilen getrennt.
     *
     * @param list<array<string, mixed>> $blocks
     */
    public function plain(array $blocks, bool $member): string
    {
        $types = $this->types();
        $parts = [];
        foreach ($blocks as $b) {
            $type = $types[$b['type'] ?? ''] ?? null;
            if ($type === null || (($b['audience'] ?? 'all') === 'members' && !$member)) {
                continue;
            }
            $text = trim($type->plain($b));
            if ($text !== '') {
                $parts[] = $text;
            }
        }
        return implode("\n\n", $parts);
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
            if (($b['type'] ?? '') !== TextBlock::TYPE || !in_array($uid, RichText::imageUids((string) ($b['html'] ?? '')), true)) {
                continue;
            }
            if (($b['audience'] ?? 'all') !== 'members') {
                return false;
            }
            $found = true;
        }
        return $found;
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
