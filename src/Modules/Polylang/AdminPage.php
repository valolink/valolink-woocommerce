<?php

declare(strict_types=1);

namespace Valolink\Woo\Modules\Polylang;

use Valolink\Woo\Admin\SettingsPage;
use Valolink\Woo\Settings;

/**
 * Valolink WooCommerce → Polylang: status per language, the setup button, a "sync everything"
 * repair button, and a note on product screens about what the translations share.
 */
final class AdminPage
{
    private const SLUG = 'valolink-woocommerce-polylang';
    private const ACTION = 'valolink_woo_polylang';

    public function __construct(private readonly Settings $settings) {}

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_page'], 20);
        add_action('admin_post_' . self::ACTION, [$this, 'handle']);
        add_action('edit_form_top', [$this, 'product_note']);
    }

    public function add_page(): void
    {
        add_submenu_page(
            SettingsPage::MENU_SLUG,
            __('Polylang', 'valolink-woocommerce'),
            __('Polylang', 'valolink-woocommerce'),
            'manage_woocommerce',
            self::SLUG,
            [$this, 'render'],
        );
    }

    public function render(): void
    {
        $setup = new Setup($this->settings);
        $status = $setup->status();
        $notice = isset($_GET['done']) ? sanitize_text_field(wp_unslash($_GET['done'])) : '';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('WooCommerce and Polylang', 'valolink-woocommerce'); ?></h1>
            <?php if ($notice !== '') : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div>
            <?php endif; ?>

            <?php if (Pll::wc_addon_active()) : ?>
                <div class="notice notice-warning inline"><p><?php esc_html_e('Polylang for WooCommerce is active, so this module does nothing: both would translate the shop. To switch over, deactivate Polylang for WooCommerce and run setup here right after.', 'valolink-woocommerce'); ?></p></div>
                </div>
                <?php return; ?>
            <?php endif; ?>

            <?php if (!$status['setup_done']) : ?>
                <p><?php esc_html_e('Products are not translatable yet, and nothing in the shop has changed. Setup gives every product, category, tag and attribute term without a language the default language, makes products translatable, and adds translations of the shop, cart, checkout and my account pages.', 'valolink-woocommerce'); ?></p>
                <p><?php
                    printf(
                        /* translators: 1: number of products, 2: number of terms, 3: language code */
                        esc_html__('Waiting: %1$d products and %2$d terms without a language; they will be set to "%3$s".', 'valolink-woocommerce'),
                        (int) $status['untagged_products'],
                        (int) $status['untagged_terms'],
                        esc_html(Pll::default_language()),
                    );
                ?></p>
                <?php $this->button('setup', __('Run setup', 'valolink-woocommerce'), true); ?>
            <?php else : ?>
                <table class="widefat striped" style="max-width: 640px;">
                    <thead><tr>
                        <th><?php esc_html_e('Language', 'valolink-woocommerce'); ?></th>
                        <th><?php esc_html_e('Products', 'valolink-woocommerce'); ?></th>
                        <th><?php esc_html_e('Published', 'valolink-woocommerce'); ?></th>
                        <th><?php esc_html_e('Published originals not yet translated', 'valolink-woocommerce'); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($status['languages'] as $lang => $row) : ?>
                        <tr>
                            <td><?php echo esc_html($lang); ?></td>
                            <td><?php echo (int) $row['products']; ?></td>
                            <td><?php echo (int) $row['published']; ?></td>
                            <td><?php echo isset($row['untranslated']) ? (int) $row['untranslated'] : '—'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ($status['untagged_products'] > 0 || $status['untagged_terms'] > 0) : ?>
                    <p><?php
                        printf(
                            /* translators: 1: number of products, 2: number of terms */
                            esc_html__('%1$d products and %2$d terms have no language and are hidden from the shop. Running setup again gives them the default language.', 'valolink-woocommerce'),
                            (int) $status['untagged_products'],
                            (int) $status['untagged_terms'],
                        );
                    ?></p>
                    <?php $this->button('setup', __('Give them the default language', 'valolink-woocommerce'), false); ?>
                <?php endif; ?>
                <p><?php esc_html_e('Translations follow their product automatically. If something was changed while this module was off, this copies every product\'s shared data to its translations again (text is never touched).', 'valolink-woocommerce'); ?></p>
                <?php $this->button('sync', __('Sync all translations', 'valolink-woocommerce'), false); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private function button(string $task, string $label, bool $primary): void
    {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
            <input type="hidden" name="task" value="<?php echo esc_attr($task); ?>">
            <?php wp_nonce_field(self::ACTION . '_' . $task); ?>
            <?php submit_button($label, $primary ? 'primary' : 'secondary', 'submit', false); ?>
        </form>
        <?php
    }

    public function handle(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Insufficient permissions.', 'valolink-woocommerce'), '', ['response' => 403]);
        }
        $task = isset($_POST['task']) ? sanitize_key(wp_unslash($_POST['task'])) : '';
        check_admin_referer(self::ACTION . '_' . $task);

        if ($task === 'setup') {
            try {
                $result = (new Setup($this->settings))->run();
            } catch (\RuntimeException $e) {
                wp_die(esc_html($e->getMessage()), '', ['response' => 409, 'back_link' => true]);
            }
            $message = sprintf(
                /* translators: 1: products, 2: terms, 3: language, 4: pages */
                __('Setup done: %1$d products and %2$d terms set to "%3$s", %4$d page translations added.', 'valolink-woocommerce'),
                $result['products'],
                $result['terms'],
                $result['language'],
                $result['pages'],
            );
        } elseif ($task === 'sync') {
            if (Pll::wc_addon_active()) {
                wp_die(esc_html__('Polylang for WooCommerce is active: deactivate it first.', 'valolink-woocommerce'), '', ['response' => 409, 'back_link' => true]);
            }
            $groups = Cli::sync_all();
            /* translators: %d: number of products */
            $message = sprintf(__('Synced %d translated products.', 'valolink-woocommerce'), $groups);
        } else {
            wp_die('Unknown task.');
        }

        wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'done' => rawurlencode($message)], admin_url('admin.php')));
        exit;
    }

    /** On a translated product's edit screen: say which fields are shared. */
    public function product_note($post): void
    {
        if (!$post instanceof \WP_Post || $post->post_type !== 'product' || !(new Setup($this->settings))->is_done()) {
            return;
        }
        $group = Pll::group($post->ID);
        if (count($group) < 2) {
            return;
        }
        $others = implode(', ', array_keys(array_diff($group, [$post->ID])));
        echo '<div class="notice notice-info inline"><p>' . esc_html(sprintf(
            /* translators: %s: language codes */
            __('Shared with the %s version: prices, stock, SKU, images, categories, attributes and variations. Changes here go there too. Name, descriptions and the words of custom attributes are this language\'s own.', 'valolink-woocommerce'),
            $others,
        )) . '</p></div>';
    }
}
