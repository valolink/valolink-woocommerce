<?php

declare(strict_types=1);

namespace Valolink\Woo\Modules\Polylang;

/**
 * Makes products, their categories, tags, brands and attribute terms translatable in Polylang,
 * whatever its own settings say. Variations, shipping classes and WooCommerce's internal
 * taxonomies (product_type, product_visibility) stay out: they are shared by every language.
 */
final class TranslatableTypes
{
    private static bool $enabled = false;

    /** Hooked on plugins_loaded: Polylang caches these lists once the theme is set up. */
    public static function enable(): void
    {
        if (self::$enabled) {
            return;
        }
        self::$enabled = true;
        add_filter('pll_get_post_types', [self::class, 'post_types'], 10, 2);
        add_filter('pll_get_taxonomies', [self::class, 'taxonomies_filter'], 10, 2);

        // Setup runs mid-request, after Polylang may have cached the lists without us.
        if (did_action('after_setup_theme') && function_exists('PLL') && isset(PLL()->model)) {
            PLL()->model->languages->clean_local_cache();
        }
    }

    /** @param array<string, string> $post_types */
    public static function post_types($post_types, $is_settings)
    {
        $post_types['product'] = 'product';
        return $post_types;
    }

    /** @param array<string, string> $taxonomies */
    public static function taxonomies_filter($taxonomies, $is_settings)
    {
        foreach (self::taxonomies() as $taxonomy) {
            $taxonomies[$taxonomy] = $taxonomy;
        }
        return $taxonomies;
    }

    /** @return string[] */
    public static function taxonomies(): array
    {
        $taxonomies = ['product_cat', 'product_tag', 'product_brand'];
        if (function_exists('wc_get_attribute_taxonomy_names')) {
            $taxonomies = array_merge($taxonomies, wc_get_attribute_taxonomy_names());
        }
        return $taxonomies;
    }
}
