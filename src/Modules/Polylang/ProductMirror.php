<?php

declare(strict_types=1);

namespace Valolink\Woo\Modules\Polylang;

/**
 * Copies what a product's translations share from one member to another, through WooCommerce's
 * own CRUD so lookup tables, transients and the variable product's price range stay right.
 *
 * Shared: everything in PRODUCT_PROPS / VARIATION_PROPS, the product type, categories, tags,
 * brands, attributes and the variations themselves. Per language: name, slug, descriptions,
 * purchase note, button text, status, reviews, the names and values of custom attributes, and
 * variation descriptions (copied once, when the variation is first made).
 *
 * Custom (non-taxonomy) attributes are matched by position: a translation keeps its own name
 * and values for the attribute and option at the same place in the list. Values added later in
 * the source arrive in the source language until someone translates them.
 */
final class ProductMirror
{
    private const PRODUCT_PROPS = [
        'regular_price', 'sale_price', 'date_on_sale_from', 'date_on_sale_to',
        'sku', 'global_unique_id', 'manage_stock', 'stock_quantity', 'stock_status', 'backorders',
        'low_stock_amount', 'sold_individually', 'weight', 'length', 'width', 'height',
        'shipping_class_id', 'tax_status', 'tax_class', 'image_id', 'gallery_image_ids',
        'featured', 'catalog_visibility', 'virtual', 'downloadable', 'downloads',
        'download_limit', 'download_expiry', 'reviews_allowed', 'menu_order', 'product_url',
    ];

    private const VARIATION_PROPS = [
        'regular_price', 'sale_price', 'date_on_sale_from', 'date_on_sale_to',
        'sku', 'global_unique_id', 'manage_stock', 'stock_quantity', 'stock_status', 'backorders',
        'low_stock_amount', 'weight', 'length', 'width', 'height', 'shipping_class_id',
        'tax_class', 'image_id', 'virtual', 'downloadable', 'downloads', 'download_limit',
        'download_expiry', 'menu_order', 'status',
    ];

    /** Left out when the change came from a stock operation, which StockSync has applied already. */
    private const STOCK_PROPS = ['stock_quantity', 'stock_status'];

    /**
     * Bring $to fully in line with $from: product data, then every variation (made, updated or
     * removed to match).
     */
    public static function full(int $from_id, int $to_id): void
    {
        $from = wc_get_product($from_id);
        if (!$from instanceof \WC_Product) {
            return;
        }

        $to = self::product($from, $to_id, true);
        if ($to === null) {
            return;
        }

        $from_variations = $from->is_type('variable') ? Families::variations_by_key($from_id) : [];
        $to_variations = Families::variations_by_key($to->get_id());

        foreach ($from_variations as $variation_id) {
            Families::ensure_variation_key($variation_id);
        }
        foreach ($from_variations as $key => $variation_id) {
            self::variation($variation_id, $from, $to, $to_variations[$key] ?? 0, true);
        }
        foreach (array_diff_key($to_variations, $from_variations) as $orphan) {
            self::delete_variation($orphan);
        }

        if ($to->is_type('variable')) {
            \WC_Product_Variable::sync($to->get_id());
        }
        wc_delete_product_transients($to->get_id());
        update_post_meta($to->get_id(), Families::SYNCED_META, '1');
    }

