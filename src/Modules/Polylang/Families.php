<?php

declare(strict_types=1);

namespace Valolink\Woo\Modules\Polylang;

/**
 * Which products and variations are "the same item" in different languages.
 *
 * Products are linked by Polylang's translation groups. Variations are not Polylang objects at
 * all: each translation of a variable product has its own variations, and the matching ones
 * share a key in VARIATION_KEY (the id of the variation they were copied from).
 */
final class Families
{
    public const VARIATION_KEY = '_valolink_woo_variation_key';

    /** Set on a translation once it has copied the shared data from its source. */
    public const SYNCED_META = '_valolink_woo_synced';

    public static function variation_key(int $variation_id): int
    {
        $key = (int) get_post_meta($variation_id, self::VARIATION_KEY, true);
        return $key > 0 ? $key : $variation_id;
    }

    /** Give a variation its key if it has none yet (its own id), and return it. */
    public static function ensure_variation_key(int $variation_id): int
    {
        $key = (int) get_post_meta($variation_id, self::VARIATION_KEY, true);
        if ($key > 0) {
            return $key;
        }
        update_post_meta($variation_id, self::VARIATION_KEY, $variation_id);
        return $variation_id;
    }

    /**
     * Variations of $parent_id, keyed by variation key. Variations without a key count as their
     * own (they were made there and have not been copied anywhere yet).
     *
     * @return array<int, int> key => variation id
     */
    public static function variations_by_key(int $parent_id): array
    {
        $ids = get_posts([
            'post_type'      => 'product_variation',
            'post_parent'    => $parent_id,
            'post_status'    => ['publish', 'private'],
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'orderby'        => ['menu_order' => 'ASC', 'ID' => 'ASC'],
        ]);

        $out = [];
        foreach ($ids as $id) {
            $out[self::variation_key((int) $id)] = (int) $id;
        }
        return $out;
    }

    /** The variation of $parent_id that matches $variation_id, or 0. */
    public static function variation_in(int $variation_id, int $parent_id): int
    {
        return self::variations_by_key($parent_id)[self::variation_key($variation_id)] ?? 0;
    }

    /**
     * The same item in the other languages: the other members of a product's translation group,
     * or a variation's matches under those members.
     *
     * @return int[]
     */
    public static function counterparts(int $id): array
    {
        $type = get_post_type($id);
        if ($type === 'product') {
            return array_values(array_diff(Pll::group($id), [$id]));
        }
        if ($type !== 'product_variation') {
            return [];
        }

        $parent = (int) wp_get_post_parent_id($id);
        $out = [];
        foreach (Pll::group($parent) as $member) {
            if ($member === $parent) {
                continue;
            }
            $match = self::variation_in($id, $member);
            if ($match > 0) {
                $out[] = $match;
            }
        }
        return $out;
    }

    /** True for the default-language member, and for translations that have copied from it. */
    public static function is_synced(int $product_id, array $group): bool
    {
        return Pll::source($group) === $product_id || get_post_meta($product_id, self::SYNCED_META, true) === '1';
    }
}
