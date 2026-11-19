<?php

declare(strict_types=1);

namespace Valolink\Woo\Modules\Polylang;

use Valolink\Woo\Settings;

/**
 * The one-time step that hands products over to Polylang, and the status shown before and after.
 *
 * Until setup has run the module changes nothing for visitors. Polylang hides content that has
 * no language, so making products translatable before they have one would empty the shop: setup
 * first tags every product, category, tag, brand and attribute term with the default language,
 * then switches translation on, then makes the missing translations of WooCommerce's own pages.
 */
final class Setup
{
    /** English titles for the pages setup makes; other languages start with the source title. */
    private const PAGE_TITLES_EN = [
        'shop'      => 'Shop',
        'cart'      => 'Cart',
        'checkout'  => 'Checkout',
        'myaccount' => 'My account',
    ];

    public function __construct(private readonly Settings $settings) {}

    public function is_done(): bool
    {
        return (bool) $this->settings->get_module_setting(PolylangModule::MODULE_ID, 'setup_done', false);
    }

    /** @return array<string, int|string> what setup did */
    public function run(): array
    {
        if (Pll::wc_addon_active()) {
            throw new \RuntimeException('Polylang for WooCommerce is active: deactivate it first.');
        }
        $default = Pll::default_language();
        if ($default === '') {
            throw new \RuntimeException('Polylang has no default language yet: add the languages first.');
        }

        $posts = $this->untagged_products();
        Pll::tag_posts($posts, $default);

        $terms = $this->untagged_terms();
        Pll::tag_terms($terms, $default);

        $this->settings->set_module_setting(PolylangModule::MODULE_ID, 'setup_done', true);
        $this->settings->set_module_setting(PolylangModule::MODULE_ID, 'flush_rewrite', true);
        TranslatableTypes::enable();

        $pages = $this->translate_pages();

        return ['products' => count($posts), 'terms' => count($terms), 'pages' => $pages, 'language' => $default];
    }

    /** Product counts per language, and how many have no translation in each other language. */
    public function status(): array
    {
        $out = ['setup_done' => $this->is_done(), 'untagged_products' => count($this->untagged_products()), 'untagged_terms' => count($this->untagged_terms()), 'languages' => []];
        if (!$this->is_done()) {
            return $out;
        }

        $default = Pll::default_language();
        $sources = $this->products_in($default, 'publish');
        foreach (Pll::languages() as $lang) {
            $row = ['products' => count($this->products_in($lang, 'any')), 'published' => count($this->products_in($lang, 'publish'))];
            if ($lang !== $default) {
                $row['untranslated'] = count(array_filter($sources, static fn (int $id): bool => Pll::post_in($id, $lang) <= 0));
            }
            $out['languages'][$lang] = $row;
        }

        $pages = [];
        foreach (array_keys(self::PAGE_TITLES_EN) as $page) {
            $id = (int) get_option('woocommerce_' . $page . '_page_id');
            $pages[$page] = $id > 0 ? Pll::group($id) : [];
        }
        $out['pages'] = $pages;

        return $out;
    }

    /** @return int[] */
    private function products_in(string $lang, string $status): array
    {
        return array_map('intval', get_posts([
            'post_type'        => 'product',
            'post_status'      => $status,
            'lang'             => $lang,
            'posts_per_page'   => -1,
            'fields'           => 'ids',
            'suppress_filters' => false,
        ]));
    }

    /** @return int[] */
    private function untagged_products(): array
    {
        global $wpdb;
        return array_map('intval', $wpdb->get_col(
            "SELECT p.ID FROM {$wpdb->posts} p
             WHERE p.post_type = 'product' AND p.post_status NOT IN ('auto-draft', 'trash')
               AND NOT EXISTS (
                 SELECT 1 FROM {$wpdb->term_relationships} tr
                 JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'language'
                 WHERE tr.object_id = p.ID)"
        ));
    }

    /** @return int[] */
    private function untagged_terms(): array
    {
        global $wpdb;
        $taxonomies = TranslatableTypes::taxonomies();
        if ($taxonomies === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($taxonomies), '%s'));
        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT tt.term_id FROM {$wpdb->term_taxonomy} tt
             WHERE tt.taxonomy IN ($in)
               AND NOT EXISTS (
                 SELECT 1 FROM {$wpdb->term_relationships} tr
                 JOIN {$wpdb->term_taxonomy} lt ON lt.term_taxonomy_id = tr.term_taxonomy_id AND lt.taxonomy = 'term_language'
                 WHERE tr.object_id = tt.term_id)",
            ...$taxonomies,
        )));
    }

    /**
     * Published translations of the shop, cart, checkout and my account pages in every language
     * that lacks them. Their content is WooCommerce's shortcode or block, the same in every
     * language; the terms page is left for a real translation. They are copied from the
     * default-language page, which may not be the one WooCommerce's setting names (a site that
     * had Polylang for WooCommerce can point at its English pages), because a site's own
     * additions to the cart or checkout page live on the page its shoppers actually see.
     *
     * @return int pages made
     */
    private function translate_pages(): int
    {
        $made = 0;
        foreach (self::PAGE_TITLES_EN as $page => $english) {
            $source_id = (int) get_option('woocommerce_' . $page . '_page_id');
            $source = $source_id > 0 ? get_post($source_id) : null;
            if (!$source instanceof \WP_Post) {
                continue;
            }
            $source_lang = Pll::post_language($source_id);
            if ($source_lang === '') {
                $source_lang = Pll::default_language();
                pll_set_post_language($source_id, $source_lang);
            }

            foreach (Pll::languages() as $lang) {
                $group = Pll::group($source_id);
                if (isset($group[$lang])) {
                    continue;
                }
                $from_id = Pll::source($group);
                $from = $from_id > 0 ? get_post($from_id) : null;
                $from = $from instanceof \WP_Post ? $from : $source;
                $title = $lang === 'en' ? $english : $from->post_title;
                $id = pll_insert_post([
                    'post_type'    => 'page',
                    'post_status'  => 'publish',
                    'post_title'   => $title,
                    'post_name'    => sanitize_title($title),
                    'post_content' => $from->post_content,
                    'post_author'  => (int) $from->post_author,
                    'translations' => $group,
                ], $lang);
                if (is_wp_error($id)) {
                    error_log('[valolink-woocommerce] polylang: could not make the ' . $lang . ' ' . $page . ' page: ' . $id->get_error_message());
                    continue;
                }
                pll_save_post_translations($group + [$lang => (int) $id]);
                foreach (get_post_meta($from->ID) as $key => $values) {
                    if (in_array($key, ['_edit_lock', '_edit_last'], true)) {
                        continue;
                    }
                    foreach ((array) $values as $value) {
                        add_post_meta((int) $id, (string) $key, maybe_unserialize($value));
                    }
                }
                $made++;
            }
        }
        return $made;
    }
}
