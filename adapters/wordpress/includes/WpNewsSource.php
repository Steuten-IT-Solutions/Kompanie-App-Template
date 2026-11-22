<?php

declare(strict_types=1);

namespace KompanieApp\WordPress;

use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Domain\ContentTranslations;
use KompanieApp\Core\Port\NewsEditor;
use KompanieApp\Core\Port\NewsSource;

/**
 * Neuigkeiten aus den veröffentlichten Beiträgen der WordPress-Seite (ohne Kennwortschutz).
 *
 * Geschrieben werden sie im Editor von WordPress (NewsEditor): Wer in der App „News schreiben“ hat
 * (NewsService::canEditSite), bekommt dafür über den Filter user_has_cap die Rechte für Beiträge, wie ein Redakteur,
 * aber ohne Seiten und ohne ungefiltertes HTML.
 *
 * Mehrsprachig (Plan „Mehrsprachigkeit“, PR 9f): Ist Polylang aktiv, zeigt die Liste die deutschen Beiträge; wer die App
 * in einer weiteren Sprache nutzt, sieht statt eines Beitrags dessen veröffentlichte Übersetzung aus Polylang, sonst den
 * deutschen mit dem Hinweis „Nur auf Deutsch verfügbar“ (untranslated). Ohne Polylang gelten alle Beiträge als deutsch.
 */
final class WpNewsSource implements NewsSource, NewsEditor
{
    /** Rechte von WordPress für alle Beiträge und die Mediathek (keine Seiten, kein unfiltered_html). */
    public const POST_CAPS = [
        'read', 'upload_files',
        'edit_posts', 'edit_others_posts', 'edit_published_posts', 'edit_private_posts', 'publish_posts',
        'delete_posts', 'delete_others_posts', 'delete_published_posts', 'delete_private_posts', 'read_private_posts',
    ];

    /** @var array<int, bool> je Konto: darf Beiträge schreiben (für diesen Aufruf gemerkt) */
    private static array $writers = [];
    private static bool $checking = false;

    /**
     * @param ?\Closure(): ?string $locale weitere eingeschaltete Sprache der Anfrage (ContentTranslations::current), null =
     *        Deutsch
     */
    public function __construct(private readonly ?\Closure $locale = null)
    {
    }

    /**
     * Filter user_has_cap: Rechte für Beiträge an alle, die in der App die News schreiben dürfen.
     *
     * @param array<string, bool> $allcaps
     * @param array<int, string> $caps verlangte Grundrechte
     * @param \Closure(int): ?User $find Konto der App (WpUsers::find)
     * @param \Closure(User): bool $canEdit NewsService::canEditSite
     * @return array<string, bool>
     */
    public static function grant(array $allcaps, array $caps, int $userId, \Closure $find, \Closure $canEdit): array
    {
        $wanted = array_diff(array_intersect($caps, self::POST_CAPS), array_keys(array_filter($allcaps)));
        // Die Rechte der App fragen selbst WordPress-Rechte ab (WpUsers::toUser): nicht im Kreis laufen.
        if ($wanted === [] || $userId <= 0 || self::$checking) {
            return $allcaps;
        }
        if (!array_key_exists($userId, self::$writers)) {
            self::$checking = true;
            try {
                $user = $find($userId);
                self::$writers[$userId] = $user !== null && $canEdit($user);
            } catch (\Throwable $e) {
                self::$writers[$userId] = false;
            } finally {
                self::$checking = false;
            }
        }
        if (self::$writers[$userId]) {
            foreach ($wanted as $cap) {
                $allcaps[$cap] = true;
            }
        }
        return $allcaps;
    }

    /** Gemerkte Antworten vergessen (nach geänderten Rollen oder Einstellungen, in Tests). */
    public static function forget(): void
    {
        self::$writers = [];
    }

    public function newUrl(): string
    {
        return admin_url('post-new.php');
    }

    public function editUrl(string $id): ?string
    {
        $post = ctype_digit($id) ? get_post((int) $id) : null;
        if (!$post || $post->post_type !== 'post') {
            return null;
        }
        return admin_url('post.php?post=' . $post->ID . '&action=edit');
    }

    public function available(): bool
    {
        return true;
    }

