<?php

declare(strict_types=1);

namespace KompanieApp\WordPress;

use KompanieApp\Core\Port\NewsSource;

/** Neuigkeiten aus den veröffentlichten Beiträgen der WordPress-Seite (ohne Kennwortschutz). */
final class WpNewsSource implements NewsSource
{
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
