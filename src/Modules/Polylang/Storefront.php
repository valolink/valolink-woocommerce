<?php

declare(strict_types=1);

namespace Valolink\Woo\Modules\Polylang;

/**
 * WooCommerce's pages and requests in the visitor's language.
 *
 * - Cart, checkout, my account and terms resolve to their translation in the current language.
 * - A translated shop page shows the product archive in its language, like the original does.
 * - WooCommerce's AJAX endpoint keeps the language, so add-to-cart and checkout run in it.
 * - Mini-cart fragments are cached per language, or switching shows the old language's names.
 * - Coupons limited to products or categories also accept their translations.
 */
final class Storefront
{
    private const PAGES = ['shop', 'cart', 'checkout', 'myaccount', 'terms', 'refund_returns'];

    public function register(): void
    {
        foreach (self::PAGES as $page) {
            add_filter('woocommerce_get_' . $page . '_page_id', [$this, 'page_id']);
        }
        add_action('pre_get_posts', [$this, 'translated_shop_page_is_archive'], 5);
        add_filter('woocommerce_ajax_get_endpoint', [$this, 'ajax_endpoint'], 10, 2);
        add_filter('woocommerce_cart_fragment_name', [$this, 'fragment_name']);

        foreach (['product_ids', 'excluded_product_ids'] as $prop) {
            add_filter('woocommerce_coupon_get_' . $prop, [$this, 'coupon_products']);
        }
        foreach (['product_categories', 'excluded_product_categories'] as $prop) {
            add_filter('woocommerce_coupon_get_' . $prop, [$this, 'coupon_categories']);
        }
    }

    public function page_id($id)
    {
        // WooCommerce's settings screen reads and saves these ids: keep it on the stored ones.
        if (is_admin() && !wp_doing_ajax() && !Pll::is_switched()) {
            return $id;
        }
        $lang = Pll::current();
        if ($lang === '' || (int) $id <= 0) {
            return $id;
        }
        return Pll::post_in((int) $id, $lang) ?: $id;
    }

    /**
     * The original shop page works because its address is also the product archive's. A
     * translation of it has its own address, which WordPress resolves as a plain page: turn that
     * request into the product archive, as WooCommerce did itself for verbose page rules.
     */
    public function translated_shop_page_is_archive($query): void
    {
        if (!$query instanceof \WP_Query || !$query->is_main_query() || is_admin() || !$query->is_page()) {
            return;
        }
        $shop = (int) get_option('woocommerce_shop_page_id');
        $page = isset($query->queried_object->ID) ? (int) $query->queried_object->ID : 0;
        if ($shop <= 0 || $page <= 0 || $page === $shop || !in_array($page, Pll::group($shop), true)) {
            return;
        }

        $query->set('post_type', 'product');
        $query->set('page', '');
        $query->set('pagename', '');
        $query->set('page_id', '');
        $query->is_archive = true;
        $query->is_post_type_archive = true;
        $query->is_singular = false;
        $query->is_page = false;
    }

    public function ajax_endpoint($url, $request)
    {
        $lang = Pll::current();
        if ($lang === '' || $lang === Pll::default_language()) {
            return $url;
        }
        $home = untrailingslashit((string) pll_home_url($lang));
        $path = (string) wp_parse_url($home, PHP_URL_PATH);
        // The endpoint is a root-relative "/?wc-ajax=…"; put the language's home path in front.
        if ($path !== '' && str_starts_with((string) $url, '/') && !str_starts_with((string) $url, $path . '/')) {
            return $path . $url;
        }
        return $url;
    }

    public function fragment_name($name)
    {
        $lang = Pll::current();
        return $lang === '' ? $name : $name . '_' . $lang;
    }

    /** @param int[] $ids */
    public function coupon_products($ids)
    {
        $out = (array) $ids;
        foreach ((array) $ids as $id) {
            $out = array_merge($out, Families::counterparts((int) $id));
        }
        return array_values(array_unique(array_map('intval', $out)));
    }

    /** @param int[] $ids */
    public function coupon_categories($ids)
    {
        $out = (array) $ids;
        foreach ((array) $ids as $id) {
            $out = array_merge($out, array_values(array_map('intval', (array) pll_get_term_translations((int) $id))));
        }
        return array_values(array_unique(array_map('intval', $out)));
    }
}
