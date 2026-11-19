<?php

declare(strict_types=1);

namespace Valolink\Woo\Modules\Polylang;

/**
 * A product's translations share its SKU and GTIN, which WooCommerce otherwise refuses as
 * duplicates. Another product in the same family is not a duplicate; anything else still is.
 */
final class UniqueIds
{
    /** While > 0 the check is off: the module is copying a family's own ids between its members. */
    private static int $bypass = 0;

    public function register(): void
    {
        add_filter('wc_product_has_unique_sku', [$this, 'filter_sku'], 10, 3);
        add_filter('wc_product_has_global_unique_id', [$this, 'filter_global_unique_id'], 10, 3);
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public static function bypass(callable $fn): mixed
    {
        self::$bypass++;
        try {
            return $fn();
        } finally {
            self::$bypass--;
        }
    }

    /** Return value: whether a clashing SKU was found (truthy = not unique). */
    public function filter_sku($found, $product_id, $sku)
    {
        return $found ? $this->clashes((int) $product_id, '_sku', (string) $sku) : $found;
    }

    public function filter_global_unique_id($found, $product_id, $global_unique_id)
    {
        return $found ? $this->clashes((int) $product_id, '_global_unique_id', (string) $global_unique_id) : $found;
    }

    private function clashes(int $product_id, string $meta_key, string $value): bool
    {
        if (self::$bypass > 0) {
            return false;
        }
        if ($product_id <= 0) {
            return true;
        }

        global $wpdb;
        $others = $wpdb->get_col($wpdb->prepare(
            "SELECT pm.post_id FROM {$wpdb->postmeta} pm
             JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = %s AND pm.meta_value = %s AND pm.post_id <> %d
               AND p.post_status <> 'trash' AND p.post_type IN ('product', 'product_variation')",
            $meta_key,
            $value,
            $product_id,
        ));
        if ($others === []) {
            return false;
        }

        $family = Families::counterparts($product_id);
        foreach ($others as $other) {
            if (!in_array((int) $other, $family, true)) {
                return true;
            }
        }
        return false;
    }
}
