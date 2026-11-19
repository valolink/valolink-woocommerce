<?php

declare(strict_types=1);

namespace Valolink\Woo\Modules\Polylang;

/**
 * Stock that orders, refunds and cancellations move (wc_update_product_stock) is applied to
 * every language version as the same change, not copied as a number: two orders placed at once
 * in different languages each reduce every copy, and neither overwrites the other.
 *
 * Stock typed in wp-admin is an ordinary save and goes through ProductSync.
 */
final class StockSync
{
    /** @var array<int, array{operation: string, amount: float}> Changes WooCommerce is about to write. */
    private static array $pending = [];

    /** @var array<int, true> Ids whose stock moved this request: ProductSync leaves their stock alone. */
    private static array $touched = [];

    public function register(): void
    {
        add_filter('woocommerce_update_product_stock_query', [$this, 'capture'], 10, 4);
        add_action('woocommerce_updated_product_stock', [$this, 'replicate']);
    }

    /** @return array<int, true> */
    public static function touched(): array
    {
        return self::$touched;
    }

    /** Runs just before WooCommerce's own UPDATE: note what it is about to do. */
    public function capture($sql, $product_id, $new_stock, $operation)
    {
        $product_id = (int) $product_id;
        if ($operation === 'set') {
            self::$pending[$product_id] = ['operation' => 'set', 'amount' => (float) $new_stock];
        } else {
            // WooCommerce computed $new_stock from the value it just read; the difference is
            // the change, whichever way it goes.
            $current = (float) get_post_meta($product_id, '_stock', true);
            self::$pending[$product_id] = ['operation' => 'change', 'amount' => (float) $new_stock - $current];
        }
        return $sql;
    }

    public function replicate($product_id): void
    {
        $product_id = (int) $product_id;
        $change = self::$pending[$product_id] ?? null;
        unset(self::$pending[$product_id]);
        if ($change === null || !pll_is_translated_post_type('product')) {
            return;
        }

        self::$touched[$product_id] = true;
        global $wpdb;
        $data_store = \WC_Data_Store::load('product');

        foreach (Families::counterparts($product_id) as $id) {
            self::$touched[$id] = true;
            add_post_meta($id, '_stock', 0, true);

            if ($change['operation'] === 'set') {
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$wpdb->postmeta} SET meta_value = %s WHERE post_id = %d AND meta_key = '_stock'",
                    wc_stock_amount($change['amount']),
                    $id,
                ));
            } else {
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$wpdb->postmeta} SET meta_value = meta_value %+f WHERE post_id = %d AND meta_key = '_stock'",
                    $change['amount'],
                    $id,
                ));
            }
            wp_cache_delete($id, 'post_meta');
            $data_store->update_lookup_table($id, 'wc_product_meta_lookup');

            // Saving re-reads the stock and recomputes the stock status, the out-of-stock
            // visibility term and the caches, as WooCommerce does after its own update.
            $product = wc_get_product($id);
            if ($product instanceof \WC_Product) {
                $data_store->read_stock_quantity($product);
                ProductSync::quietly(static fn () => UniqueIds::bypass(static fn () => $product->save()));
            }
        }
    }
}
