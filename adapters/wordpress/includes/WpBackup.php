<?php

declare(strict_types=1);

namespace KompanieApp\WordPress;

use KompanieApp\Core\App;
use KompanieApp\Core\Port\BackupProvider;
use KompanieApp\Core\Port\FileStorage;
use KompanieApp\Core\Storage\Backup;
use KompanieApp\Core\Storage\GalleryRepository;
use KompanieApp\Core\Storage\Sql\Connection;
use KompanieApp\Core\Storage\Sql\Migrator;

/**
 * Datensicherung im WordPress-Plugin: Daten des Kerns mit den Dateien der Dokumente und Abzeichen (Backup::dumpFiles),
 * Einstellungen (Options mit Präfix) und die Konten der Website
 * (accounts: WordPress-Benutzer mit Passwort-Hash, Namen, WP-Rollen und Rolle in der App). Die Daten verweisen auf die
 * IDs der Benutzer. Deshalb lässt sich eine Sicherung nur auf derselben Website (Adresse) wiederherstellen: nach einem
 * missglückten Update oder nach dem Verlust der Website auf einer neu installierten WordPress-Seite.
 *
 * Beim Wiederherstellen werden fehlende Konten mit ihrer bisherigen ID angelegt (dafür braucht es das WP-Recht, Benutzer
 * anzulegen). Vorhandene Konten behalten Benutzernamen, Passwort und E-Mail; aus der Sicherung kommen nur die Rolle in der
 * App, die Konto-Markierung und die früheren WP-Rollen der App. Konten, die nicht in der Sicherung stehen, bleiben.
 * Gehört eine ID hier einem anderen Benutzernamen (oder Benutzername bzw. E-Mail einer anderen ID), bricht das
 * Wiederherstellen ab, bevor sich etwas ändert: Die Daten gehörten sonst anderen Personen.
 */
final class WpBackup implements BackupProvider
{
    /** Gehören zur Installation, nicht zu den Daten. */
    private const SKIP_SETTINGS = [...Backup::LOCAL_SETTINGS, 'roles_version'];
    /** Frühere WP-Rollen der App: Beim Wiederherstellen vorhandener Konten ändern sich nur diese WP-Rollen. */
    private const APP_WP_ROLES = [Roles::ROLE_MEMBER, Roles::ROLE_ORGANIZER, Roles::ROLE_MANAGER];

