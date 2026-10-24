<?php

declare(strict_types=1);

namespace KompanieApp\WordPress;

use KompanieApp\Core\App;
use KompanieApp\Core\Domain\Features;
use KompanieApp\Core\Domain\GoogleConnect;
use KompanieApp\Core\Domain\MaintenanceService;
use KompanieApp\Core\Http\ApiError;

/** Einstellungen → <Name der Kompanie>: Wartung, Bereiche, Google-Zugang, Kalender, Galerie und Platzhalterbild der Webseite. */
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
        add_action('admin_post_kompanie_app_maintenance', static fn () => self::saveMaintenance(Plugin::app()));
        add_action('admin_post_kompanie_app_calendar', static fn () => self::saveCalendar(Plugin::app()));
        add_action('admin_notices', [self::class, 'permalinkNotice']);
        add_action('admin_post_kompanie_app_gallery', static fn () => self::saveGallery(Plugin::app()));
        add_action('admin_post_kompanie_app_gallery_sync', static fn () => self::syncGallery(Plugin::app()));
        add_action('admin_post_kompanie_app_members_placeholder', static fn () => self::saveMembersPlaceholder(Plugin::app()));
        // Auswahl des Platzhalterbilds aus der Mediathek.
        add_action('admin_enqueue_scripts', static function (string $hook): void {
            if ($hook === 'settings_page_' . self::PAGE) {
                wp_enqueue_media();
            }
        });
        add_action('admin_post_kompanie_app_google_connect', static fn () => self::googleConnect(Plugin::app()));
        add_action('admin_post_kompanie_app_google_disconnect', static fn () => self::googleDisconnect(Plugin::app()));
        add_action('admin_post_kompanie_app_service_account', static fn () => self::saveServiceAccount(Plugin::app()));
        // Rückkehr von Google: admin-post.php ohne „action“ (siehe WpEnvironment::googleRedirectUri). Auch ohne
        // Anmeldung, damit eine verständliche Meldung kommt statt einer leeren Seite.
        add_action('admin_post', static fn () => self::googleCallback(Plugin::app()));
        add_action('admin_post_nopriv', static fn () => self::googleCallback(Plugin::app()));
        // Beim Öffnen der Einstellungsseite die Planung prüfen (falls WP-Cron-Einträge verloren gingen).
        add_action('load-settings_page_' . self::PAGE, static fn () => Plugin::app()->scheduleTasks());
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
                Öffentliche Termine zeigt auf der Webseite der Shortcode <code>[kompanie_termine]</code>, die Galerie <code>[kompanie_galerie]</code>,
                die Ämter <code>[kompanie_aemter]</code> (nur eine Gruppe: <code>[kompanie_aemter gruppe="…"]</code>) und die Mitgliederliste <code>[kompanie_mitglieder]</code>.
            </p>

            <?php $maintenance = $app->maintenance()->state(); ?>
            <h2 id="wartung">Wartungsmodus</h2>
            <?php if ($maintenance['on']) : ?>
                <div class="notice notice-error inline"><p><strong>Wartungsmodus aktiv – nur Admins können die App nutzen.</strong>
                    Seit <?php echo esc_html(wp_date('d.m.Y H:i', $maintenance['since'])); ?>.</p></div>
            <?php endif; ?>
            <p>Solange der Wartungsmodus an ist, sehen alle außer Admins in der App nur eine Wartungsseite (z. B. während einer
               Datenübernahme). Die übrige Webseite und ihre Shortcodes sind nicht betroffen.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="kompanie_app_maintenance" />
                <?php wp_nonce_field('kompanie_app_maintenance'); ?>
                <p><label for="maintenance_reason">Begründung (optional, erscheint auf der Wartungsseite)</label><br />
                   <textarea id="maintenance_reason" name="reason" rows="3" class="large-text" maxlength="<?php echo (int) MaintenanceService::REASON_MAX; ?>"><?php echo esc_textarea($maintenance['reason']); ?></textarea></p>
                <?php if ($maintenance['on']) : ?>
                    <?php submit_button('Begründung speichern', 'secondary', 'maintenance_keep', false); ?>
                    <?php submit_button('Wartungsmodus beenden', 'primary', 'maintenance_off', false); ?>
                <?php else : ?>
                    <?php submit_button('Wartungsmodus einschalten', 'secondary', 'maintenance_on', false); ?>
                <?php endif; ?>
            </form>

            <hr />
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
            <?php self::renderGoogle($app); ?>

            <hr />
            <h2 id="kalender">Kalender</h2>
            <p>Am einfachsten: In Google Kalender unter <em>Einstellungen → Kalender → Kalender integrieren</em> die
               <strong>„Privatadresse im iCal-Format“</strong> kopieren und hier einfügen.
               Ein privater Kalender geht auch über seine Kalender-ID und den <a href="#google">Google-Zugang</a>
               (Verbindung mit Erlaubnis für den Kalender oder Dienstkonto, für das der Kalender freigegeben ist).
               Reihenfolge: API-Schlüssel, dann Google-Zugang, dann iCal-Link.</p>
            <?php
            $calendarSource = [
                'google' => 'Google-API mit eigenem API-Schlüssel',
                'google_connection' => 'Kalender-IDs über den Google-Zugang',
                'ics' => 'iCal-Link',
            ][$app->calendar()->source()] ?? '';
            ?>
            <p>Genutzt wird: <strong><?php echo esc_html($calendarSource !== '' ? $calendarSource : 'noch keine Quelle'); ?></strong></p>
            <?php if ($app->calendar()->connectionNeedsCalendarAccess()) : ?>
                <div class="notice notice-info inline"><p>Die Verbindung mit Google darf den Kalender nicht lesen (beim Verbinden nicht erlaubt).
                    Für die Kalender-IDs unter <a href="#google">Google-Zugang</a> „Verbindung trennen“ und neu verbinden, dabei auch das Häkchen für den Kalender setzen.</p></div>
            <?php endif; ?>
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
                        <p class="description">Mit API-Schlüssel (nur öffentliche Kalender) oder über den <a href="#google">Google-Zugang</a> (auch private). Mehrere durch Komma trennen.</p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="google_api_key">Google-API-Schlüssel</label></th>
                        <td><input type="password" autocomplete="off" class="regular-text" id="google_api_key" name="google_api_key" value="<?php echo esc_attr((string) $s->get('google_api_key', '')); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="calendar_cache_minutes">Aktualisieren alle … Minuten</label></th>
                        <td><input type="number" class="small-text" min="1" max="1440" step="1" required id="calendar_cache_minutes" name="calendar_cache_minutes" value="<?php echo esc_attr((string) max(1, (int) $s->get('calendar_cache_minutes', 15))); ?>" />
                        <p class="description">So lange zeigt die App die zuletzt geladenen Termine, bevor sie bei Google neu nachfragt (1 bis 1440 Minuten).
                            Kürzere Zeiten zeigen Änderungen schneller, bedeuten aber mehr Abrufe bei Google, und das Öffnen der Termine dauert dann öfter länger.
                            Zusätzlich erneuert die App den Kalender stündlich im Hintergrund; Speichern und „Kalender testen“ laden sofort neu.</p></td>
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
               <strong>„intern“</strong> eines Albums sehen nur angemeldete Mitglieder.
               Gelesen wird über den <a href="#google">Google-Zugang</a>:
               <?php if ($app->drive()->oauth() !== null) : ?>
                   die Verbindung mit Google<?php echo $app->drive()->oauthEmail() !== '' ? ' (' . esc_html($app->drive()->oauthEmail()) . ')' : ''; ?>.
               <?php elseif ($email !== '') : ?>
                   das Dienstkonto <code><?php echo esc_html($email); ?></code>; gib den Galerie-Ordner für diese Adresse frei (Rolle „Betrachter“).
               <?php else : ?>
                   <strong>noch nicht eingerichtet</strong>, bitte zuerst oben mit Google verbinden.
               <?php endif; ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="kompanie_app_gallery" />
                <?php wp_nonce_field('kompanie_app_gallery'); ?>
                <table class="form-table" role="presentation">
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

            <hr />
            <?php self::renderMembersPlaceholder($app); ?>
        </div>
        <?php
    }

    /** Platzhalterbild für [kompanie_mitglieder]: aus der Mediathek wählen (wird gleich gespeichert) oder entfernen. */
    private static function renderMembersPlaceholder(App $app): void
    {
        $id = (int) $app->settings->get(Shortcodes::MEMBERS_PLACEHOLDER, 0);
        $preview = $id > 0 ? wp_get_attachment_image_url($id, 'medium') : false;
        ?>
        <h2 id="webseite">Webseite: Mitglieder ohne Foto</h2>
        <p>Die Mitgliederliste auf der Webseite (<code>[kompanie_mitglieder]</code>) zeigt bei Personen ohne Foto dieses Bild
           statt der Anfangsbuchstaben, z. B. eine Silhouette oder das Logo. Am besten im Hochformat 2 : 3; andere Formate
           werden mittig zugeschnitten. Ohne Platzhalterbild stehen dort die Anfangsbuchstaben. Die App selbst zeigt weiter die Anfangsbuchstaben.</p>
        <?php if ($id > 0 && $preview === false) : ?>
            <div class="notice notice-warning inline"><p>Das gewählte Bild ist nicht mehr in der Mediathek. Bis du ein neues wählst, stehen dort die Anfangsbuchstaben.</p></div>
        <?php endif; ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="kompanie-members-placeholder">
            <input type="hidden" name="action" value="kompanie_app_members_placeholder" />
            <?php wp_nonce_field('kompanie_app_members_placeholder'); ?>
            <input type="hidden" name="placeholder_id" id="kompanie-placeholder-id" value="<?php echo esc_attr($id > 0 ? (string) $id : ''); ?>" />
            <?php if ($preview !== false) : ?>
                <p><img src="<?php echo esc_url($preview); ?>" alt="Aktuelles Platzhalterbild" style="width:120px;aspect-ratio:2/3;object-fit:cover;display:block" /></p>
            <?php endif; ?>
            <p>
                <button type="button" class="button" id="kompanie-placeholder-choose"><?php echo $preview !== false ? 'Anderes Bild wählen' : 'Bild aus der Mediathek wählen'; ?></button>
                <?php if ($id > 0) : ?>
                    <?php submit_button('Platzhalterbild entfernen', 'delete', 'remove', false); ?>
                <?php endif; ?>
            </p>
        </form>
        <script>
        (function(){var b=document.getElementById('kompanie-placeholder-choose');if(!b)return;
        b.addEventListener('click',function(){if(!window.wp||!wp.media)return;
        var f=wp.media({title:'Platzhalterbild für Mitglieder ohne Foto',library:{type:'image'},multiple:false,button:{text:'Übernehmen'}});
        f.on('select',function(){var a=f.state().get('selection').first();if(!a)return;
        document.getElementById('kompanie-placeholder-id').value=a.id;document.getElementById('kompanie-members-placeholder').submit();});
        f.open();});})();
        </script>
        <?php
    }

    private static function saveMembersPlaceholder(App $app): never
    {
        self::guard('kompanie_app_members_placeholder');
        [$message, $error] = self::applyMembersPlaceholder($app, wp_unslash($_POST));
        self::back($message, $error, 'webseite');
    }

    /**
     * Platzhalterbild aus dem Formular speichern bzw. entfernen (ohne Weiterleitung, damit testbar).
     *
     * @param array<string, mixed> $post
     * @return array{0: string, 1: bool} Meldung und ob sie ein Fehler ist
     */
    public static function applyMembersPlaceholder(App $app, array $post): array
    {
        if (isset($post['remove'])) {
            $app->settings->delete(Shortcodes::MEMBERS_PLACEHOLDER);
            return ['Platzhalterbild entfernt. Personen ohne Foto zeigen wieder die Anfangsbuchstaben.', false];
        }
        $id = isset($post['placeholder_id']) && is_scalar($post['placeholder_id']) ? absint($post['placeholder_id']) : 0;
        if ($id === 0 || !wp_attachment_is_image($id)) {
            return ['Bitte ein Bild aus der Mediathek wählen.', true];
        }
        $app->settings->set(Shortcodes::MEMBERS_PLACEHOLDER, $id);
        return ['Platzhalterbild gespeichert.', false];
    }

    /** Google-Zugang für Galerie und Kalender: „Mit Google verbinden“, darunter als Alternative das Dienstkonto. */
    private static function renderGoogle(App $app): void
    {
        $drive = $app->drive();
        $client = $drive->oauthClient();
        $email = $drive->clientEmail();
        $connected = $drive->oauth() !== null;
        $post = esc_url(admin_url('admin-post.php'));
        ?>
        <h2 id="google">Google-Zugang</h2>
        <p>Über diesen Zugang liest die App den Galerie-Ordner aus Google Drive und private Google Kalender (nur lesen).
           Empfohlen: <strong>Mit Google verbinden</strong> über einen eigenen OAuth-Client. Die Anleitung steht im Handbuch
           (Admin: Google einrichten).</p>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="google_redirect_uri">Weiterleitungs-URI</label></th>
                <td><input type="text" readonly class="large-text code" id="google_redirect_uri" value="<?php echo esc_attr($app->env->googleRedirectUri()); ?>" />
                <p class="description">Genau so im OAuth-Client bei Google unter „Autorisierte Weiterleitungs-URIs“ eintragen.</p></td>
            </tr>
        </table>
        <?php if ($connected) : ?>
            <p>✓ Verbunden mit Google<?php echo $drive->oauthEmail() !== '' ? ' als <strong>' . esc_html($drive->oauthEmail()) . '</strong>' : ''; ?>.
               <?php echo $drive->calendarAccess()
                   ? 'Es darf auch den Kalender lesen.'
                   : 'Den Kalender darf es noch nicht lesen. Für einen privaten Kalender „Verbindung trennen“ und neu verbinden, dabei beide Häkchen setzen.'; ?></p>
            <form method="post" action="<?php echo $post; ?>">
                <input type="hidden" name="action" value="kompanie_app_google_disconnect" />
                <?php wp_nonce_field('kompanie_app_google_disconnect'); ?>
                <?php submit_button('Verbindung trennen', 'secondary', 'submit', false); ?>
            </form>
        <?php else : ?>
            <form method="post" action="<?php echo $post; ?>">
                <input type="hidden" name="action" value="kompanie_app_google_connect" />
                <?php wp_nonce_field('kompanie_app_google_connect'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="google_client_id">Client-ID</label></th>
                        <td><input type="text" class="large-text code" id="google_client_id" name="client_id" autocomplete="off" placeholder="….apps.googleusercontent.com" value="<?php echo esc_attr($client['client_id']); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="google_client_secret">Clientschlüssel (Client-Secret)</label></th>
                        <td><input type="password" class="regular-text code" id="google_client_secret" name="client_secret" autocomplete="off" value="" placeholder="<?php echo esc_attr($client['client_secret_set'] ? 'gespeichert – leer lassen, um ihn zu behalten' : ''); ?>" /></td>
                    </tr>
                </table>
                <?php submit_button('Speichern und mit Google verbinden', 'primary', 'submit', false); ?>
                <p class="description">Danach meldest du dich bei Google mit dem Konto an, das den Galerie-Ordner und den Kalender sehen kann.
                   Zeigt Google „Google hat diese App nicht überprüft“, wähle „Erweitert“ und dann „Weiter zu …“ und setze beide Häkchen
                   (Google Drive und Google Kalender).</p>
            </form>
        <?php endif; ?>

        <h3>Alternative: Dienstkonto mit JSON-Schlüssel</h3>
        <p>Nur nötig, wenn du nicht „Mit Google verbinden“ nutzt.
            <?php if ($email !== '') : ?>
                Hinterlegt: <code><?php echo esc_html($email); ?></code>. Gib den Galerie-Ordner in Google Drive für diese Adresse frei (Rolle „Betrachter“),
                für Termine über die Kalender-IDs auch den Kalender („Alle Termindetails sehen“).
                <?php if ($connected) : ?><em>Wird nicht genutzt, solange die Verbindung mit Google besteht.</em><?php endif; ?>
            <?php endif; ?></p>
        <form method="post" action="<?php echo $post; ?>">
            <input type="hidden" name="action" value="kompanie_app_service_account" />
            <?php wp_nonce_field('kompanie_app_service_account'); ?>
            <p><label for="drive_sa">Schlüssel des Dienstkontos (JSON)</label><br />
               <textarea id="drive_sa" name="drive_sa" rows="4" class="large-text code" placeholder="<?php echo esc_attr($email !== '' ? 'Nur ausfüllen, um den Schlüssel zu ersetzen' : 'Inhalt der JSON-Datei hier einfügen'); ?>"></textarea></p>
            <?php submit_button('Schlüssel speichern', 'secondary', 'submit', false); ?>
            <?php if ($email !== '') : ?>
                <?php submit_button('Schlüssel entfernen', 'delete', 'remove', false); ?>
            <?php endif; ?>
        </form>
        <?php
    }

    /** Client speichern und zu Google weiterleiten. */
    private static function googleConnect(App $app): never
    {
        self::guard('kompanie_app_google_connect');
        try {
            $url = self::googleConnectUrl($app, $_POST);
        } catch (ApiError $e) {
            self::back($e->getMessage(), true, 'google');
        }
        // Externe Adresse: wp_safe_redirect() würde sie verwerfen.
        wp_redirect($url); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
        exit;
    }

    /**
     * Adresse der Anmeldung bei Google für das Formular (ohne Weiterleitung, damit testbar).
     *
     * @param array<string, mixed> $post
     * @throws ApiError bei ungültigem Client oder ohne Admin
     */
    public static function googleConnectUrl(App $app, array $post): string
    {
        $id = isset($post['client_id']) ? sanitize_text_field(wp_unslash((string) $post['client_id'])) : '';
        $secret = isset($post['client_secret']) ? sanitize_text_field(wp_unslash((string) $post['client_secret'])) : '';
        return $app->googleConnect()->start($id, $secret, GoogleConnect::RETURN_ADMIN);
    }

    private static function googleDisconnect(App $app): never
    {
        self::guard('kompanie_app_google_disconnect');
        try {
            $app->googleConnect()->disconnect();
        } catch (ApiError $e) {
            self::back($e->getMessage(), true, 'google');
        }
        self::back('Die Verbindung zu Google wurde getrennt.', false, 'google');
    }

    /** Rückkehr von Google auf admin-post.php: nur wenn das state von dieser App stammt. */
    private static function googleCallback(App $app): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Schutz über „state“ (an Benutzer und Sitzung gebunden)
        $query = wp_unslash($_GET);
        if (!is_array($query) || !GoogleConnect::isOwnState($query['state'] ?? null)) {
            return; // Nicht für uns (z. B. ein anderes Plugin auf derselben Adresse).
        }
        $target = self::googleCallbackTarget($app, $query);
        if ($target === null) {
            wp_die(
                esc_html('Bitte zuerst als Administrator in WordPress anmelden und die Verbindung mit Google dann erneut starten.'),
                'Keine Berechtigung',
                ['response' => 403],
            );
        }
        // Ziel in der App bzw. im WP-Admin derselben Website.
        wp_safe_redirect($target, 303);
        exit;
    }

    /**
     * Wertet die Rückkehr von Google aus und gibt das Ziel der Weiterleitung zurück (null ohne angemeldeten Admin).
     *
     * @param array<string, mixed> $query
     */
    public static function googleCallbackTarget(App $app, array $query): ?string
    {
        $query = array_map(static fn ($v) => is_string($v) ? sanitize_text_field($v) : '', $query);
        try {
            $result = $app->googleConnect()->callback($query);
        } catch (ApiError) {
            return null;
        }
        if ($result['return'] === GoogleConnect::RETURN_ADMIN) {
            return self::url(['kompanie_msg' => rawurlencode($result['message']), 'kompanie_error' => $result['ok'] ? 0 : 1]) . '#google';
        }
        return $app->googleConnect()->appReturnUrl();
    }

    private static function saveServiceAccount(App $app): never
    {
        self::guard('kompanie_app_service_account');
        $json = isset($_POST['drive_sa']) ? trim((string) wp_unslash($_POST['drive_sa'])) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- wird geprüft, nicht ausgegeben
        $hadCalendarIds = $app->calendar()->calendarIds() !== [];
        try {
            if (isset($_POST['remove'])) {
                $app->drive()->removeServiceAccount();
                $message = 'Schlüssel des Dienstkontos entfernt.';
            } elseif ($json !== '') {
                $app->drive()->saveServiceAccount($json);
                $message = 'Schlüssel des Dienstkontos gespeichert.';
            } else {
                self::back('Bitte den Inhalt der JSON-Datei einfügen.', true, 'google');
            }
        } catch (\RuntimeException $e) {
            self::back($e->getMessage(), true, 'google');
        }
        if ($hadCalendarIds) {
            $app->calendar()->clearCache();
        }
        $app->scheduleTasks();
        self::back($message, false, 'google');
    }

    private static function renderCalendarTest(App $app): void
    {
        $app->calendar()->clearCache();
        try {
            $events = $app->calendar()->upcoming();
            echo '<div class="notice notice-success inline"><p>' . esc_html(sprintf('%d Termine gefunden.', count($events))) . '</p><ul>';
            foreach (array_slice($events, 0, 5) as $ev) {
                echo '<li>' . esc_html($ev['start'] . ' – ' . $ev['title']) . '</li>';
            }
            echo '</ul></div>';
        } catch (\Throwable $e) {
            echo '<div class="notice notice-error inline"><p>' . esc_html($e->getMessage()) . '</p></div>';
        }
    }

    private static function saveMaintenance(App $app): never
    {
        self::guard('kompanie_app_maintenance');
        $on = isset($_POST['maintenance_on']) || isset($_POST['maintenance_keep']);
        $reason = isset($_POST['reason']) ? sanitize_textarea_field(wp_unslash($_POST['reason'])) : '';
        $actor = $app->authContext->currentUser();
        if ($actor === null) {
            wp_die('Keine Berechtigung.');
        }
        try {
            $app->maintenance()->set($on, $reason, $actor);
        } catch (ApiError $e) {
            self::back($e->getMessage(), true, 'wartung');
        }
        self::back($on ? 'Wartungsmodus ist an.' : 'Wartungsmodus beendet.', false, 'wartung');
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
        [$message, $error] = self::applyCalendar($app, wp_unslash($_POST));
        $app->scheduleTasks();
        self::back($message, $error, 'kalender', $error ? [] : ['kompanie_test' => 1]);
    }

    /**
     * Kalender aus dem Formular speichern (ohne Weiterleitung, damit testbar). Die Dauer des Zwischenspeichers (Issue
     * #177) prüft SettingsService wie in der App; ist sie ungültig, bleibt alles unverändert.
     *
     * @param array<string, mixed> $post
     * @return array{0: string, 1: bool} Meldung und ob sie ein Fehler ist
     */
    public static function applyCalendar(App $app, array $post): array
    {
        $text = static fn (string $key): string => isset($post[$key]) && is_scalar($post[$key]) ? (string) $post[$key] : '';
        if (isset($post['calendar_cache_minutes'])) {
            try {
                $app->settingsService()->update(['calendar_cache_minutes' => $text('calendar_cache_minutes')]);
            } catch (ApiError $e) {
                return [$e->getMessage(), true];
            }
        }
        $app->settings->set('calendar_ics_url', esc_url_raw(trim($text('calendar_ics_url')), ['http', 'https', 'webcal']));
        $app->settings->set('calendar_ids', sanitize_text_field($text('calendar_ids')));
        $app->settings->set('google_api_key', sanitize_text_field($text('google_api_key')));
        $app->calendar()->clearCache();
        return ['Gespeichert.', false];
    }

    private static function saveGallery(App $app): never
    {
        self::guard('kompanie_app_gallery');
        $messages = [];
        $error = false;
        try {
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
