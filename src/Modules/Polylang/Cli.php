<?php

declare(strict_types=1);

namespace Valolink\Woo\Modules\Polylang;

use Valolink\Woo\Settings;

/**
 * WooCommerce + Polylang housekeeping.
 *
 * ## EXAMPLES
 *
 *     wp valolink-woo polylang status
 *     wp valolink-woo polylang setup
 *     wp valolink-woo polylang sync 123 456
 *     wp valolink-woo polylang sync --all
 */
final class Cli
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * Products per language, untranslated originals, and WooCommerce's pages per language.
     *
     * [--format=<format>]
     * : json or yaml. Default: yaml.
     */
    public function status(array $args, array $assoc): void
    {
        $status = (new Setup($this->settings))->status();
        \WP_CLI::print_value($status, ['format' => $assoc['format'] ?? 'yaml']);
    }

    /**
     * Give products and terms without a language the default one, make products translatable
     * and add translations of WooCommerce's pages. Safe to run again.
     */
    public function setup(array $args, array $assoc): void
    {
        $result = (new Setup($this->settings))->run();
        \WP_CLI::success(sprintf(
            '%d products and %d terms set to "%s", %d page translations added. Rewrite rules are flushed on the next request.',
            $result['products'],
            $result['terms'],
            $result['language'],
            $result['pages'],
        ));
    }

    /**
     * Copy the shared data of products (and their variations) to their translations.
     *
     * [<id>...]
     * : Product ids, any language.
     *
     * [--all]
     * : Every product that has a translation.
     */
    public function sync(array $args, array $assoc): void
    {
        if (!(new Setup($this->settings))->is_done()) {
            \WP_CLI::error('Run setup first.');
        }
        if (!empty($assoc['all'])) {
            \WP_CLI::success(sprintf('Synced %d translated products.', self::sync_all()));
            return;
        }
        if ($args === []) {
            \WP_CLI::error('Give product ids, or --all.');
        }
        foreach ($args as $id) {
            ProductSync::sync_group((int) $id);
            \WP_CLI::log(sprintf('%d: %s', (int) $id, wp_json_encode(Pll::group((int) $id))));
        }
        \WP_CLI::success('Done.');
    }

    /** @return int translation groups synced */
    public static function sync_all(): int
    {
        $sources = get_posts([
            'post_type'        => 'product',
            'post_status'      => ['publish', 'draft', 'pending', 'private'],
            'lang'             => Pll::default_language(),
            'posts_per_page'   => -1,
            'fields'           => 'ids',
            'suppress_filters' => false,
        ]);

        $synced = 0;
        foreach ($sources as $id) {
            if (count(Pll::group((int) $id)) > 1) {
                ProductSync::sync_group((int) $id);
                $synced++;
            }
        }
        return $synced;
    }
}
