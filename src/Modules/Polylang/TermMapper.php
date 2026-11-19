<?php

declare(strict_types=1);

namespace Valolink\Woo\Modules\Polylang;

/**
 * Maps product categories, tags, brands and attribute terms into another language.
 *
 * A term with no translation in the target language gets one, named like the original, so a
 * translated product never loses a category or an attribute value. Someone translates the
 * name later in Products → Categories (or Attributes); the link between the two stays.
 */
final class TermMapper
{
    /**
     * @param int[] $term_ids
     * @return int[]
     */
    public static function map_ids(array $term_ids, string $taxonomy, string $lang): array
    {
        $out = [];
        foreach ($term_ids as $id) {
            $out[] = self::map((int) $id, $taxonomy, $lang);
        }
        return array_values(array_unique(array_filter($out)));
    }

    public static function map(int $term_id, string $taxonomy, string $lang): int
    {
        if ($term_id <= 0 || !pll_is_translated_taxonomy($taxonomy)) {
            return $term_id;
        }

        $from = Pll::term_language($term_id);
        if ($from === '') {
            // Made after setup without a language (an import, a plugin): it was meant for the
            // default language, which is where setup put every earlier term too.
            $from = Pll::default_language();
            pll_set_term_language($term_id, $from);
        }
        if ($from === $lang) {
            return $term_id;
        }

        $existing = (int) pll_get_term($term_id, $lang);
        if ($existing > 0) {
            return $existing;
        }

        $term = get_term($term_id, $taxonomy);
        if (!$term instanceof \WP_Term) {
            return $term_id;
        }

        $parent = $term->parent > 0 ? self::map((int) $term->parent, $taxonomy, $lang) : 0;
        $group = array_map('intval', (array) pll_get_term_translations($term_id));

        $made = pll_insert_term($term->name, $taxonomy, $lang, [
            'parent'      => $parent,
            'description' => $term->description,
        ]);

        if (is_wp_error($made)) {
            // A term of that name already exists in the target language but belongs to no
            // group: adopt it rather than fail.
            $found = (int) ($made->get_error_data('term_exists') ?? 0);
            if ($found <= 0 || Pll::term_language($found) !== $lang || pll_get_term($found, $from)) {
                error_log(sprintf('[valolink-woocommerce] polylang: could not translate term %d (%s) into %s: %s', $term_id, $taxonomy, $lang, $made->get_error_message()));
                return $term_id;
            }
            $new_id = $found;
        } else {
            $new_id = (int) $made['term_id'];
            foreach (get_term_meta($term_id) as $key => $values) {
                if (str_starts_with((string) $key, 'product_count_')) {
                    continue;
                }
                foreach ((array) $values as $value) {
                    add_term_meta($new_id, (string) $key, maybe_unserialize($value));
                }
            }
        }

        pll_save_term_translations($group + [$from => $term_id, $lang => $new_id]);

        return $new_id;
    }
}
