<?php
/**
 * Plugin Name:       Valolink WooCommerce
 * Description:       Modular WooCommerce toolkit (GeneratePress product cards, product carousel, and more). Each feature is a toggleable module with zero footprint when disabled. Replaces Valolink GP Woo.
 * Version:           0.3.0
 * Requires at least: 6.5
 * Requires PHP:      8.2
 * Author:            Valolink
 * Text Domain:       valolink-woocommerce
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

define('VALOLINK_WOO_VERSION', '0.3.0');
define('VALOLINK_WOO_FILE', __FILE__);
define('VALOLINK_WOO_DIR', plugin_dir_path(__FILE__));
define('VALOLINK_WOO_URL', plugin_dir_url(__FILE__));
define('VALOLINK_WOO_BASENAME', plugin_basename(__FILE__));

// GitHub repo for wp-admin updates, as 'owner/repo'. Set to 'OWNER/REPO' to disable the updater.
define('VALOLINK_WOO_GITHUB_REPO', 'valolink/valolink-woocommerce');

require_once VALOLINK_WOO_DIR . 'src/Autoloader.php';
\Valolink\Woo\Autoloader::register();

register_activation_hook(__FILE__, [\Valolink\Woo\Plugin::class, 'on_activate']);

// Boot on init (not plugins_loaded): all module hooks are init-or-later, and booting at
// init avoids the WP 6.7+ "textdomain loaded too early" notice from translated module labels.
// Priority 5 keeps us ahead of GenerateBlocks' condition registration at init:10.
add_action('init', [\Valolink\Woo\Plugin::class, 'boot'], 5);
add_action('plugins_loaded', [\Valolink\Woo\Plugin::class, 'early'], 20);