    /** @param FileStorage|null $files geschützte Dateiablage (Dokumente, Abzeichen); ohne sie bleiben die Dateien außen vor */
    public function __construct(
        private readonly Connection $db,
        private readonly string $version,
        private readonly string $kompanie,
        private readonly ?FileStorage $files = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function export(): array
    {
        $tables = (new Backup($this->db))->dump(Backup::CORE_TABLES);
        return [...$this->head(), 'tables' => $tables, 'files' => $this->files !== null ? Backup::dumpFiles($this->files, $tables) : [], ...$this->tail()];
    }

    public function exportTo($out): void
    {
        (new Backup($this->db))->write($out, Backup::CORE_TABLES, $this->files, $this->head(), $this->tail());
    }

    /** @return array<string, mixed> Felder vor tables */
    private function head(): array
    {
        return [
            'format' => Backup::FORMAT,
            'formatVersion' => Backup::FORMAT_VERSION,
            'created' => gmdate('c'),
            'variant' => 'wordpress',
            'version' => $this->version,
            'kompanie' => $this->kompanie,
            'site' => self::site(),
            'schema' => ['core' => (int) get_option(WpSettings::PREFIX . Migrator::SETTING, 0)],
        ];
    }

    /** @return array<string, mixed> Felder nach files */
    private function tail(): array
    {
        $settings = [];
        foreach (self::settingNames() as $key) {
            $settings[$key] = get_option(WpSettings::PREFIX . $key);
        }
        return ['accounts' => self::accounts(), 'settings' => $settings];
    }

    public function canImport(): bool
    {
        return true;
    }

    public function import(string $json): void
    {
        $data = Backup::parse($json);
        if (($data['variant'] ?? '') !== 'wordpress') {
            throw new \RuntimeException('Sicherungen der eigenständigen Version lassen sich (noch) nicht ins WordPress-Plugin übernehmen: Die Benutzer sind hier WordPress-Konten.');
        }
        if (($data['site'] ?? '') !== self::site()) {
            throw new \RuntimeException('Die Sicherung stammt von einer anderen Website. Die Daten verweisen auf deren WordPress-Benutzer und lassen sich hier nicht wiederherstellen.');
        }
        $settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
        // Ältere Sicherungen ohne Konten: wie bisher nur die Daten (die Konten müssen dann schon da sein).
        $accounts = array_key_exists('accounts', $data) ? self::parseAccounts($data['accounts']) : [];
        $plan = self::planAccounts($accounts);
        $files = Backup::parseFiles($data['files'] ?? null);
        try {
            $this->db->transaction(function () use ($data, $plan): void {
                self::restoreAccounts($plan);
                (new Backup($this->db))->restore($data['tables'], Backup::CORE_TABLES);
                // Bilddateien sind nicht in der Sicherung: alle neu aufbereiten.
                $this->db->execute('UPDATE {photos} SET status = ?', [GalleryRepository::PENDING]);
            });
        } finally {
            // Konten sind direkt in der Datenbank geschrieben (Teil der Transaktion); den Objekt-Cache danach leeren.
            foreach ($accounts as $a) {
                clean_user_cache($a['id']);
            }
        }
        if ($this->files !== null) {
            Backup::restoreFiles($this->files, $files);
        }
        // Einstellungen erst nach der Transaktion: update_option schreibt auch in den Objekt-Cache,
        // der ein Zurückrollen der Transaktion nicht mitbekäme.
        $wp = new WpSettings();
        foreach ($settings as $key => $value) {
            if (is_string($key) && preg_match('/^[a-z0-9_]{1,100}$/', $key) && self::isData($key)) {
                $wp->set($key, $value);
            }
        }
        // Andere Bereiche oder eine andere Galerie: beim nächsten Aufruf neu einplanen (App::upgrade).
        $wp->delete(App::PLAN_SETTING);
    }

    /**
     * Benutzer dieser Website (bei Multisite nur die Mitglieder der Seite).
     *
     * @return array<int, array<string, mixed>>
     */
    private static function accounts(): array
    {
        global $wpdb;
        $prefix = $wpdb->get_blog_prefix();
        $out = [];
        foreach (get_users(['fields' => 'all_with_meta', 'orderby' => 'ID', 'order' => 'ASC']) as $u) {
            $id = (int) $u->ID;
            $role = get_user_meta($id, $prefix . WpUsers::ROLE_META, true);
            $out[] = [
                'id' => $id,
                'login' => (string) $u->user_login,
                'pass' => (string) $u->user_pass,
                'email' => (string) $u->user_email,
                'nicename' => (string) $u->user_nicename,
                'url' => (string) $u->user_url,
                'registered' => (string) $u->user_registered,
                'display_name' => (string) $u->display_name,
                'first_name' => (string) get_user_meta($id, 'first_name', true),
                'last_name' => (string) get_user_meta($id, 'last_name', true),
                'nickname' => (string) get_user_meta($id, 'nickname', true),
                'roles' => array_values(array_map('strval', (array) $u->roles)),
                'app_role' => is_string($role) && $role !== '' ? $role : null,
                'app_account' => get_user_meta($id, $prefix . WpUsers::ACCOUNT_META, true) === '1',
            ];
        }
        return $out;
    }

    /**
     * Konten aus der Sicherung prüfen und auf die erwarteten Typen bringen.
     *
     * @return array<int, array{id: int, login: string, pass: string, email: string, nicename: string, url: string, registered: string,
     *   display_name: string, first_name: string, last_name: string, nickname: string, roles: string[], app_role: string|null, app_account: bool}>
     */
    private static function parseAccounts(mixed $accounts): array
    {
        $broken = new \RuntimeException('Die Sicherung ist beschädigt (Konten).');
        if (!is_array($accounts)) {
            throw $broken;
        }
        $out = [];
        $text = ['login', 'pass', 'email', 'nicename', 'url', 'registered', 'display_name', 'first_name', 'last_name', 'nickname'];
        foreach ($accounts as $a) {
            if (!is_array($a) || !is_int($a['id'] ?? null) || $a['id'] <= 0 || !is_string($a['login'] ?? null) || trim($a['login']) === '' || isset($out[$a['id']])) {
                throw $broken;
            }
            $row = ['id' => $a['id']];
            foreach ($text as $key) {
                $v = $a[$key] ?? '';
                if (!is_string($v)) {
                    throw $broken;
                }
                $row[$key] = $v;
            }
            $roles = $a['roles'] ?? [];
            $appRole = $a['app_role'] ?? null;
            if (!is_array($roles) || array_filter($roles, static fn ($r): bool => !is_string($r)) !== [] || ($appRole !== null && !is_string($appRole))) {
                throw $broken;
            }
            $row['roles'] = array_values($roles);
            $row['app_role'] = $appRole;
            $row['app_account'] = ($a['app_account'] ?? false) === true;
            $out[$a['id']] = $row;
        }
        return array_values($out);
    }

    /**
     * Was mit jedem Konto der Sicherung geschieht: anlegen (fehlt hier) oder übernehmen (gleiche ID, gleicher
     * Benutzername). Ändert nichts; bricht bei Widersprüchen und fehlendem WP-Recht ab.
     *
     * @param array<int, array<string, mixed>> $accounts aus parseAccounts()
     * @return array{create: array<int, array<string, mixed>>, update: array<int, array<string, mixed>>}
     */
    private static function planAccounts(array $accounts): array
    {
        global $wpdb;
        $plan = ['create' => [], 'update' => []];
        $conflicts = [];
        foreach ($accounts as $a) {
            $existing = $wpdb->get_row($wpdb->prepare("SELECT ID, user_login FROM $wpdb->users WHERE ID = %d", $a['id']));
            if ($existing !== null) {
                if (strtolower((string) $existing->user_login) !== strtolower($a['login'])) {
                    $conflicts[] = sprintf('Konto %d heißt hier „%s“, in der Sicherung „%s“', $a['id'], $existing->user_login, $a['login']);
                } else {
                    $plan['update'][] = $a;
                }
                continue;
            }
            $byLogin = (int) $wpdb->get_var($wpdb->prepare("SELECT ID FROM $wpdb->users WHERE user_login = %s", $a['login']));
            $byEmail = $a['email'] !== '' ? (int) $wpdb->get_var($wpdb->prepare("SELECT ID FROM $wpdb->users WHERE user_email = %s", $a['email'])) : 0;
            if ($byLogin > 0) {
                $conflicts[] = sprintf('den Benutzernamen „%s“ hat hier Konto %d, in der Sicherung Konto %d', $a['login'], $byLogin, $a['id']);
            } elseif ($byEmail > 0) {
                $conflicts[] = sprintf('die E-Mail von „%s“ hat hier schon Konto %d', $a['login'], $byEmail);
            } else {
                $plan['create'][] = $a;
            }
        }
        if ($conflicts !== []) {
            $more = count($conflicts) > 5 ? sprintf(' (und %d weitere)', count($conflicts) - 5) : '';
            throw new \RuntimeException('Die Konten der Sicherung passen nicht zu den Benutzern dieser Website: ' . implode('; ', array_slice($conflicts, 0, 5)) . $more
                . '. Nichts wurde geändert. Auf einer neu installierten Website den WordPress-Administrator mit dem Benutzernamen und der E-Mail'
                . ' anlegen, die das Konto mit seiner ID in der Sicherung hat, oder mit einem Benutzernamen und einer E-Mail, die in der Sicherung nicht vorkommen.');
        }
        if ($plan['create'] !== [] && get_current_user_id() !== 0 && !(current_user_can('create_users') && current_user_can('promote_users'))) {
            throw new \RuntimeException(sprintf('Die Sicherung enthält %d Konten, die es hier nicht gibt. Anlegen darf sie nur, wer in WordPress Benutzer anlegen darf (Administrator).', count($plan['create'])));
        }
        return $plan;
    }

    /**
     * Konten anlegen bzw. übernehmen, direkt in der Datenbank (innerhalb der Transaktion des Wiederherstellens; WordPress-
     * Funktionen wie wp_insert_user() vergeben eine neue ID und schreiben in den Objekt-Cache).
     *
     * @param array{create: array<int, array<string, mixed>>, update: array<int, array<string, mixed>>} $plan
     */
    private static function restoreAccounts(array $plan): void
    {
        global $wpdb;
        $prefix = $wpdb->get_blog_prefix();
        $fail = static fn (): \RuntimeException => new \RuntimeException('Die Konten ließen sich nicht wiederherstellen: ' . $wpdb->last_error);
        foreach ($plan['create'] as $a) {
            $ok = $wpdb->insert($wpdb->users, [
                'ID' => $a['id'],
                'user_login' => $a['login'],
                'user_pass' => $a['pass'],
                'user_nicename' => self::uniqueNicename($a['nicename'] !== '' ? $a['nicename'] : sanitize_title(mb_substr($a['login'], 0, 50))),
                'user_email' => $a['email'],
                'user_url' => $a['url'],
                'user_registered' => preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $a['registered']) === 1 ? $a['registered'] : gmdate('Y-m-d H:i:s'),
                'user_activation_key' => '',
                'user_status' => 0,
                'display_name' => $a['display_name'] !== '' ? $a['display_name'] : $a['login'],
            ]);
            if ($ok === false) {
                throw $fail();
            }
            $roles = array_values(array_filter($a['roles'], static fn (string $r): bool => wp_roles()->is_role($r)));
            self::setMeta($a['id'], 'first_name', $a['first_name']);
            self::setMeta($a['id'], 'last_name', $a['last_name']);
            self::setMeta($a['id'], 'nickname', $a['nickname'] !== '' ? $a['nickname'] : $a['login']);
            self::setMeta($a['id'], $prefix . 'capabilities', array_fill_keys($roles !== [] ? $roles : ['subscriber'], true));
            self::setMeta($a['id'], $prefix . 'user_level', self::level($roles));
            self::setAppMeta($a, $prefix);
        }
        foreach ($plan['update'] as $a) {
            $caps = get_user_meta($a['id'], $prefix . 'capabilities', true);
            $caps = is_array($caps) ? array_diff_key($caps, array_flip(self::APP_WP_ROLES)) : [];
            foreach (array_intersect($a['roles'], self::APP_WP_ROLES) as $r) {
                $caps[$r] = true;
            }
            self::setMeta($a['id'], $prefix . 'capabilities', $caps !== [] ? $caps : ['subscriber' => true]);
            self::setAppMeta($a, $prefix);
        }
    }

