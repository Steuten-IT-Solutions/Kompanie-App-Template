<?php

declare(strict_types=1);

namespace KompanieApp\WordPress;

use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Port\NewsEditor;
use KompanieApp\Core\Port\NewsSource;

/**
 * Neuigkeiten aus den veröffentlichten Beiträgen der WordPress-Seite (ohne Kennwortschutz).
 *
 * Geschrieben werden sie im Editor von WordPress (NewsEditor): Wer in der App „News schreiben“ hat
 * (NewsService::canEditSite), bekommt dafür über den Filter user_has_cap die Rechte für Beiträge, wie ein Redakteur,
 * aber ohne Seiten und ohne ungefiltertes HTML.
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
        $query = new \WP_Query([
            'post_type' => 'post',
            'post_status' => 'publish',
            'has_password' => false,
            'posts_per_page' => $perPage,
            'paged' => max(1, $page),
            'ignore_sticky_posts' => true,
        ]);
        return [
            'items' => array_map([$this, 'format'], $query->posts),
            'totalPages' => (int) $query->max_num_pages,
        ];
    }

    public function item(string $id): ?array
    {
        $post = ctype_digit($id) ? get_post((int) $id) : null;
        if (!$post || $post->post_type !== 'post' || $post->post_status !== 'publish' || $post->post_password !== '') {
            return null;
        }
        $item = $this->format($post);
        // the_content-Filter erwarten den globalen Beitrag.
        $previous = $GLOBALS['post'] ?? null;
        $GLOBALS['post'] = $post;
        setup_postdata($post);
        $item['content'] = apply_filters('the_content', $post->post_content);
        $GLOBALS['post'] = $previous;
        wp_reset_postdata();
        return $item;
    }

    private function format(\WP_Post $post): array
    {
        $excerpt = has_excerpt($post) ? $post->post_excerpt : wp_trim_words(wp_strip_all_tags(strip_shortcodes($post->post_content)), 40, ' …');
        $image = get_the_post_thumbnail_url($post, 'medium_large');
        return [
            'id' => (string) $post->ID,
            'title' => html_entity_decode(get_the_title($post), ENT_QUOTES, 'UTF-8'),
            'date' => (string) get_post_time('c', true, $post),
            'excerpt' => html_entity_decode(wp_strip_all_tags($excerpt), ENT_QUOTES, 'UTF-8'),
            'image' => $image ?: null,
            'link' => (string) get_permalink($post),
        ];
    }
}
