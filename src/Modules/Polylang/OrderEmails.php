<?php

declare(strict_types=1);

namespace Valolink\Woo\Modules\Polylang;

/**
 * Orders remember the language they were placed in, and their emails use it: the customer's
 * in the order's language, the shop's own (new order, cancelled, failed) in the default one.
 * That holds also when the email goes out later from wp-admin, where the request has no
 * language of its own.
 *
 * Texts typed into WooCommerce → Settings → Emails (subjects, headings, additional content,
 * footer) are registered as Polylang strings, translated under Languages → Translations.
 */
final class OrderEmails
{
    public const ORDER_META = '_valolink_woo_language';
    private const STRING_GROUP = 'WooCommerce emails';
    private const TRANSLATED_OPTIONS = ['subject', 'heading', 'additional_content', 'subject_full', 'subject_partial', 'heading_full', 'heading_partial', 'subject_paid', 'heading_paid'];

    /** The order whose emails are being sent right now. */
    private static ?\WC_Order $order = null;

    /** @var array<string, array|null> Language switches per email id, undone when it is sent. */
    private array $switched = [];

    public function register(): void
    {
        add_action('woocommerce_checkout_create_order', [$this, 'remember_language']);
        add_action('woocommerce_store_api_checkout_update_order_meta', [$this, 'remember_language']);

        add_filter('woocommerce_email_actions', [$this, 'watch_email_actions'], PHP_INT_MAX);
        add_action('woocommerce_before_resend_order_emails', [$this, 'resend_started']);
        add_action('woocommerce_after_resend_order_emails', [$this, 'forget_order']);
        add_filter('woocommerce_allow_switching_email_locale', [$this, 'switch_for_email'], 10, 2);
        add_filter('woocommerce_allow_restoring_email_locale', [$this, 'restore_after_email'], 10, 2);

        add_filter('woocommerce_email_get_option', [$this, 'translate_option'], 10, 4);
        add_filter('woocommerce_email_footer_text', [$this, 'translate_footer'], 5);
        if (is_admin()) {
            add_action('admin_init', [$this, 'register_strings']);
        }
    }

    public function remember_language($order): void
    {
        $lang = Pll::current();
        if ($order instanceof \WC_Order && $lang !== '') {
            $order->update_meta_data(self::ORDER_META, $lang);
        }
    }

    public static function order_language(\WC_Order $order): string
    {
        $lang = (string) $order->get_meta(self::ORDER_META);
        return in_array($lang, Pll::languages(), true) ? $lang : Pll::default_language();
    }

    /**
     * WooCommerce sends transactional emails from "<action>_notification" hooks whose first
     * argument is the order (or its id). Note the order before the emails' own handlers run.
     *
     * @param string[] $actions
     */
    public function watch_email_actions($actions)
    {
        foreach ((array) $actions as $action) {
            add_action($action . '_notification', [$this, 'notification_started'], 1, 10);
            add_action($action . '_notification', [$this, 'forget_order'], PHP_INT_MAX, 0);
        }
        return $actions;
    }

    public function notification_started(...$args): void
    {
        $first = $args[0] ?? null;
        if (is_array($first) && isset($first['order_id'])) {
            $first = $first['order_id'];
        }
        $order = $first instanceof \WC_Order ? $first : (is_numeric($first) ? wc_get_order((int) $first) : null);
        self::$order = $order instanceof \WC_Order ? $order : null;
    }

    public function resend_started($order): void
    {
        self::$order = $order instanceof \WC_Order ? $order : null;
    }

    public function forget_order(): void
    {
        self::$order = null;
    }

    /** WooCommerce asks before it switches an email to the site locale; switch to ours instead. */
    public function switch_for_email($allow, $email)
    {
        // Only orders noted by a notification or resend hook: $email->object is not set yet here,
        // and WooCommerce reuses email objects, so it may still hold the previous order.
        $order = self::$order;
        if (!$email instanceof \WC_Email || $order === null) {
            return $allow;
        }

        $lang = $email->is_customer_email() ? self::order_language($order) : Pll::default_language();
        $this->switched[$email->id] = Pll::switch_to($lang);
        $email->init_settings();
        return false;
    }

    public function restore_after_email($allow, $email)
    {
        if (!$email instanceof \WC_Email || !array_key_exists($email->id, $this->switched)) {
            return $allow;
        }
        Pll::restore($this->switched[$email->id]);
        unset($this->switched[$email->id]);
        // WC_Settings_API::get_option() stores the default subject and heading in the email's
        // settings the first time it reads them, in whatever language was active then. Reload,
        // or the next order's email of this type reuses this one's language.
        $email->init_settings();
        return false;
    }

    public function translate_option($value, $email, $raw, $key)
    {
        if (!is_string($value) || $value === '' || !in_array($key, self::TRANSLATED_OPTIONS, true)) {
            return $value;
        }
        return pll__($value);
    }

    public function translate_footer($text)
    {
        return is_string($text) && $text !== '' ? pll__($text) : $text;
    }

    /** Only on Polylang's strings screen: loading every email on each admin page is not free. */
    public function register_strings(): void
    {
        if (($_GET['page'] ?? '') !== 'mlang_strings' || !function_exists('pll_register_string')) {
            return;
        }
        foreach (WC()->mailer()->get_emails() as $email) {
            foreach (self::TRANSLATED_OPTIONS as $key) {
                $value = $email->settings[$key] ?? '';
                if (is_string($value) && $value !== '') {
                    pll_register_string($email->id . ' ' . $key, $value, self::STRING_GROUP, $key === 'additional_content');
                }
            }
        }
        $footer = (string) get_option('woocommerce_email_footer_text', '');
        if ($footer !== '') {
            pll_register_string('footer_text', $footer, self::STRING_GROUP, true);
        }
    }
}
