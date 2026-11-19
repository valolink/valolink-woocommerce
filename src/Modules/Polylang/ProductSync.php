<?php

declare(strict_types=1);

namespace Valolink\Woo\Modules\Polylang;

/**
 * Decides when ProductMirror runs. Saves and links are collected during the request and handled
 * on shutdown, when whoever saved (wp-admin, REST, Accesslink, an import) has finished writing:
 * Accesslink, for one, links a new translation first and copies the source's postmeta after.
 *
 * - A product saved: its shared data goes to the other members of its group.
 * - A variation saved or added: it goes to the matching variation in each translation.
 * - A variation deleted: its matches go too.
 * - A translation linked for the first time (or saved before it ever was): it copies everything
 *   from the group's source rather than the other way round, so a fresh, half-empty
 *   translation never overwrites the shop's real data.
 */
final class ProductSync
{
    /** @var array<int, true> */
    private static array $products = [];
    /** @var array<int, true> */
    private static array $variations = [];
    /** @var array<int, true> */
    private static array $linked = [];
    /** @var array<int, array{parent: int, key: int}> */
    private static array $deleted = [];

    /** While true, saves are the module's own and are not queued again. */
    private static bool $mirroring = false;
    private static bool $scheduled = false;

    public function register(): void
    {
        add_action('woocommerce_new_product', [$this, 'product_saved'], 20);
        add_action('woocommerce_update_product', [$this, 'product_saved'], 20);
        add_action('woocommerce_new_product_variation', [$this, 'variation_saved'], 20);
        add_action('woocommerce_update_product_variation', [$this, 'variation_saved'], 20);
        add_action('woocommerce_before_delete_product_variation', [$this, 'variation_deleted']);
        add_action('set_object_terms', [$this, 'terms_set'], 10, 4);
        add_filter('woocommerce_duplicate_product_exclude_meta', [$this, 'exclude_from_duplicate']);
    }

    /** Run $fn without queueing the saves it makes. */
    public static function quietly(callable $fn): mixed
    {
        $was = self::$mirroring;
        self::$mirroring = true;
        try {
            return $fn();
        } finally {
            self::$mirroring = $was;
        }
    }

    public function product_saved($product_id): void
    {
        if (!self::$mirroring && pll_is_translated_post_type('product')) {
            self::$products[(int) $product_id] = true;
            $this->schedule();
        }
    }

    public function variation_saved($variation_id): void
    {
        if (!self::$mirroring && pll_is_translated_post_type('product')) {
            self::$variations[(int) $variation_id] = true;
            $this->schedule();
        }
    }

    public function variation_deleted($variation_id): void
    {
        if (self::$mirroring || !pll_is_translated_post_type('product')) {
            return;
        }
        $variation_id = (int) $variation_id;
        unset(self::$variations[$variation_id]);
        self::$deleted[] = [
            'parent' => (int) wp_get_post_parent_id($variation_id),
            'key'    => Families::variation_key($variation_id),
        ];
        $this->schedule();
    }

    /** Polylang links translations by setting their shared post_translations term. */
    public function terms_set($object_id, $terms, $tt_ids, $taxonomy): void
    {
        if ($taxonomy === 'post_translations' && !self::$mirroring && get_post_type((int) $object_id) === 'product') {
            self::$linked[(int) $object_id] = true;
            $this->schedule();
        }
    }

    /** @param string[] $meta_keys */
    public function exclude_from_duplicate(array $meta_keys): array
    {
        $meta_keys[] = Families::SYNCED_META;
        $meta_keys[] = Families::VARIATION_KEY;
        return $meta_keys;
    }

    private function schedule(): void
    {
        if (!self::$scheduled) {
            self::$scheduled = true;
            add_action('shutdown', [self::class, 'process'], 5);
        }
    }

    public static function process(): void
    {
        self::quietly(static function (): void {
            $stock_touched = StockSync::touched();

            foreach (array_keys(self::$linked + self::$products) as $product_id) {
                self::attempt(static fn () => self::product($product_id, !isset($stock_touched[$product_id])));
            }
            foreach (array_keys(self::$variations) as $variation_id) {
                self::attempt(static fn () => self::variation($variation_id, !isset($stock_touched[$variation_id])));
            }
            foreach (self::$deleted as $deleted) {
                self::attempt(static fn () => self::deleted($deleted['parent'], $deleted['key']));
            }
        });

        self::$linked = self::$products = self::$variations = self::$deleted = [];
        self::$scheduled = false;
    }

    /** Bring a whole group in line, from its source. For setup, the CLI and the admin page. */
    public static function sync_group(int $product_id): void
    {
        self::quietly(static function () use ($product_id): void {
            $group = Pll::group($product_id);
            $source = Pll::source($group);
            foreach ($group as $member) {
                if ($member !== $source) {
                    ProductMirror::full($source, $member);
                }
            }
        });
    }

    private static function product(int $product_id, bool $with_stock): void
    {
        $group = Pll::group($product_id);
        if (count($group) < 2 || get_post_type($product_id) !== 'product') {
            return;
        }
        $source = Pll::source($group);

        // New members copy everything from the source first.
        foreach ($group as $member) {
            if (!Families::is_synced($member, $group)) {
                ProductMirror::full($source, $member);
            }
        }
        if (isset(self::$linked[$product_id]) && !isset(self::$products[$product_id])) {
            return;
        }

        $from = wc_get_product($product_id);
        if (!$from instanceof \WC_Product) {
            return;
        }
        foreach ($group as $member) {
            if ($member !== $product_id) {
                ProductMirror::product($from, $member, $with_stock);
            }
        }

        // Attribute names or values may have changed: variation values follow the source's.
        $source_product = wc_get_product($source);
        if ($source_product instanceof \WC_Product) {
            foreach ($group as $member) {
                $product = wc_get_product($member);
                if ($member !== $source && $product instanceof \WC_Product) {
                    ProductMirror::remap_variation_attributes($source_product, $product);
                }
            }
        }
    }

    private static function variation(int $variation_id, bool $with_stock): void
    {
        $parent_id = (int) wp_get_post_parent_id($variation_id);
        $group = Pll::group($parent_id);
        $from_parent = wc_get_product($parent_id);
        if (count($group) < 2 || !$from_parent instanceof \WC_Product || get_post_type($variation_id) !== 'product_variation') {
            return;
        }

        foreach ($group as $member) {
            if ($member === $parent_id || !Families::is_synced($member, $group)) {
                continue;
            }
            $to_parent = wc_get_product($member);
            if (!$to_parent instanceof \WC_Product || !$to_parent->is_type('variable')) {
                continue;
            }
            ProductMirror::variation($variation_id, $from_parent, $to_parent, Families::variation_in($variation_id, $member), $with_stock);
            \WC_Product_Variable::sync($member);
            wc_delete_product_transients($member);
        }
    }

    private static function deleted(int $parent_id, int $key): void
    {
        // A variation removed along with its whole product: the translations keep theirs.
        $status = get_post_status($parent_id);
        if ($status === false || $status === 'trash') {
            return;
        }

        foreach (Pll::group($parent_id) as $member) {
            $match = Families::variations_by_key($member)[$key] ?? 0;
            if ($member !== $parent_id && $match > 0) {
                ProductMirror::delete_variation($match);
                \WC_Product_Variable::sync($member);
                wc_delete_product_transients($member);
            }
        }
    }

    private static function attempt(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            error_log('[valolink-woocommerce] polylang sync failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        }
    }
}
