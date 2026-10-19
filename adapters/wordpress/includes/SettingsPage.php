<?php

declare(strict_types=1);

namespace KompanieApp\WordPress;

use KompanieApp\Core\App;
use KompanieApp\Core\Domain\Features;
use KompanieApp\Core\Http\ApiError;

/** Einstellungen → <Name der Kompanie>: Bereiche, Kalender und Galerie. */
final class SettingsPage
{
    public const PAGE = 'kompanie-app';

    public static function init(): void
    {
        add_action('admin_menu', static fn () => add_options_page(
            Plugin::app()->config->shortName() . ' – App',
            Plugin::app()->config->shortName() . ' – App',
            'manage_options',
            self::PAGE,
            static fn () => self::render(Plugin::app()),
        ));
        add_action('admin_post_kompanie_app_features', static fn () => self::saveFeatures(Plugin::app()));
        add_action('admin_post_kompanie_app_calendar', static fn () => self::saveCalendar(Plugin::app()));
        add_action('admin_notices', [self::class, 'permalinkNotice']);
        add_action('admin_post_kompanie_app_gallery', static fn () => self::saveGallery(Plugin::app()));
        add_action('admin_post_kompanie_app_gallery_sync', static fn () => self::syncGallery(Plugin::app()));
    }

    /** Ohne „schöne“ Permalinks läuft die App unter ?kompanie_app=…; /app/ ist einprägsamer. */
    public static function permalinkNotice(): void
    {
        if (WpEnvironment::prettyPermalinks() || !current_user_can('manage_options')) {
            return;
        }
        printf(
            '<div class="notice notice-info"><p>Die App ist erreichbar unter <a href="%1$s">%1$s</a>. Für die kürzere Adresse <code>/app/</code> unter <a href="%2$s">Einstellungen → Permalinks</a> z. B. „Beitragsname“ wählen.</p></div>',
            esc_url((new WpEnvironment())->appUrl()),
            esc_url(admin_url('options-permalink.php')),
        );
    }

    private static function url(array $args = []): string
    {
        return add_query_arg($args, admin_url('options-general.php?page=' . self::PAGE));
    }

    private static function back(string $message, bool $error, string $anchor, array $extra = []): never
    {
        wp_safe_redirect(self::url(['kompanie_msg' => rawurlencode($message), 'kompanie_error' => $error ? 1 : 0] + $extra) . '#' . $anchor);
        exit;
    }

