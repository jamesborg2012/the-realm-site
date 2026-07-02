<?php

if (!defined('ABSPATH')) {
    die('ACCESS_DENIED');
}

/**
 * Adds a "Membership" tab to WooCommerce My Account (item 23, part 3).
 *
 * - Active members see their membership details.
 * - Pending members (review / new) see an "under review" message.
 * - Non-members can apply to become a member, or link an existing membership number — both set
 *   the status to `review` for an admin to finalise in the Realm Members Manager dashboard.
 */
class RMM_Account_Handler extends Realm_Members_Manager_Core
{
    const ENDPOINT = 'membership';

    public function __construct()
    {
        add_action('init', [$this, 'add_endpoint']);
        add_filter('woocommerce_account_menu_items', [$this, 'add_menu_item']);
        add_action('woocommerce_account_' . self::ENDPOINT . '_endpoint', [$this, 'render_endpoint']);
        add_filter('woocommerce_endpoint_' . self::ENDPOINT . '_title', [$this, 'endpoint_title']);
        add_action('template_redirect', [$this, 'handle_form_submission']);
    }

    /**
     * Registers the My Account rewrite endpoint, flushing rules once so the URL resolves.
     */
    public function add_endpoint()
    {
        add_rewrite_endpoint(self::ENDPOINT, EP_ROOT | EP_PAGES);

        if (get_option('rmm_membership_endpoint_flushed') !== '1') {
            flush_rewrite_rules(false);
            update_option('rmm_membership_endpoint_flushed', '1');
        }
    }

    /**
     * Inserts the "Membership" item into the My Account menu, just before Logout.
     */
    public function add_menu_item($items)
    {
        $new_items = [];

        foreach ($items as $key => $label) {
            if ($key === 'customer-logout') {
                $new_items[self::ENDPOINT] = __('Membership', 'realm-members-manager');
            }
            $new_items[$key] = $label;
        }

        if (!isset($new_items[self::ENDPOINT])) {
            $new_items[self::ENDPOINT] = __('Membership', 'realm-members-manager');
        }

        return $new_items;
    }

    public function endpoint_title($title)
    {
        return __('Membership', 'realm-members-manager');
    }

    /**
     * Renders the Membership tab content.
     */
    public function render_endpoint()
    {
        $user_id = get_current_user_id();

        $status = get_user_meta($user_id, 'rmm_membership_status', true);
        $number = get_user_meta($user_id, 'rmm_membership_number', true);
        $expire = get_user_meta($user_id, 'rmm_membership_expire', true);

        $is_active  = ($status === 'active') && !empty($expire) && strtotime($expire) >= strtotime('today');
        $is_expired = ($status === 'active') && !empty($expire) && strtotime($expire) < strtotime('today');
        $is_pending = in_array($status, ['review', 'new'], true);

        [$price, $expiry_display] = $this->membership_offer();

        echo $this->render_template('public/partials/account/membership', [
            'is_active'       => $is_active,
            'is_expired'      => $is_expired,
            'is_pending'      => $is_pending,
            'status'          => $status,
            'number'          => $number,
            'expire'          => $expire,
            'price'           => $price,
            'expiry_display'  => $expiry_display,
            'store_discount'  => (float) get_option('rmm_member_store_discount', 18),
            'online_discount' => (float) get_option('rmm_member_online_only_discount', 8),
            'notice'          => isset($_GET['rmm_membership']) ? sanitize_key(wp_unslash($_GET['rmm_membership'])) : '',
        ]);
    }

    /**
     * Handles the "become a member" / "link existing number" form posts (PRG pattern).
     */
    public function handle_form_submission()
    {
        if (!is_user_logged_in() || empty($_POST['rmm_membership_action'])) {
            return;
        }

        if (!isset($_POST['rmm_membership_nonce']) || !wp_verify_nonce($_POST['rmm_membership_nonce'], 'rmm_membership_action')) {
            return;
        }

        $user_id = get_current_user_id();
        $status  = get_user_meta($user_id, 'rmm_membership_status', true);
        $expire  = get_user_meta($user_id, 'rmm_membership_expire', true);
        $action  = sanitize_key(wp_unslash($_POST['rmm_membership_action']));

        // Active members have nothing to apply for.
        if ($status === 'active' && !empty($expire) && strtotime($expire) >= strtotime('today')) {
            $this->redirect_membership('already');
        }

        if ($action === 'apply') {
            update_user_meta($user_id, 'rmm_membership_status', 'review');
            $this->redirect_membership('applied');
        }

        if ($action === 'link_number') {
            $number = isset($_POST['rmm_membership_number']) ? sanitize_text_field(wp_unslash($_POST['rmm_membership_number'])) : '';

            if ($number === '') {
                $this->redirect_membership('number_missing');
            }

            $existing = get_users([
                'meta_key'   => 'rmm_membership_number',
                'meta_value' => $number,
                'exclude'    => [$user_id],
                'number'     => 1,
                'fields'     => 'ID',
            ]);

            if (!empty($existing)) {
                $this->redirect_membership('number_taken');
            }

            // Store the claimed number (both keys, kept in sync with the theme) and flag for review.
            update_user_meta($user_id, 'rmm_membership_number', $number);
            update_user_meta($user_id, 'realm_membership_number', $number);
            update_user_meta($user_id, 'rmm_membership_status', 'review');
            $this->redirect_membership('linked');
        }
    }

    private function redirect_membership($code)
    {
        wp_safe_redirect(add_query_arg('rmm_membership', $code, wc_get_account_endpoint_url(self::ENDPOINT)));
        exit;
    }

    /**
     * The current membership offer (price + expiry), mirroring the Account Creation block: the tier
     * is the months remaining until year-end rounded to the nearest 3 (falling back to 12 months).
     */
    private function membership_offer(): array
    {
        $current_month         = (int) date('n');
        $months_until_december = 12 - $current_month + 1;
        $rounded               = (int) round($months_until_december / 3) * 3;

        if ($rounded <= 2) {
            $rounded = 12;
        }

        $price = get_option("rmm_{$rounded}_months_membership", '');

        $year = (int) date('Y');
        if ($rounded === 12) {
            $year++;
        }

        $expiry_display = date_i18n('F j, Y', strtotime("$year-12-31"));

        return [$price, $expiry_display];
    }
}
