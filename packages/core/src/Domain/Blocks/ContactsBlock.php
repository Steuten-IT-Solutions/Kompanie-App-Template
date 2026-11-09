<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain\Blocks;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Capability;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Domain\Input;
use KompanieApp\Core\Http\ApiError;

/**
 * Ansprechpartner: ausgewählte Ämter mit ihren aktuellen Inhabern (laufende Amtszeit), Name und Foto wie in den Ämtern
 * (OfficeService::overview, gleiche Regeln für Fotos). E-Mail-Adresse und Handynummer nur für Lesende mit view_personal
 * (MemberService::personal). Ist der Bereich Ämter aus, fällt der Block weg (außer für die Pflege); ohne view_offices
 * bleibt die Liste leer. Gelöschte Ämter fallen still weg.
 */
final class ContactsBlock implements BlockType
{
    public const TYPE = 'contacts';
    public const TITLE_MAX = 100;
    public const MAX = 20;

    public function __construct(private readonly App $app)
    {
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function validate(array $in): array
    {
        $ids = $in['office_ids'] ?? null;
        if (!is_array($ids) || !array_is_list($ids) || $ids === []) {
            throw ApiError::invalid('Bitte mindestens ein Amt für die Ansprechpartner auswählen.');
        }
        if (count($ids) > self::MAX) {
            throw ApiError::invalid(sprintf('Höchstens %d Ämter je Block.', self::MAX));
        }
        $known = array_column($this->app->offices()->repository()->offices(), 'id');
        $out = [];
        foreach ($ids as $id) {
            $id = Input::integer($id);
            if ($id === null || !in_array($id, $known, true)) {
                throw ApiError::invalid('Dieses Amt gibt es nicht.');
            }
            $out[$id] = $id;
        }
        return ['title' => Field::line($in['title'] ?? '', self::TITLE_MAX, 'Die Überschrift der Ansprechpartner'), 'office_ids' => array_values($out)];
    }

    public function render(array $data, ?User $viewer, bool $manager): ?array
    {
        if (!$manager && !$this->app->features()->enabled('offices')) {
            return null;
        }
        return ['title' => (string) ($data['title'] ?? ''), 'office_ids' => self::ids($data), 'offices' => $this->contacts($data, $viewer)];
    }

    /** Je Amt eine Zeile „Amt: Name (E-Mail, Telefon), Name“, mit der Überschrift davor; bei einer Person das Amt in der Einzahl. */
    public function plain(array $data, ?User $viewer = null): string
    {
        $lines = [];
        foreach ($this->contacts($data, $viewer) as $o) {
            $names = array_map(static function (array $p): string {
                $contact = array_values(array_filter([$p['email'] ?? '', $p['phone'] ?? ''], static fn (string $v): bool => $v !== ''));
                return $p['name'] . ($contact !== [] ? ' (' . implode(', ', $contact) . ')' : '');
            }, $o['holders']);
            // Eine Person (oder niemand): das Amt in der Einzahl; mehrere: die Bezeichnung
            $label = count($names) <= 1 && $o['holder_title'] !== null ? $o['holder_title'] : $o['title'];
            $lines[] = $label . ': ' . ($names === [] ? 'zurzeit nicht besetzt' : implode(', ', $names));
        }
        if ($lines === [] || !$this->app->features()->enabled('offices')) {
            return '';
        }
        $title = (string) ($data['title'] ?? '');
        return ($title !== '' ? $title . "\n" : '') . implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $data
     * @return list<int>
     */
    private static function ids(array $data): array
    {
        return array_values(array_filter(is_array($data['office_ids'] ?? null) ? $data['office_ids'] : [], 'is_int'));
    }

    /**
     * Ämter in der Reihenfolge des Blocks mit den Inhabern ihrer laufenden Amtszeiten.
     *
     * @param array<string, mixed> $data
     * @return list<array{id: int, title: string, holder_title: ?string, holders: list<array<string, mixed>>}>
     */
    private function contacts(array $data, ?User $viewer): array
    {
        $who = $viewer ?? $this->app->anonymous();
        if (!$this->app->features()->enabled('offices') || !($who->can(Capability::ViewOffices) || $who->can(Capability::ManageOffices))) {
            return [];
        }
        $byId = array_column($this->app->offices()->overview($viewer)['offices'], null, 'id');
        $personal = $viewer !== null && $viewer->can(Capability::ViewPersonal);
        $out = [];
        foreach (self::ids($data) as $id) {
            $office = $byId[$id] ?? null;
            if ($office === null) {
                continue;
            }
            $holders = [];
            foreach ($office['terms'] as $term) {
                if (!$term['current']) {
                    continue;
                }
                foreach ($term['holders'] as $h) {
                    $p = ['name' => (string) $h['name'], 'photo_url' => $h['photo_url'] ?? null];
                    // Konto nur, wo die Ämter es zeigen (Mitgliederliste, Pflege): dann auch die Kontaktdaten
                    if ($personal && isset($h['user_id'])) {
                        try {
                            $info = $this->app->members()->personal((int) $h['user_id']);
                            $p += ['email' => $info['email'], 'phone' => $info['phone']];
                        } catch (ApiError) {
                            // Konto inzwischen gelöscht
                        }
                    }
                    $holders[] = $p;
                }
            }
            // Einzahl wie im Zeitstrahl einer Person („Prinz“ statt „Prinzen“), null ohne
            $out[] = ['id' => $id, 'title' => (string) $office['title'], 'holder_title' => $office['holder_title'], 'holders' => $holders];
        }
        return $out;
    }
}
