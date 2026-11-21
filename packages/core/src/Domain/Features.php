<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\I18n\I18n;
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
     * - requires: Bereiche, ohne die dieser nicht eingeschaltet werden kann
     * - setup: Abschnitt in den Einstellungen, falls der Bereich eingerichtet werden muss
     * - role: Rechte der Standardrolle, die der Bereich beim Einschalten mitbringt („<Bereich> verwalten“);
     *   null, wenn er keine eigenen Rechte braucht
     *
     * @var array<string, array{label: string, requires: string[], setup: string|null, role: string[]|null}>
     */
    public const DEFINITIONS = [
        'events' => ['label' => 'Termine', 'requires' => [], 'setup' => null, 'role' => ['member', 'manage_events']],
        // Interner Kalender: Termine in der App gepflegt, aus Google Kalender oder einer iCal-Adresse (CalendarService). Die
        // Standardrolle „Kalender verwalten“ gibt es nur mit der Quelle „In der App“ (roleOf).
        'calendar' => ['label' => 'Kalender', 'requires' => [], 'setup' => 'kalender', 'role' => null],
        // News: aus den Beiträgen einer Webseite, einem Feed oder in der App geschrieben (NewsService). Die Standardrolle
        // „News verwalten“ gibt es nur mit der Quelle „In der App“ oder den Beiträgen der WordPress-Seite (roleOf); sonst gibt es nichts zu pflegen.
        'news' => ['label' => 'News', 'requires' => [], 'setup' => 'news', 'role' => null],
        'gallery' => ['label' => 'Galerie', 'requires' => [], 'setup' => 'galerie', 'role' => ['member', 'view_submissions', 'review_gallery', 'manage_gallery']],
        'points' => ['label' => 'Punkte', 'requires' => [], 'setup' => null, 'role' => ['member', 'view_points', 'manage_points']],
        'penalties' => ['label' => 'Strafen', 'requires' => [], 'setup' => null, 'role' => ['member', 'view_penalties', 'manage_penalties']],
        'ranks' => ['label' => 'Dienstgrade', 'requires' => [], 'setup' => null, 'role' => ['member', 'view_promotions', 'manage_ranks']],
        'titles' => ['label' => 'Titel', 'requires' => [], 'setup' => null, 'role' => ['member', 'view_titles', 'manage_titles']],
        'attendance' => ['label' => 'Anwesenheit', 'requires' => [], 'setup' => null, 'role' => ['member', 'view_attendance', 'manage_attendance']],
        'documents' => ['label' => 'Dokumente', 'requires' => [], 'setup' => null, 'role' => ['member', 'view_restricted_documents', 'manage_documents']],
        'board' => ['label' => 'Schwarzes Brett', 'requires' => [], 'setup' => null, 'role' => ['member', 'view_board_history', 'post_board']],
        // Umfragen (Issue #4): Laufende Umfragen stehen am Schwarzen Brett, deshalb nur zusammen mit ihm.
        'polls' => ['label' => 'Umfragen', 'requires' => ['board'], 'setup' => null, 'role' => ['member', 'view_poll_results', 'manage_polls']],
        // Geburtstage (Issue #1): kein eigenes Recht; das Datum anderer pflegt, wer Mitglieder anlegen darf (create_users).
        'birthdays' => ['label' => 'Geburtstage', 'requires' => [], 'setup' => null, 'role' => null],
        // Inventar (Issue #6): nur für alle mit manage_inventory, Mitglieder ohne das Recht sehen es nicht.
        'inventory' => ['label' => 'Inventar', 'requires' => [], 'setup' => null, 'role' => ['member', 'view_inventory', 'manage_inventory']],
        // Mitgliederliste (Issue #112: öffentlich): Namen, Fotos, Dienstgrade und Titel der Mitglieder und Anwärter; kein eigenes Recht.
        'directory' => ['label' => 'Mitglieder', 'requires' => [], 'setup' => null, 'role' => null],
        // Ämter (Issue #112): Zeitstrahl aller Ämter mit früheren Inhabern, öffentlich; im Tab der Mitgliederliste.
        'offices' => ['label' => 'Ämter', 'requires' => [], 'setup' => null, 'role' => ['member', 'manage_offices']],
        // Listen (Issue #121): nur für alle mit manage_lists, Werte der Mitglieder nur, soweit sie sie ohnehin sehen.
        'lists' => ['label' => 'Listen', 'requires' => [], 'setup' => null, 'role' => ['member', 'view_lists', 'manage_lists']],
        // E-Mails: in der Verwaltung schreiben und versenden (An, Cc, Bcc); braucht den Mailversand (Einstellungen → E-Mail).
        'mail' => ['label' => 'E-Mails', 'requires' => [], 'setup' => 'email', 'role' => ['member', 'view_mail', 'send_mail']],
        // Sponsoren (Issue #279): Logos mit Link, öffentlich (view_sponsors, anfangs auch Gast).
        'companies' => ['label' => 'Kompanien', 'requires' => [], 'setup' => null, 'role' => ['member', 'view_company_members', 'manage_companies']],
        'sponsors' => ['label' => 'Sponsoren', 'requires' => [], 'setup' => null, 'role' => ['member', 'manage_sponsors']],
        // Eigene Seiten (Issue #287): freie Texte mit Bildern, öffentlich oder für Mitglieder.
        'pages' => ['label' => 'Seiten', 'requires' => [], 'setup' => null, 'role' => ['member', 'manage_pages']],
        // Webseite (Issue #273): eine eigene Seite als Startseite unter der Adresse des Webspace, ohne App und Anmeldung;
        // nur in der eigenständigen Version (HOSTED).
        'website' => ['label' => 'Webseite', 'requires' => ['pages'], 'setup' => null, 'role' => ['member', 'manage_website']],
        // Passive: interne Liste passiver Mitglieder ohne Konto, mit Betreuer (Domain\PassiveMemberService).
        'passive' => ['label' => 'Passive', 'requires' => [], 'setup' => null, 'role' => ['member', 'view_passive', 'manage_passive']],
    ];

    /**
     * Bereiche, die der Adapter anbieten muss: die Webseite nur mit einem Ort dafür (App::$websiteHost; WordPress
     * pflegt seine Seiten selbst). Ohne ihn fehlt der Bereich in der Liste und ist immer aus.
     */
    private const HOSTED = ['website'];

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
                $this->state[$key] = (bool) ($saved[$key] ?? false) && $this->available($key);
            }
        }
        return $this->state;
    }

    /** Gibt es den Bereich in dieser Installation? (Siehe HOSTED.) */
    public function available(string $feature): bool
    {
        return !in_array($feature, self::HOSTED, true) || $this->app->websiteHost !== null;
    }

    /** Ein Satz, wofür der Bereich da ist (in der Sprache der Anfrage). */
    public static function description(string $feature): string
    {
        return match ($feature) {
            'events' => I18n::t('Öffentliche Termine, die auch Gäste ohne Anmeldung sehen.'),
            'calendar' => I18n::t('Interne Termine für angemeldete Mitglieder, getrennt von den öffentlichen: direkt in der App gepflegt, aus Google Kalender oder einer iCal-Adresse.'),
            'news' => I18n::t('Neuigkeiten der Kompanie: aus den Beiträgen der Webseite, einem Feed oder direkt in der App geschrieben.'),
            'gallery' => I18n::t('Fotoalben aus Google Drive oder nur in der App, mit internen Bildern nur für Mitglieder; auf Wunsch reichen Mitglieder Bilder zur Freigabe ein.'),
            'points' => I18n::t('Punkte für Mitglieder, mit Katalog und Verlauf.'),
            'penalties' => I18n::t('Strafen der Mitglieder, offen oder bezahlt.'),
            'ranks' => I18n::t('Dienstgrade der Mitglieder mit Verlauf; zusammen mit Punkten mit Punkteschwellen und Beförderungsübersicht.'),
            'titles' => I18n::t('Ehrentitel und Ämter der Mitglieder, z. B. Ehrenmitglied, unabhängig von Dienstgrad und Punkten.'),
            'attendance' => I18n::t('Anwesenheitslisten je Termin; zusammen mit Punkten und Strafen gesammelt buchen.'),
            'documents' => I18n::t('Dateien wie Satzung, Protokolle und Formulare für Mitglieder, nach Kategorien, auch offline.'),
            'board' => I18n::t('Dringende Nachrichten oben auf jeder Seite, so lange gewünscht (standardmäßig 24 Stunden), mit Benachrichtigung und auf Wunsch per E-Mail an alle Mitglieder und Rollen mit dem internen Kalender (z. B. Anwärter).'),
            'polls' => I18n::t('Umfragen mit einer Frage für Mitglieder, namentlich oder anonym; laufende stehen am Schwarzen Brett.'),
            'birthdays' => I18n::t('Geburtstage der Mitglieder: anstehende bei den Terminen, Benachrichtigung am Tag selbst; jedes Mitglied entscheidet, ob andere ihn sehen.'),
            'inventory' => I18n::t('Was die Kompanie besitzt, wie viel und wo, mit Umlagern und Verlauf; nur für alle, die es sehen oder verwalten dürfen.'),
            'directory' => I18n::t('Liste der Mitglieder und Rollen mit dem internen Kalender (z. B. Anwärter) mit Foto, Dienstgrad und Titeln, für alle sichtbar, auch ohne Anmeldung.'),
            'offices' => I18n::t('Wer welches Amt wann hatte, auch frühere Inhaber, als Zeitstrahl für alle sichtbar; laufende Ämter können Titel vergeben.'),
            'lists' => I18n::t('Listen zum Ausdrucken oder als CSV, z. B. Unterschriften- oder Teilnehmerlisten, mit eigenen Spalten und den Mitgliedern als Zeilen; nur für alle, die sie sehen oder verwalten dürfen.'),
            'mail' => I18n::t('E-Mails aus der App an Rollen, einzelne Mitglieder und beliebige Adressen schreiben, mit An, Cc und Bcc und Versandprotokoll; nur für alle, die sie versenden dürfen.'),
            'companies' => I18n::t('Liste eurer Kompanien mit Logo, Beschreibung und Links; Mitglieder lassen sich ihnen zuordnen. Für alle sichtbar, auch ohne Anmeldung.'),
            'sponsors' => I18n::t('Eure Sponsoren mit Logo und Link, in eurer Reihenfolge, für alle sichtbar, auch ohne Anmeldung.'),
            'pages' => I18n::t('Eigene Seiten mit formatiertem Text und Bildern, z. B. Geschichte, Festprogramm oder Anfahrt; je Seite für alle oder nur für Mitglieder.'),
            'website' => I18n::t('Eine Webseite für Besucher unter eurer Adresse, gebaut aus euren Seiten, die für alle sichtbar sind; ohne Anmeldung und ohne Cookies.'),
            'passive' => I18n::t('Interne Liste eurer passiven Mitglieder mit Adresse, Telefon, E-Mail und Geburtsdatum, je mit einem Mitglied als Betreuer; Passive bekommen keinen Zugang zur App.'),
            default => '',
        };
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
            if (!$this->available($key)) {
                continue;
            }
            $out[] = [
                'key' => $key,
                'label' => $this->app->config->sectionLabel($key, $def['label']),
                'description' => self::description($key),
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
            'news' => $this->app->newsService()->available(),
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
                throw ApiError::invalid(I18n::t('Unbekannter Bereich: {key}.', ['key' => $key]));
            }
            if (!is_bool($value)) {
                throw ApiError::invalid(I18n::t('Ungültiger Wert für {key} (an oder aus erwartet).', ['key' => $key]));
            }
            // Bereiche, die es hier nicht gibt (HOSTED), bleiben aus.
            $next[(string) $key] = $value && $this->available((string) $key);
        }
        foreach ($next as $key => $on) {
            foreach (self::DEFINITIONS[$key]['requires'] as $required) {
                if ($on && !$next[$required]) {
                    throw ApiError::invalid(I18n::t('„{feature}“ braucht „{required}“. Bitte beide einschalten.', [
                        'feature' => $this->app->config->sectionLabel($key, self::DEFINITIONS[$key]['label']),
                        'required' => $this->app->config->sectionLabel($required, self::DEFINITIONS[$required]['label']),
                    ]));
                }
            }
        }
        $before = $this->all();
        $this->save($next);
        // Standardrollen der Bereiche (Issue #28): beim Einschalten anlegen, beim Ausschalten entfernen,
        // wenn niemand sie hat.
        foreach ($next as $key => $on) {
            $caps = $this->roleOf($key);
            if ($caps !== null && $on !== ($before[$key] ?? false)) {
                $this->app->roles()->featureChanged($key, $on, $this->roleLabel($key), $caps);
            }
        }
        // Schwarzes Brett (Issue #5), Umfragen (Issue #4) und Geburtstage (Issue #1): Beim ersten Einschalten bekommen
        // bestehende Abos die neue Art dazu (einmalig je Art, siehe PushService::offerTypeToExisting).
        // Ebenso die Galerie mit Einreichungen (Issue #286).
        foreach ([['board', PushService::TYPE_BOARD], ['polls', PushService::TYPE_POLLS], ['birthdays', PushService::TYPE_BIRTHDAYS], ['gallery', PushService::TYPE_GALLERY_REVIEW], ['gallery', PushService::TYPE_GALLERY_SUBMISSIONS]] as [$feature, $type]) {
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
    /**
     * Rechte der Standardrolle eines Bereichs (DEFINITIONS role); bei den News nur mit einer Quelle, die man pflegt
     * (NewsService::managedType), beim Kalender nur mit der Quelle „In der App“.
     *
     * @return string[]|null
     */
    public function roleOf(string $feature): ?array
    {
        if ($feature === 'news') {
            $news = $this->app->newsService();
            return $news->managedType($news->sourceType()) ? NewsService::ROLE : null;
        }
        if ($feature === 'calendar') {
            return $this->app->calendar()->inApp() ? CalendarService::ROLE : null;
        }
        return self::DEFINITIONS[$feature]['role'];
    }

    public function roleLabel(string $feature): string
    {
        // Gespeichert als Name der Rolle: auf Deutsch, auch wenn die Anfrage eine andere Sprache hat
        return (string) I18n::in(I18n::SOURCE_LOCALE, fn (): string => $this->app->config->sectionLabel($feature, self::DEFINITIONS[$feature]['label']) . ' verwalten');
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
            // Mitgliederliste, Ämter, Listen, E-Mails und Sponsoren gab es in den alten Versionen nicht: nie aus kompanie.json einschalten.
            $state[$key] = $existingInstallation && !in_array($key, ['ranks', 'titles', 'attendance', 'documents', 'board', 'polls', 'birthdays', 'inventory', 'directory', 'offices', 'lists', 'mail', 'sponsors', 'companies', 'pages', 'website', 'passive'], true) && $this->app->config->legacyFeature($key);
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
