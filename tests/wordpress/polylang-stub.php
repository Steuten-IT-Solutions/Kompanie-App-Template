<?php
/**
 * Nachbildung der Funktionen von Polylang, die WpNewsSource nutzt (pll_*), für die Tests ohne das Plugin: Sprache je
 * Beitrag und Gruppen von Übersetzungen in $GLOBALS['kpwp_pll'] (null = Polylang aus), Bedingung auf die Taxonomie language
 * über pre_get_posts. Nur im Test geladen.
 */

declare(strict_types=1);

/** @return array{lang: array<int, string>, groups: list<array<string, int>>}|null */
function kpwp_pll(): ?array
{
    return $GLOBALS['kpwp_pll'] ?? null;
}

function pll_languages_list(array $args = []): array
{
    return kpwp_pll() === null ? [] : ['de', 'en'];
}

function pll_default_language(string $field = 'slug'): string|false
{
    return kpwp_pll() === null ? false : 'de';
}

function pll_get_post_language(int $id, string $field = 'slug'): string|false
{
    return kpwp_pll()['lang'][$id] ?? false;
}

function pll_get_post(int $id, string $slug = ''): int|false|null
{
    foreach (kpwp_pll()['groups'] ?? [] as $group) {
        if (in_array($id, $group, true)) {
            return $group[$slug] ?? null;
        }
    }
    return null;
}

add_action('pre_get_posts', static function (\WP_Query $q): void {
    $pll = kpwp_pll();
    $tax = $q->get('tax_query');
    $cond = is_array($tax) ? ($tax[0] ?? null) : null;
    if ($pll === null || !is_array($cond) || ($cond['taxonomy'] ?? '') !== 'language' || ($cond['operator'] ?? '') !== 'NOT IN') {
        return;
    }
    // Ohne die Taxonomie im Test: Bedingung durch post__not_in ersetzen
    $q->set('tax_query', []);
    $ids = array_keys(array_filter($pll['lang'], static fn (string $l): bool => in_array($l, (array) $cond['terms'], true)));
    $q->set('post__not_in', $ids);
});