    /** @param array<string, mixed> $a */
    private static function setAppMeta(array $a, string $prefix): void
    {
        self::setMeta($a['id'], $prefix . WpUsers::ROLE_META, $a['app_role']);
        self::setMeta($a['id'], $prefix . WpUsers::ACCOUNT_META, $a['app_account'] ? '1' : null);
    }

    /** Benutzer-Option ersetzen (null: löschen), ohne Objekt-Cache. */
    private static function setMeta(int $id, string $key, mixed $value): void
    {
        global $wpdb;
        if ($wpdb->delete($wpdb->usermeta, ['user_id' => $id, 'meta_key' => $key]) === false
            || ($value !== null && $wpdb->insert($wpdb->usermeta, ['user_id' => $id, 'meta_key' => $key, 'meta_value' => maybe_serialize($value)]) === false)) {
            throw new \RuntimeException('Die Konten ließen sich nicht wiederherstellen: ' . $wpdb->last_error);
        }
    }

    /**
     * Benutzerstufe wie WP_User::update_user_level_from_caps(): höchstes level_N der Rollen.
     *
     * @param string[] $roles
     */
    private static function level(array $roles): int
    {
        $level = 0;
        foreach ($roles as $r) {
            foreach (array_keys((array) wp_roles()->get_role($r)?->capabilities) as $cap) {
                if (preg_match('/^level_(\d+)$/', (string) $cap, $m) === 1) {
                    $level = max($level, (int) $m[1]);
                }
            }
        }
        return $level;
    }

