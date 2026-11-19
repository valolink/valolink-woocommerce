<?php

declare(strict_types=1);

namespace Valolink\Woo\Modules\Polylang;

/**
 * A cart filled in one language and viewed in another shows the other language's products:
 * each line is swapped for its translation (and variation), keeping quantity and any extra
 * data plugins added to it. Lines whose product has no translation stay as they are.
 */
final class CartLanguage
{
    /** Line keys WooCommerce derives from the product; everything else is a plugin's data. */
    private const DERIVED = [
        'key', 'product_id', 'variation_id', 'variation', 'quantity', 'data', 'data_hash',
        'line_tax_data', 'line_subtotal', 'line_subtotal_tax', 'line_total', 'line_tax',
    ];

    public function register(): void
    {
        add_action('woocommerce_cart_loaded_from_session', [$this, 'translate'], 20);
    }

    public function translate($cart): void
    {
        $lang = Pll::current();
        if ($lang === '' || !$cart instanceof \WC_Cart) {
            return;
        }

        $changed = false;
        $lines = [];
        foreach ($cart->get_cart_contents() as $key => $line) {
            $product_id = (int) $line['product_id'];
            $variation_id = (int) ($line['variation_id'] ?? 0);
            $to_product = Pll::post_in($product_id, $lang);

            if ($to_product <= 0 || $to_product === $product_id) {
                $lines[$key] = $line;
                continue;
            }

            $to_variation = $variation_id > 0 ? Families::variation_in($variation_id, $to_product) : 0;
            if ($variation_id > 0 && $to_variation <= 0) {
                $lines[$key] = $line;
                continue;
            }

            $item = wc_get_product($to_variation ?: $to_product);
            if (!$item instanceof \WC_Product) {
                $lines[$key] = $line;
                continue;
            }

            $variation = (array) ($line['variation'] ?? []);
            if ($to_variation > 0) {
                $variation = $this->map_selection($variation, $product_id, $to_product);
            }

            $extra = array_diff_key($line, array_flip(self::DERIVED));
            $new_key = $cart->generate_cart_id($to_product, $to_variation, $variation, $extra);

            $line['key'] = $new_key;
            $line['product_id'] = $to_product;
            $line['variation_id'] = $to_variation;
            $line['variation'] = $variation;
            $line['data'] = $item;
            $line['data_hash'] = wc_get_cart_item_data_hash($item);

            if (isset($lines[$new_key])) {
                $lines[$new_key]['quantity'] += $line['quantity'];
            } else {
                $lines[$new_key] = $line;
            }
            $changed = true;
        }

        if ($changed) {
            $cart->set_cart_contents($lines);
            WC()->session?->set('cart', $cart->get_cart_for_session());
        }
    }

    /**
     * The shopper's choices ("attribute_<name>" => value, "any" attributes included) as the
     * translation's attribute names and values.
     *
     * @param array<string, string> $selection
     * @return array<string, string>
     */
    private function map_selection(array $selection, int $from_parent_id, int $to_parent_id): array
    {
        $from = wc_get_product($from_parent_id);
        $to = wc_get_product($to_parent_id);
        if (!$from instanceof \WC_Product || !$to instanceof \WC_Product) {
            return $selection;
        }

        $values = [];
        foreach ($selection as $name => $value) {
            $values[substr((string) $name, strlen('attribute_'))] = (string) $value;
        }

        $out = [];
        foreach (ProductMirror::map_variation_attributes($values, $from, $to) as $name => $value) {
            $out['attribute_' . $name] = $value;
        }
        return $out;
    }
}
