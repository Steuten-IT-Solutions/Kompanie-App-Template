<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Push\PushService;

/**
 * Die Bereiche (Funktionen) der App und ob sie eingeschaltet sind – die einzige Stelle, die das sagt.
 *
 * Welche Bereiche eine Kompanie nutzt, entscheidet ihr Admin in der App (Ersteinrichtung bzw.
 * Einstellungen → Bereiche), nicht mehr kompanie.json. Ein ausgeschalteter Bereich verschwindet aus
 * der App und ist auf dem Server gesperrt; seine Daten bleiben erhalten.
 *
 * Neue Bereiche tragen sich in DEFINITIONS ein (Bezeichnung, Beschreibung, Abhängigkeiten).
 */
final class Features
{
    public const SETTING = 'features';

    /**
     * Alle Bereiche in der Reihenfolge der Anzeige.
     * - label: Standardbezeichnung (Bereiche mit eigener Bezeichnung in kompanie.json nehmen diese)
     * - description: ein Satz, wofür der Bereich da ist
     * - requires: Bereiche, ohne die dieser nicht eingeschaltet werden kann
     * - setup: Abschnitt in den Einstellungen, falls der Bereich eingerichtet werden muss
     * - role: Rechte der Standardrolle, die der Bereich beim Einschalten mitbringt („<Bereich> verwalten“);
     *   null, wenn er keine eigenen Rechte braucht
     *
     * @var array<string, array{label: string, description: string, requires: string[], setup: string|null, role: string[]|null}>
     */
    public const DEFINITIONS = [
        'events' => ['label' => 'Termine', 'description' => 'Öffentliche Termine, die auch Gäste ohne Anmeldung sehen.', 'requires' => [], 'setup' => null, 'role' => ['member', 'manage_events']],
        'calendar' => ['label' => 'Kalender', 'description' => 'Alle Termine aus Google Kalender für angemeldete Mitglieder.', 'requires' => [], 'setup' => 'kalender', 'role' => null],
        'news' => ['label' => 'News', 'description' => 'Neuigkeiten der Kompanie, z. B. aus den Beiträgen der Webseite.', 'requires' => [], 'setup' => 'news', 'role' => null],
        'gallery' => ['label' => 'Galerie', 'description' => 'Fotoalben aus Google Drive, mit internen Bildern nur für Mitglieder.', 'requires' => [], 'setup' => 'galerie', 'role' => null],
        'points' => ['label' => 'Punkte', 'description' => 'Punkte für Mitglieder, mit Katalog und Verlauf.', 'requires' => [], 'setup' => null, 'role' => ['member', 'manage_points']],
        'penalties' => ['label' => 'Strafen', 'description' => 'Strafen der Mitglieder, offen oder bezahlt.', 'requires' => [], 'setup' => null, 'role' => ['member', 'manage_penalties']],
        'ranks' => ['label' => 'Dienstgrade', 'description' => 'Dienstgrade der Mitglieder mit Verlauf; zusammen mit Punkten mit Punkteschwellen und Beförderungsübersicht.', 'requires' => [], 'setup' => null, 'role' => ['member', 'manage_ranks']],
        'titles' => ['label' => 'Titel', 'description' => 'Ehrentitel und Ämter der Mitglieder, z. B. Ehrenmitglied, unabhängig von Dienstgrad und Punkten.', 'requires' => [], 'setup' => null, 'role' => ['member', 'manage_titles']],
        'attendance' => ['label' => 'Anwesenheit', 'description' => 'Anwesenheitslisten je Termin; zusammen mit Punkten und Strafen gesammelt buchen.', 'requires' => [], 'setup' => null, 'role' => ['member', 'manage_attendance']],
        'documents' => ['label' => 'Dokumente', 'description' => 'Dateien wie Satzung, Protokolle und Formulare für Mitglieder, nach Kategorien, auch offline.', 'requires' => [], 'setup' => null, 'role' => ['member', 'manage_documents']],
        'board' => ['label' => 'Schwarzes Brett', 'description' => 'Dringende Nachrichten oben auf jeder Seite, so lange gewünscht (standardmäßig 24 Stunden), mit Benachrichtigung und auf Wunsch per E-Mail an alle Mitglieder und Rollen mit dem internen Kalender (z. B. Anwärter).', 'requires' => [], 'setup' => null, 'role' => ['member', 'post_board']],
        // Umfragen (Issue #4): Laufende Umfragen stehen am Schwarzen Brett, deshalb nur zusammen mit ihm.
        'polls' => ['label' => 'Umfragen', 'description' => 'Umfragen mit einer Frage für Mitglieder, namentlich oder anonym; laufende stehen am Schwarzen Brett.', 'requires' => ['board'], 'setup' => null, 'role' => ['member', 'manage_polls']],
        // Geburtstage (Issue #1): kein eigenes Recht; das Datum anderer pflegt, wer Mitglieder anlegen darf (create_users).
        'birthdays' => ['label' => 'Geburtstage', 'description' => 'Geburtstage der Mitglieder: anstehende bei den Terminen, Benachrichtigung am Tag selbst; jedes Mitglied entscheidet, ob andere ihn sehen.', 'requires' => [], 'setup' => null, 'role' => null],
        // Inventar (Issue #6): nur für alle mit manage_inventory, Mitglieder ohne das Recht sehen es nicht.
        'inventory' => ['label' => 'Inventar', 'description' => 'Was die Kompanie besitzt, wie viel und wo, mit Umlagern und Verlauf; nur für alle, die es verwalten.', 'requires' => [], 'setup' => null, 'role' => ['member', 'manage_inventory']],
        // Mitgliederliste (Issue #112: öffentlich): Namen, Fotos, Dienstgrade und Titel der Mitglieder und Anwärter; kein eigenes Recht.
        'directory' => ['label' => 'Mitglieder', 'description' => 'Liste der Mitglieder und Rollen mit dem internen Kalender (z. B. Anwärter) mit Foto, Dienstgrad und Titeln, für alle sichtbar, auch ohne Anmeldung.', 'requires' => [], 'setup' => null, 'role' => null],
        // Ämter (Issue #112): Zeitstrahl aller Ämter mit früheren Inhabern, öffentlich; im Tab der Mitgliederliste.
        'offices' => ['label' => 'Ämter', 'description' => 'Wer welches Amt wann hatte, auch frühere Inhaber, als Zeitstrahl für alle sichtbar; laufende Ämter können Titel vergeben.', 'requires' => [], 'setup' => null, 'role' => ['member', 'manage_offices']],
        // Listen (Issue #121): nur für alle mit manage_lists, Werte der Mitglieder nur, soweit sie sie ohnehin sehen.
        'lists' => ['label' => 'Listen', 'description' => 'Listen zum Ausdrucken oder als CSV, z. B. Unterschriften- oder Teilnehmerlisten, mit eigenen Spalten und den Mitgliedern als Zeilen; nur für alle, die sie verwalten.', 'requires' => [], 'setup' => null, 'role' => ['member', 'manage_lists']],
        // E-Mails: in der Verwaltung schreiben und versenden (An, Cc, Bcc); braucht den Mailversand (Einstellungen → E-Mail).
        'mail' => ['label' => 'E-Mails', 'description' => 'E-Mails aus der App an Rollen, einzelne Mitglieder und beliebige Adressen schreiben, mit An, Cc und Bcc und Versandprotokoll; nur für alle, die sie versenden dürfen.', 'requires' => [], 'setup' => 'email', 'role' => ['member', 'send_mail']],
    ];