    private static function guard(string $action): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Keine Berechtigung.');
        }
        check_admin_referer($action);
    }

    private static function render(App $app): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $s = $app->settings;
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- nur Anzeige von Rückmeldungen
        $msg = isset($_GET['kompanie_msg']) ? sanitize_text_field(wp_unslash($_GET['kompanie_msg'])) : '';
        $isError = !empty($_GET['kompanie_error']);
        $continue = !empty($_GET['kompanie_continue']);
        $test = isset($_GET['kompanie_test']);
        // phpcs:enable
        $status = $app->gallery()->status();
        $email = $app->drive()->clientEmail();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html($app->config->shortName()); ?> – App</h1>
            <?php if ($msg !== '') : ?>
                <div class="notice <?php echo $isError ? 'notice-error' : 'notice-success'; ?>"><p><?php echo esc_html($msg); ?></p></div>
            <?php endif; ?>
            <p>
                Die App ist erreichbar unter
                <a href="<?php echo esc_url($app->env->appUrl()); ?>" target="_blank"><?php echo esc_html($app->env->appUrl()); ?></a>.
                Rollen in der App legt ein Admin in der App unter Mitglieder fest oder im jeweiligen
                <a href="<?php echo esc_url(admin_url('users.php')); ?>">Benutzerprofil</a>.
                Öffentliche Termine zeigt auf der Webseite der Shortcode <code>[kompanie_termine]</code>, die Galerie <code>[kompanie_galerie]</code>.
            </p>

            <h2 id="bereiche">Bereiche</h2>
            <p>Welche Bereiche die App für alle hat (dasselbe wie in der App unter <em>Einstellungen → Bereiche</em>).
               Ausgeschaltete Bereiche verschwinden aus der App; ihre Daten bleiben erhalten.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="kompanie_app_features" />
                <?php wp_nonce_field('kompanie_app_features'); ?>
                <fieldset>
                    <?php foreach ($app->features()->describe() as $f) : ?>
                        <p>
                            <label>
                                <input type="checkbox" name="features[]" value="<?php echo esc_attr($f['key']); ?>" <?php checked($f['enabled']); ?> />
                                <strong><?php echo esc_html($f['label']); ?></strong> – <?php echo esc_html($f['description']); ?>
                            </label>
                            <?php if ($f['enabled'] && $f['configured'] === false) : ?>
                                <br /><span class="description">Noch nicht eingerichtet (siehe unten).</span>
                            <?php endif; ?>
                        </p>
                    <?php endforeach; ?>
                </fieldset>
                <?php submit_button('Bereiche speichern', 'secondary'); ?>
            </form>

            <hr />
            <h2 id="kalender">Kalender</h2>
            <p>Am einfachsten: In Google Kalender unter <em>Einstellungen → Kalender → Kalender integrieren</em> die
               <strong>„Privatadresse im iCal-Format“</strong> kopieren und hier einfügen.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="kompanie_app_calendar" />
                <?php wp_nonce_field('kompanie_app_calendar'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="calendar_ics_url">Kalender-Link (iCal)</label></th>
                        <td><input type="url" class="large-text" id="calendar_ics_url" name="calendar_ics_url" value="<?php echo esc_attr((string) $s->get('calendar_ics_url', '')); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="calendar_ids">Oder: Google-Kalender-IDs</label></th>
                        <td><input type="text" class="regular-text" id="calendar_ids" name="calendar_ids" value="<?php echo esc_attr((string) $s->get('calendar_ids', '')); ?>" />
                        <p class="description">Nur für öffentliche Kalender, mit API-Schlüssel. Mehrere durch Komma trennen.</p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="google_api_key">Google-API-Schlüssel</label></th>
                        <td><input type="password" autocomplete="off" class="regular-text" id="google_api_key" name="google_api_key" value="<?php echo esc_attr((string) $s->get('google_api_key', '')); ?>" /></td>
                    </tr>
                </table>
                <?php submit_button('Speichern und testen', 'primary', 'submit', false); ?>
            </form>
            <?php if ($test) : ?>
                <?php self::renderCalendarTest($app); ?>
            <?php endif; ?>

            <hr />
            <h2 id="galerie">Galerie (Google Drive)</h2>
            <p>Aufbau in Drive: <strong>Galerie-Ordner → Jahr → Album → Bilder</strong>. Bilder im Unterordner
               <strong>„intern“</strong> eines Albums sehen nur angemeldete Mitglieder.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="kompanie_app_gallery" />
                <?php wp_nonce_field('kompanie_app_gallery'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="drive_sa">Google-Zugang (JSON-Schlüssel)</label></th>
                        <td>
                            <?php if ($email !== '') : ?>
                                <p>Hinterlegt. Gib den Galerie-Ordner in Google Drive für <code><?php echo esc_html($email); ?></code> frei (Rolle „Betrachter“).</p>
                            <?php endif; ?>
                            <textarea id="drive_sa" name="drive_sa" rows="4" class="large-text code" placeholder="<?php echo esc_attr($email !== '' ? 'Nur ausfüllen, um den Schlüssel zu ersetzen' : 'Inhalt der JSON-Datei hier einfügen'); ?>"></textarea>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="gallery_root">Galerie-Ordner</label></th>
                        <td><input type="text" class="large-text" id="gallery_root" name="gallery_root" value="<?php echo esc_attr($app->gallery()->root()); ?>" placeholder="Link zum Ordner in Google Drive" /></td>
                    </tr>
                </table>
                <?php submit_button('Galerie speichern', 'secondary'); ?>
            </form>
            <h3>Stand</h3>
            <ul>
                <li>Letzter Abgleich: <?php echo !empty($status['last_sync']) ? esc_html(wp_date('d.m.Y H:i', (int) $status['last_sync'])) : '–'; ?></li>
                <li>Alben: <?php echo esc_html((string) ($status['albums'] ?? '–')); ?>, Bilder: <?php echo esc_html((string) ($status['photos'] ?? '–')); ?>,
                    noch aufzubereiten: <?php echo esc_html((string) ($status['pending'] ?? '–')); ?></li>
                <?php if (!empty($status['error'])) : ?>
                    <li><strong style="color:#b32d2e">Fehler: <?php echo esc_html((string) $status['error']); ?></strong></li>
                <?php endif; ?>
            </ul>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="kompanie-gallery-sync">
                <input type="hidden" name="action" value="kompanie_app_gallery_sync" />
                <?php if ($continue) : ?><input type="hidden" name="only_process" value="1" /><?php endif; ?>
                <?php wp_nonce_field('kompanie_app_gallery_sync'); ?>
                <?php submit_button('Jetzt abgleichen', 'primary', 'submit', false, $app->gallery()->isConfigured() ? [] : ['disabled' => 'disabled']); ?>
                <p class="description">Neue Bilder kommen danach stündlich automatisch dazu. Beim ersten Mal lädt die Seite selbstständig weiter, bis alle Bilder aufbereitet sind.</p>
            </form>
            <?php if ($continue) : ?>
                <p><em>Bereite weitere Bilder auf …</em></p>
                <script>setTimeout(function(){document.getElementById('kompanie-gallery-sync').submit();},800);</script>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function renderCalendarTest(App $app): void
    {
        $app->calendar()->clearCache();
        try {
            $events = $app->calendar()->events();
            echo '<div class="notice notice-success inline"><p>' . esc_html(sprintf('%d Termine gefunden.', count($events))) . '</p><ul>';
            foreach (array_slice($events, 0, 5) as $ev) {
                echo '<li>' . esc_html($ev['start'] . ' – ' . $ev['title']) . '</li>';
            }
            echo '</ul></div>';
        } catch (\Throwable $e) {
            echo '<div class="notice notice-error inline"><p>' . esc_html($e->getMessage()) . '</p></div>';
        }
    }

    private static function saveFeatures(App $app): never
    {
        self::guard('kompanie_app_features');
        $on = isset($_POST['features']) && is_array($_POST['features']) ? array_map('sanitize_key', wp_unslash($_POST['features'])) : [];
        $state = [];
        foreach (Features::keys() as $key) {
            $state[$key] = in_array($key, $on, true);
        }
        try {
            $app->features()->update($state);
        } catch (ApiError $e) {
            self::back($e->getMessage(), true, 'bereiche');
        }
        self::back('Bereiche gespeichert.', false, 'bereiche');
    }

    private static function saveCalendar(App $app): never
    {
        self::guard('kompanie_app_calendar');
        $url = isset($_POST['calendar_ics_url']) ? esc_url_raw(trim(wp_unslash($_POST['calendar_ics_url'])), ['http', 'https', 'webcal']) : '';
        $app->settings->set('calendar_ics_url', $url);
        $app->settings->set('calendar_ids', isset($_POST['calendar_ids']) ? sanitize_text_field(wp_unslash($_POST['calendar_ids'])) : '');
        $app->settings->set('google_api_key', isset($_POST['google_api_key']) ? sanitize_text_field(wp_unslash($_POST['google_api_key'])) : '');
        $app->calendar()->clearCache();
        $app->scheduleTasks();
        self::back('Gespeichert.', false, 'kalender', ['kompanie_test' => 1]);
    }

    private static function saveGallery(App $app): never
    {
        self::guard('kompanie_app_gallery');
        $messages = [];
        $error = false;
        $json = isset($_POST['drive_sa']) ? trim((string) wp_unslash($_POST['drive_sa'])) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- wird geprüft, nicht ausgegeben
        try {
            if ($json !== '') {
                $app->drive()->saveServiceAccount($json);
                $messages[] = 'Google-Zugang gespeichert.';
            }
            $root = isset($_POST['gallery_root']) ? sanitize_text_field(wp_unslash($_POST['gallery_root'])) : '';
            $before = $app->gallery()->root();
            $app->gallery()->setRoot($root);
            if ($before !== '' && $before !== $app->gallery()->root()) {
                $app->gallery()->reset();
            }
        } catch (\RuntimeException $e) {
            $messages[] = $e->getMessage();
            $error = true;
        }
        $app->scheduleTasks();
        self::back($messages ? implode(' ', $messages) : 'Gespeichert.', $error, 'galerie');
    }

    private static function syncGallery(App $app): never
    {
        self::guard('kompanie_app_gallery_sync');
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }
        $gallery = $app->gallery();
        try {
            // Beim automatischen Weiterladen nur aufbereiten, nicht erneut abgleichen.
            $stats = empty($_POST['only_process']) ? $gallery->sync() : $gallery->status();
            $done = $gallery->processPending(20);
            $left = $gallery->countPending();
            $msg = sprintf('%d Alben, %d Bilder gefunden. %d Bilder aufbereitet, %d ausstehend.', $stats['albums'] ?? 0, $stats['photos'] ?? 0, $done, $left);
            self::back($msg, false, 'galerie', ['kompanie_continue' => $left > 0 ? 1 : 0]);
        } catch (\RuntimeException $e) {
            self::back($e->getMessage(), true, 'galerie');
        }
    }
}