    /**
     * Product-level data from $from into $to_id. Returns the updated product, or null when
     * $to_id is not a product.
     */
    public static function product(\WC_Product $from, int $to_id, bool $with_stock): ?\WC_Product
    {
        $to = wc_get_product($to_id);
        if (!$to instanceof \WC_Product) {
            return null;
        }
        $lang = Pll::post_language($to_id);

        if ($to->get_type() !== $from->get_type()) {
            $class = \WC_Product_Factory::get_product_classname($to_id, $from->get_type());
            $to = new $class($to_id);
        }

        self::copy_props($from, $to, self::props(self::PRODUCT_PROPS, $with_stock));

        $to->set_category_ids(TermMapper::map_ids($from->get_category_ids('edit'), 'product_cat', $lang));
        $to->set_tag_ids(TermMapper::map_ids($from->get_tag_ids('edit'), 'product_tag', $lang));
        $to->set_attributes(self::merged_attributes($from, $to, $lang));
        $to->set_upsell_ids(self::map_posts($from->get_upsell_ids('edit'), $lang));
        $to->set_cross_sell_ids(self::map_posts($from->get_cross_sell_ids('edit'), $lang));
        if ($from->is_type('grouped')) {
            $to->set_children(self::map_posts($from->get_children('edit'), $lang));
        }
        if ($from->is_type('variable')) {
            $to->set_default_attributes(self::map_variation_attributes($from->get_default_attributes('edit'), $from, $to));
        }

        UniqueIds::bypass(static fn () => $to->save());

        if (taxonomy_exists('product_brand')) {
            $brands = wp_get_object_terms($from->get_id(), 'product_brand', ['fields' => 'ids']);
            if (is_array($brands)) {
                wp_set_object_terms($to->get_id(), TermMapper::map_ids($brands, 'product_brand', $lang), 'product_brand');
            }
        }

        if (!$from->is_type('variable')) {
            foreach (Families::variations_by_key($to->get_id()) as $orphan) {
                self::delete_variation($orphan);
            }
        }

        return wc_get_product($to->get_id()) ?: $to;
    }

    /**
     * One variation of $from_parent into $to_parent: updates $to_variation_id, or makes a new
     * variation there when it is 0. Attribute values are carried over by position.
     */
    public static function variation(int $variation_id, \WC_Product $from_parent, \WC_Product $to_parent, int $to_variation_id, bool $with_stock): void
    {
        $from = wc_get_product($variation_id);
        if (!$from instanceof \WC_Product_Variation) {
            return;
        }
        $key = Families::ensure_variation_key($variation_id);

        $creating = $to_variation_id <= 0;
        $to = $creating ? new \WC_Product_Variation() : wc_get_product($to_variation_id);
        if (!$to instanceof \WC_Product_Variation) {
            return;
        }

        if ($creating) {
            $to->set_parent_id($to_parent->get_id());
            $to->set_description($from->get_description('edit'));
        }
        self::copy_props($from, $to, self::props(self::VARIATION_PROPS, $with_stock));
        $to->set_attributes(self::map_variation_attributes($from->get_attributes('edit'), $from_parent, $to_parent));

        UniqueIds::bypass(static fn () => $to->save());
        if ($creating || (int) get_post_meta($to->get_id(), Families::VARIATION_KEY, true) !== $key) {
            update_post_meta($to->get_id(), Families::VARIATION_KEY, $key);
        }
    }

    /**
     * Re-derive the attribute values of $member's variations from the source's matching
     * variations. Run after attributes change anywhere, so a translation whose option names
     * were just translated gets matching values in its variations.
     */
    public static function remap_variation_attributes(\WC_Product $source, \WC_Product $member): void
    {
        if (!$source->is_type('variable') || !$member->is_type('variable')) {
            return;
        }

        $source_variations = Families::variations_by_key($source->get_id());
        foreach (Families::variations_by_key($member->get_id()) as $key => $variation_id) {
            if (!isset($source_variations[$key])) {
                continue;
            }
            $from = wc_get_product($source_variations[$key]);
            $to = wc_get_product($variation_id);
            if (!$from instanceof \WC_Product_Variation || !$to instanceof \WC_Product_Variation) {
                continue;
            }
            $mapped = self::map_variation_attributes($from->get_attributes('edit'), $source, $member);
            if ($mapped != $to->get_attributes('edit')) {
                $to->set_attributes($mapped);
                $to->save();
            }
        }
        \WC_Product_Variable::sync($member->get_id());
    }

    public static function delete_variation(int $variation_id): void
    {
        $variation = wc_get_product($variation_id);
        if ($variation instanceof \WC_Product_Variation) {
            $variation->delete(true);
        }
    }

    /** @param string[] $props */
    private static function copy_props(\WC_Product $from, \WC_Product $to, array $props): void
    {
        foreach ($props as $prop) {
            $get = 'get_' . $prop;
            $set = 'set_' . $prop;
            if (!is_callable([$from, $get]) || !is_callable([$to, $set])) {
                continue;
            }
            try {
                UniqueIds::bypass(static fn () => $to->$set($from->$get('edit')));
            } catch (\WC_Data_Exception $e) {
                error_log(sprintf('[valolink-woocommerce] polylang: %s not copied from %d to %d: %s', $prop, $from->get_id(), $to->get_id(), $e->getMessage()));
            }
        }
    }

