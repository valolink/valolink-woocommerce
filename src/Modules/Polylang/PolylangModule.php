<?php

declare(strict_types=1);

namespace Valolink\Woo\Modules\Polylang;

use Valolink\Woo\Context;
use Valolink\Woo\EarlyHooks;
use Valolink\Woo\Module;
use Valolink\Woo\Settings;

/**
 * WooCommerce support for the free Polylang.
 *
 * Products, categories, tags, brands and attribute terms become translatable. A product's
 * translations share everything but their words (ProductMirror lists what is shared); stock
 * moved by orders is applied to every language at once (StockSync); the storefront, cart and
 * emails follow the shopper's language (Storefront, CartLanguage, OrderEmails).
 *
 * Inert until its setup has run (Setup): see the admin page or `wp valolink-woo polylang`.
 */
final class PolylangModule implements Module, EarlyHooks
{
    public const MODULE_ID = 'polylang';

    public function __construct(private readonly Settings $settings) {}

    public static function early(Settings $settings): void
    {
        if (function_exists('pll_languages_list') && !Pll::wc_addon_active()
            && $settings->get_module_setting(self::MODULE_ID, 'setup_done', false)) {
            TranslatableTypes::enable();
        }
    }

    public function should_load(Context $context): bool
    {
        // Every context: orders, imports, cron and WP-CLI change products and stock too.
        return Pll::available() && class_exists('WooCommerce');
    }

    public function register(): void
    {
        if (is_admin()) {
            (new AdminPage($this->settings))->register();
        }
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('valolink-woo polylang', new Cli($this->settings));
        }

        // The admin page says why nothing runs; switching over is: deactivate the add-on, run setup.
        if (Pll::wc_addon_active() || !(new Setup($this->settings))->is_done()) {
            return;
        }

        if ($this->settings->get_module_setting(self::MODULE_ID, 'flush_rewrite', false)) {
            // Polylang adds the language prefix to the product rules once products are
            // translatable; that happens on the first request after setup.
            add_action('wp_loaded', function (): void {
                flush_rewrite_rules(false);
                $this->settings->set_module_setting(self::MODULE_ID, 'flush_rewrite', false);
            });
        }

        // Accesslink's guide tells agents to translate a product's words only:
        // everything else follows from the original here.
        add_filter('valolink_accesslink_product_translations_synced', '__return_true');

        (new UniqueIds())->register();
        (new ProductSync())->register();
        (new StockSync())->register();
        (new Storefront())->register();
        (new CartLanguage())->register();
        (new OrderEmails())->register();
    }

    public function uninstall(): void
    {
        delete_post_meta_by_key(Families::SYNCED_META);
        delete_post_meta_by_key(Families::VARIATION_KEY);
        $this->settings->forget_module(self::MODULE_ID);
    }
}
