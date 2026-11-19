<?php

declare(strict_types=1);

namespace Valolink\Woo\Admin;

use Valolink\Woo\Registry;
use Valolink\Woo\Settings;

/**
 * Single admin screen with a module-toggle table. Mirrors the Valolink Plugin settings UI.
 */
final class SettingsPage
{
    public const MENU_SLUG    = 'valolink-woocommerce';
    public const CAPABILITY   = 'manage_options';
    public const NONCE_ACTION = 'valolink_woo_save_settings';
    public const SAVE_ACTION  = 'valolink_woo_save_settings';

    public function __construct(
        private readonly Settings $settings,
        private readonly Registry $registry,
    ) {}

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_menu_page']);
        add_action('admin_post_' . self::SAVE_ACTION, [$this, 'handle_save']);
    }

    public function add_menu_page(): void
    {
        add_menu_page(
            __('Valolink WooCommerce', 'valolink-woocommerce'),
            __('Valolink WooCommerce', 'valolink-woocommerce'),
            self::CAPABILITY,
            self::MENU_SLUG,
            [$this, 'render'],
            'dashicons-cart',
            82,
        );
    }

    public function render(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }

        $manifests = $this->registry->all();
        $updated   = isset($_GET['updated']) && $_GET['updated'] === '1';
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Valolink WooCommerce', 'valolink-woocommerce'); ?></h1>
            <p class="description">
                <?php echo esc_html__('WooCommerce / GeneratePress functionality, split into modules. Disabled modules load no code.', 'valolink-woocommerce'); ?>
            </p>

            <?php if ($updated) : ?>
                <div class="notice notice-success is-dismissible"><p>
                    <?php echo esc_html__('Settings saved.', 'valolink-woocommerce'); ?>
                </p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::SAVE_ACTION); ?>">
                <?php wp_nonce_field(self::NONCE_ACTION); ?>

                <h2><?php echo esc_html__('Modules', 'valolink-woocommerce'); ?></h2>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th style="width: 80px;"><?php echo esc_html__('Enabled', 'valolink-woocommerce'); ?></th>
                            <th style="width: 200px;"><?php echo esc_html__('Module', 'valolink-woocommerce'); ?></th>
                            <th><?php echo esc_html__('Description', 'valolink-woocommerce'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($manifests as $manifest) : ?>
                            <tr>
                                <td>
                                    <input
                                        type="checkbox"
                                        name="valolink_woo_enabled[]"
                                        value="<?php echo esc_attr($manifest->id); ?>"
                                        <?php checked($this->settings->is_module_enabled($manifest->id, $manifest->default_enabled)); ?>
                                    >
                                </td>
                                <td><strong><?php echo esc_html($manifest->label); ?></strong></td>
                                <td><?php echo esc_html($manifest->description); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php submit_button(__('Save Changes', 'valolink-woocommerce')); ?>
            </form>
        </div>
        <?php
    }

    public function handle_save(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Insufficient permissions.', 'valolink-woocommerce'), '', ['response' => 403]);
        }

        check_admin_referer(self::NONCE_ACTION);

        $submitted = isset($_POST['valolink_woo_enabled']) && is_array($_POST['valolink_woo_enabled'])
            ? array_map('sanitize_key', wp_unslash($_POST['valolink_woo_enabled']))
            : [];

        foreach ($this->registry->all() as $manifest) {
            $this->settings->set_module_enabled(
                $manifest->id,
                in_array($manifest->id, $submitted, true),
            );
        }

        wp_safe_redirect(add_query_arg(
            ['page' => self::MENU_SLUG, 'updated' => '1'],
            admin_url('admin.php'),
        ));
        exit;
    }
}
