<?php

declare(strict_types=1);

namespace Valolink\Woo;

use Valolink\Woo\Admin\SettingsPage;
use Valolink\Woo\Modules\ProductCard\ProductCardModule;
use Valolink\Woo\Modules\ProductCarousel\ProductCarouselModule;

final class Plugin
{
    /** The plugin this one replaces. Its module code declares the same global functions. */
    public const LEGACY_BASENAME = 'valolink-gp-woo/valolink-gp-woo.php';
    public const LEGACY_OPTION_KEY = 'valolink_gp_woo_settings';

    public static function boot(): void
    {
        // Both active would redeclare the gpwc_* functions (fatal), so stand down until the
        // old one is deactivated. Activating this plugin normally deactivates it (on_activate).
        if (defined('VALOLINK_GP_WOO_VERSION')) {
            add_action('admin_notices', [self::class, 'legacy_active_notice']);
            return;
        }

        $settings = new Settings();
        $registry = new Registry();
        self::register_modules($registry, $settings);

        // Self-updater (core infrastructure; inert unless a real repo is configured).
        if (VALOLINK_WOO_GITHUB_REPO !== 'OWNER/REPO') {
            (new Updater(VALOLINK_WOO_FILE, VALOLINK_WOO_GITHUB_REPO, VALOLINK_WOO_VERSION))->register();
        }

        if (is_admin()) {
            (new SettingsPage($settings, $registry))->register();
        }

        (new Loader($settings, $registry, Context::detect()))->load();
    }

    public static function register_modules(Registry $registry, Settings $settings): void
    {
        $registry->register(new ModuleManifest(
            id: ProductCardModule::MODULE_ID,
            label: __('Product Card', 'valolink-woocommerce'),
            description: __('Render a GeneratePress Element as the WooCommerce product-loop card, with WooCommerce dynamic tags, a block condition, and an add-to-cart shortcode for GenerateBlocks. Flag an Element on its edit screen to use it as the card.', 'valolink-woocommerce'),
            class: ProductCardModule::class,
            default_enabled: true,
            constructor_args: [$settings],
        ));

        $registry->register(new ModuleManifest(
            id: ProductCarouselModule::MODULE_ID,
            label: __('Product Carousel', 'valolink-woocommerce'),
            description: __('A scrollable product-listing block (newest, on sale, category, …) that renders each product through the shared product-card template, so custom listings match the shop loop.', 'valolink-woocommerce'),
            class: ProductCarouselModule::class,
            default_enabled: true,
            constructor_args: [$settings],
        ));
    }

    public static function on_activate(): void
    {
        if (get_option(Settings::OPTION_KEY) === false) {
            // Carry over the module toggles from Valolink GP Woo. Its option stays in place
            // (harmless) in case the old plugin is reactivated.
            $legacy = get_option(self::LEGACY_OPTION_KEY);
            add_option(Settings::OPTION_KEY, is_array($legacy) ? $legacy : ['modules' => []], '', false);
        }

        // Silent: the old plugin has no deactivation hook, and its uninstall must not run
        // (it would delete the _gpwc_product_card flags this plugin still uses).
        if (function_exists('is_plugin_active') && is_plugin_active(self::LEGACY_BASENAME)) {
            deactivate_plugins(self::LEGACY_BASENAME, true);
        }
    }

    public static function legacy_active_notice(): void
    {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        echo '<div class="notice notice-error"><p>'
            . esc_html__('Valolink WooCommerce is inactive while Valolink GP Woo is active. Deactivate Valolink GP Woo (do not delete it from wp-admin: its uninstall removes the product-card flags), then remove its folder.', 'valolink-woocommerce')
            . '</p></div>';
    }

    public static function uninstall(): void
    {
        $settings = new Settings();
        $registry = new Registry();
        self::register_modules($registry, $settings);

        foreach ($registry->all() as $manifest) {
            if (class_exists($manifest->class)) {
                $module = new ($manifest->class)(...$manifest->constructor_args);
                if ($module instanceof Module) {
                    $module->uninstall();
                }
            }
        }

        delete_option(Settings::OPTION_KEY);
    }
}