    /** @var array<string, bool>|null */
    private ?array $state = null;

    public function __construct(private readonly App $app)
    {
    }

    /** @return string[] */
    public static function keys(): array
    {
        return array_keys(self::DEFINITIONS);
    }

    public function enabled(string $feature): bool
    {
        return $this->all()[$feature] ?? false;
    }

    /**
     * Ein- bzw. ausgeschaltet je Bereich. Ohne gespeicherte Einstellung ist alles aus:
     * Der Admin wählt bei der Ersteinrichtung, was die Kompanie nutzt.
     *
     * @return array<string, bool>
     */
    public function all(): array
    {
        if ($this->state === null) {
            $saved = $this->app->settings->get(self::SETTING);
            $saved = is_array($saved) ? $saved : [];
            $this->state = [];
            foreach (self::keys() as $key) {
                $this->state[$key] = (bool) ($saved[$key] ?? false);
            }
        }
        return $this->state;
    }

    /**
     * Arbeiten Dienstgrade mit Punkten zusammen (Schwellen, Beförderungsübersicht, „noch N Punkte bis …“)?
     * Die einzige Stelle für diese Kopplung (Issue #9).
     */
    public function ranksUsePoints(): bool
    {
        return $this->enabled('ranks') && $this->enabled('points');
    }

    public function isStored(): bool
    {
        return is_array($this->app->settings->get(self::SETTING));
    }

    /**
     * Liste für die Ersteinrichtung und die Einstellungen der Admins.
     *
     * @return array<int, array{key: string, label: string, description: string, enabled: bool, requires: string[], configured: bool|null, setup: string|null}>
     */
    public function describe(): array
    {
        $out = [];
        foreach (self::DEFINITIONS as $key => $def) {
            $out[] = [
                'key' => $key,
                'label' => $this->app->config->sectionLabel($key, $def['label']),
                'description' => $def['description'],
                'enabled' => $this->enabled($key),
                'requires' => $def['requires'],
                // null: muss nicht eingerichtet werden
                'configured' => $def['setup'] === null ? null : $this->configured($key),
                'setup' => $def['setup'],
            ];
        }
        return $out;
    }