    public function page(int $page, int $perPage): array
    {
        $args = [
            'post_type' => 'post',
            'post_status' => 'publish',
            'has_password' => false,
            'posts_per_page' => $perPage,
            'paged' => max(1, $page),
            'ignore_sticky_posts' => true,
        ];
        $german = self::germanLanguage();
        $others = $german !== null ? array_values(array_diff((array) pll_languages_list(['fields' => 'slug']), [$german])) : [];
        if ($others !== []) {
            // Polylang: die deutschen Beiträge und die ohne Sprache (Taxonomie language), ohne die der anderen Sprachen;
            // die Übersetzungen kommen je Beitrag dazu. Mit eigener Bedingung auf language filtert Polylang nicht selbst.
            $args['tax_query'] = [['taxonomy' => 'language', 'field' => 'slug', 'terms' => $others, 'operator' => 'NOT IN']];
        }
        $query = new \WP_Query($args);
        $items = [];
        foreach ($query->posts as $post) {
            if ($post instanceof \WP_Post) {
                [$shown, $untranslated] = $this->localized($post);
                $items[] = ['id' => (string) $post->ID] + $this->format($shown) + $untranslated;
            }
        }
        return ['items' => $items, 'totalPages' => (int) $query->max_num_pages];
    }

    public function item(string $id): ?array
    {
        $post = ctype_digit($id) ? get_post((int) $id) : null;
        if (!self::visible($post)) {
            return null;
        }
        [$shown, $untranslated] = $this->localized($post);
        $item = ['id' => (string) $post->ID] + $this->format($shown) + $untranslated;
        $post = $shown;
        // the_content-Filter erwarten den globalen Beitrag.
        $previous = $GLOBALS['post'] ?? null;
        $GLOBALS['post'] = $post;
        setup_postdata($post);
        $item['content'] = apply_filters('the_content', $post->post_content);
        $GLOBALS['post'] = $previous;
        wp_reset_postdata();
        return $item;
    }

    /** Veröffentlicht, ein Beitrag, ohne Kennwort? */
    private static function visible(mixed $post): bool
    {
        return $post instanceof \WP_Post && $post->post_type === 'post' && $post->post_status === 'publish' && $post->post_password === '';
    }

    /** Ist Polylang aktiv, die Sprache (Kürzel) der deutschen Beiträge: de, sonst die Standardsprache; sonst null. */
    private static function germanLanguage(): ?string
    {
        if (!function_exists('pll_languages_list') || !function_exists('pll_default_language') || !function_exists('pll_get_post')) {
            return null;
        }
        $slugs = pll_languages_list(['fields' => 'slug']);
        if (is_array($slugs) && in_array('de', $slugs, true)) {
            return 'de';
        }
        $default = pll_default_language('slug');
        return is_string($default) && $default !== '' ? $default : null;
    }

    /**
     * Der Beitrag in der Sprache der Anfrage: die veröffentlichte Übersetzung aus Polylang, sonst er selbst mit dem
     * Hinweis untranslated (nur in einer weiteren Sprache).
     *
     * @return array{0: \WP_Post, 1: array{untranslated?: string}}
     */
    private function localized(\WP_Post $post): array
    {
        $locale = $this->locale !== null ? ($this->locale)() : null;
        $german = self::germanLanguage();
        // Polylang: der Beitrag in der gewünschten Sprache (auf Deutsch auch, wenn ein übersetzter aufgerufen wird)
        $target = $locale ?? $german;
        if ($german !== null && $target !== null && function_exists('pll_get_post_language')) {
            if (pll_get_post_language($post->ID, 'slug') === $target) {
                return [$post, []];
            }
            $id = pll_get_post($post->ID, $target);
            $translation = is_int($id) && $id > 0 ? get_post($id) : null;
            if ($translation instanceof \WP_Post && self::visible($translation)) {
                return [$translation, []];
            }
        }
        return [$post, $locale !== null ? ['untranslated' => ContentTranslations::NONE] : []];
    }

    /** @return array<string, mixed> ohne id */
    private function format(\WP_Post $post): array
    {
        $excerpt = has_excerpt($post) ? $post->post_excerpt : wp_trim_words(wp_strip_all_tags(strip_shortcodes($post->post_content)), 40, ' …');
        $image = get_the_post_thumbnail_url($post, 'medium_large');
        return [
            'title' => html_entity_decode(get_the_title($post), ENT_QUOTES, 'UTF-8'),
            'date' => (string) get_post_time('c', true, $post),
            'excerpt' => html_entity_decode(wp_strip_all_tags($excerpt), ENT_QUOTES, 'UTF-8'),
            'image' => $image ?: null,
            'link' => (string) get_permalink($post),
        ];
    }
}