    /** Name in Adressen (/author/…) wie bei wp_insert_user() eindeutig machen: bei Bedarf mit -2, -3 … */
    private static function uniqueNicename(string $base): string
    {
        global $wpdb;
        $nicename = $base;
        for ($suffix = 2; $wpdb->get_var($wpdb->prepare("SELECT ID FROM $wpdb->users WHERE user_nicename = %s LIMIT 1", $nicename)) !== null; $suffix++) {
            $nicename = mb_substr($base, 0, 49 - strlen((string) $suffix)) . "-$suffix";
        }
        return $nicename;
    }

    /** @return string[] Namen der Einstellungen ohne Präfix */
    private static function settingNames(): array
    {
        global $wpdb;
        $names = (array) $wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name",
            $wpdb->esc_like(WpSettings::PREFIX) . '%',
        ));
        $keys = array_map(static fn (string $n): string => substr($n, strlen(WpSettings::PREFIX)), $names);
        return array_values(array_filter($keys, static fn (string $k): bool => self::isData($k)));
    }

    /** Einstellung, die zu den Daten gehört (nicht zur Installation, kein Zähler von WpCache). */
    private static function isData(string $key): bool
    {
        return !in_array($key, self::SKIP_SETTINGS, true) && !str_starts_with($key, WpCache::COUNTER);
    }

    /** Website ohne Schema, damit ein Wechsel auf https die Sicherung nicht unbrauchbar macht. */
    private static function site(): string
    {
        return rtrim((string) preg_replace('#^https?://#i', '', home_url('/')), '/');
    }
}