    /** Ist ein Bereich eingerichtet (z. B. Kalender mit Adresse)? Unabhängig davon, ob er an ist. */
    public function configured(string $feature): bool
    {
        return match ($feature) {
            'calendar' => $this->app->calendar()->isConfigured(),
            'gallery' => $this->app->gallery()->isConfigured(),
            'news' => $this->app->news->available(),
            'mail' => $this->app->mail()->configured(),
            default => true,
        };
    }

    /**
     * Übernimmt die mitgeschickten Schalter; nicht genannte Bereiche bleiben, wie sie sind.
     * Unbekannte Bereiche, Werte, die kein Wahrheitswert sind, und fehlende Abhängigkeiten → 400.
     *
     * @param array<string, mixed> $in
     * @return array<string, bool>
     */
    public function update(array $in): array
    {
        $next = $this->all();
        foreach ($in as $key => $value) {
            if (!array_key_exists((string) $key, self::DEFINITIONS)) {
                throw ApiError::invalid('Unbekannter Bereich: ' . $key . '.');
            }
            if (!is_bool($value)) {
                throw ApiError::invalid('Ungültiger Wert für ' . $key . ' (an oder aus erwartet).');
            }
            $next[(string) $key] = $value;
        }
        foreach ($next as $key => $on) {
            foreach (self::DEFINITIONS[$key]['requires'] as $required) {
                if ($on && !$next[$required]) {
                    throw ApiError::invalid(sprintf(
                        '„%s“ braucht „%s“. Bitte beide einschalten.',
                        $this->app->config->sectionLabel($key, self::DEFINITIONS[$key]['label']),
                        $this->app->config->sectionLabel($required, self::DEFINITIONS[$required]['label']),
                    ));
                }
            }
        }
        $before = $this->all();
        $this->save($next);
        // Standardrollen der Bereiche (Issue #28): beim Einschalten anlegen, beim Ausschalten entfernen,
        // wenn niemand sie hat.
        foreach ($next as $key => $on) {
            $caps = self::DEFINITIONS[$key]['role'];
            if ($caps !== null && $on !== ($before[$key] ?? false)) {
                $this->app->roles()->featureChanged($key, $on, $this->roleLabel($key), $caps);
            }
        }
        // Schwarzes Brett (Issue #5), Umfragen (Issue #4) und Geburtstage (Issue #1): Beim ersten Einschalten bekommen
        // bestehende Abos die neue Art dazu (einmalig je Art, siehe PushService::offerTypeToExisting).
        foreach (['board' => PushService::TYPE_BOARD, 'polls' => PushService::TYPE_POLLS, 'birthdays' => PushService::TYPE_BIRTHDAYS] as $feature => $type) {
            if ($next[$feature] && !($before[$feature] ?? false)) {
                try {
                    $this->app->push()->offerTypeToExisting($type);
                } catch (\Throwable $e) {
                    error_log('Kompanie-App: Benachrichtigungen „' . $type . '“ nicht eingeschaltet: ' . $e->getMessage());
                }
            }
        }
        return $next;
    }

    /** Name der Standardrolle eines Bereichs, z. B. „Punkte verwalten“ (mit der Bezeichnung aus kompanie.json). */
    public function roleLabel(string $feature): string
    {
        return $this->app->config->sectionLabel($feature, self::DEFINITIONS[$feature]['label']) . ' verwalten';
    }

    /**
     * Einmalig beim ersten Start dieser Version: Bestehende Installationen übernehmen die Bereiche
     * aus der alten kompanie.json (Standard dort: an), damit nichts plötzlich verschwindet.
     * Neue Installationen starten ohne Bereiche.
     */
    public function initialize(bool $existingInstallation): void
    {
        if ($this->isStored()) {
            return;
        }
        $state = [];
        foreach (self::keys() as $key) {
            // Dienstgrade, Titel, Anwesenheit, Dokumente, das Schwarze Brett, Umfragen, Geburtstage, Inventar, die
            // Mitgliederliste, Ämter und Listen gab es in den alten Versionen nicht: nie aus kompanie.json einschalten.
            $state[$key] = $existingInstallation && !in_array($key, ['ranks', 'titles', 'attendance', 'documents', 'board', 'polls', 'birthdays', 'inventory', 'directory', 'offices', 'lists'], true) && $this->app->config->legacyFeature($key);
        }
        $this->save($state);
    }

    /** @param array<string, bool> $state */
    private function save(array $state): void
    {
        $this->app->settings->set(self::SETTING, $state);
        $this->state = $state;
        $this->app->scheduleTasks();
    }
}