    /**
     * @param string[] $props
     * @return string[]
     */
    private static function props(array $props, bool $with_stock): array
    {
        return $with_stock ? $props : array_values(array_diff($props, self::STOCK_PROPS));
    }

    /**
     * @param int[] $ids
     * @return int[]
     */
    private static function map_posts(array $ids, string $lang): array
    {
        $out = [];
        foreach ($ids as $id) {
            // Untranslated ones stay as they are: dropping them would lose them from the source
            // too, the next time the translation is saved and copied back.
            $out[] = Pll::post_in((int) $id, $lang) ?: (int) $id;
        }
        return array_values(array_unique($out));
    }

    /**
     * $from's attributes for $to: structure, visibility and taxonomy terms (translated) from
     * $from; names and values of custom attributes from $to where it already has them.
     *
     * @return \WC_Product_Attribute[]
     */
    private static function merged_attributes(\WC_Product $from, \WC_Product $to, string $lang): array
    {
        $theirs = array_values($to->get_attributes('edit'));
        $out = [];

        foreach (array_values($from->get_attributes('edit')) as $i => $source) {
            $attribute = new \WC_Product_Attribute();
            $attribute->set_id($source->get_id());
            $attribute->set_name($source->get_name());
            $attribute->set_position($source->get_position());
            $attribute->set_visible($source->get_visible());
            $attribute->set_variation($source->get_variation());

            if ($source->is_taxonomy()) {
                $attribute->set_options(TermMapper::map_ids($source->get_options(), $source->get_name(), $lang));
            } else {
                $own = $theirs[$i] ?? null;
                $own = $own instanceof \WC_Product_Attribute && !$own->is_taxonomy() ? $own : null;
                $own_options = $own ? array_values($own->get_options()) : [];
                $options = [];
                foreach (array_values($source->get_options()) as $j => $option) {
                    $options[] = $own_options[$j] ?? $option;
                }
                if ($own) {
                    $attribute->set_name($own->get_name());
                }
                $attribute->set_options($options);
            }

            $out[] = $attribute;
        }

        return $out;
    }

    /**
     * Variation attribute values (or default attributes) of $from_parent, as the matching
     * values of $to_parent: by position for custom attributes, by term translation for
     * taxonomy ones. Keys are attribute names without the 'attribute_' prefix.
     *
     * @param array<string, string> $values
     * @return array<string, string>
     */
    public static function map_variation_attributes(array $values, \WC_Product $from_parent, \WC_Product $to_parent): array
    {
        $from_attributes = $from_parent->get_attributes('edit');
        $to_keys = array_keys($to_parent->get_attributes('edit'));
        $to_attributes = array_values($to_parent->get_attributes('edit'));
        $lang = Pll::post_language($to_parent->get_id());

        $out = [];
        foreach (array_keys($from_attributes) as $i => $from_key) {
            if (!array_key_exists($from_key, $values) || !isset($to_keys[$i])) {
                continue;
            }
            $out[$to_keys[$i]] = self::map_value((string) $values[$from_key], $from_attributes[$from_key], $to_attributes[$i], $lang);
        }
        return $out;
    }

    private static function map_value(string $value, \WC_Product_Attribute $from, \WC_Product_Attribute $to, string $lang): string
    {
        if ($value === '') {
            return '';
        }

        if ($from->is_taxonomy()) {
            $term = get_term_by('slug', $value, $from->get_name());
            if (!$term instanceof \WP_Term) {
                return $value;
            }
            $mapped = get_term(TermMapper::map((int) $term->term_id, $from->get_name(), $lang), $from->get_name());
            return $mapped instanceof \WP_Term ? $mapped->slug : $value;
        }

        $to_options = array_values($to->get_options());
        foreach (array_values($from->get_options()) as $j => $option) {
            if ($option === $value || sanitize_title($option) === sanitize_title($value)) {
                return (string) ($to_options[$j] ?? $value);
            }
        }
        return $value;
    }
}
