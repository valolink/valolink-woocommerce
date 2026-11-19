<?php

declare(strict_types=1);

namespace Valolink\Woo\Modules\Polylang;

/**
 * Thin layer over Polylang's documented pll_* API, plus the two things it lacks: switching the
 * language mid-request (for emails) and tagging many objects at once (for setup).
 */
final class Pll
{
    /** Set while switch_to() is in effect, so page-id mapping also runs in admin requests. */
    private static int $switched = 0;

    public static function available(): bool
    {
        return function_exists('pll_languages_list') && function_exists('pll_insert_term') && function_exists('PLL');
    }

    /**
     * Polylang for WooCommerce, the paid add-on, does the same job: both would map the shop's
     * pages and sync products. The module stays off while it is active.
     */
    public static function wc_addon_active(): bool
    {
        return defined('PLLWC_VERSION');
    }

    public static function default_language(): string
    {
        return (string) pll_default_language('slug');
    }

    /** @return string[] */
    public static function languages(): array
    {
        return array_map('strval', (array) pll_languages_list(['fields' => 'slug']));
    }

    /** The request's language, or '' when Polylang has none (admin showing all languages, CLI). */
    public static function current(): string
    {
        $lang = pll_current_language('slug');
        return is_string($lang) ? $lang : '';
    }

    public static function post_language(int $post_id): string
    {
        $lang = pll_get_post_language($post_id, 'slug');
        return is_string($lang) ? $lang : '';
    }

    public static function term_language(int $term_id): string
    {
        $lang = pll_get_term_language($term_id, 'slug');
        return is_string($lang) ? $lang : '';
    }

    /**
     * The post's translation group as lang => id, the post itself included. Trashed and missing
     * members are left out: nothing is synced into the trash.
     *
     * @return array<string, int>
     */
    public static function group(int $post_id): array
    {
        $out = [];
        foreach ((array) pll_get_post_translations($post_id) as $lang => $id) {
            $status = get_post_status((int) $id);
            if ($status !== false && $status !== 'trash') {
                $out[(string) $lang] = (int) $id;
            }
        }
        return $out;
    }

    /**
     * The member the others follow: the default-language one, else the oldest. Only matters
     * when a translation first joins (it copies from here) and for variation attribute values.
     *
     * @param array<string, int> $group
     */
    public static function source(array $group): int
    {
        return $group[self::default_language()] ?? ($group === [] ? 0 : min($group));
    }

    /** The post's translation into $lang, or 0. */
    public static function post_in(int $post_id, string $lang): int
    {
        return (int) pll_get_post($post_id, $lang);
    }

    public static function language_object(string $slug): ?\PLL_Language
    {
        $lang = PLL()->model->get_language($slug);
        return $lang instanceof \PLL_Language ? $lang : null;
    }

    /**
     * Switch the request to another language: Polylang's current language, the WordPress
     * locale (and with it every loaded textdomain) and Polylang's string translations.
     *
     * @return array{prev: mixed, locale: bool}|null Token for restore(); null when nothing changed.
     */
    public static function switch_to(string $slug): ?array
    {
        $lang = self::language_object($slug);
        if ($lang === null) {
            return null;
        }

        $polylang = PLL();
        $prev = $polylang->curlang ?? null;
        $polylang->curlang = $lang;
        $locale = switch_to_locale($lang->locale);
        // After the locale switch: switching reloads textdomains and would drop these again.
        $polylang->load_strings_translations($lang->slug);
        self::$switched++;

        return ['prev' => $prev, 'locale' => $locale];
    }

    public static function restore(?array $token): void
    {
        if ($token === null) {
            return;
        }

        if ($token['locale']) {
            restore_previous_locale();
        }
        $polylang = PLL();
        $polylang->curlang = $token['prev'];
        $polylang->load_strings_translations($token['prev'] instanceof \PLL_Language ? $token['prev']->slug : '');
        self::$switched = max(0, self::$switched - 1);
    }

    public static function is_switched(): bool
    {
        return self::$switched > 0;
    }

    /**
     * Tag posts with a language in one query, as Polylang's own "set default language" admin
     * action does. Only for posts that have no language yet.
     *
     * @param int[] $ids
     */
    public static function tag_posts(array $ids, string $slug): void
    {
        $lang = self::language_object($slug);
        if ($lang !== null && $ids !== []) {
            PLL()->model->post->set_language_in_mass($ids, $lang);
        }
    }

    /** @param int[] $ids */
    public static function tag_terms(array $ids, string $slug): void
    {
        $lang = self::language_object($slug);
        if ($lang !== null && $ids !== []) {
            PLL()->model->term->set_language_in_mass($ids, $lang);
        }
    }
}
