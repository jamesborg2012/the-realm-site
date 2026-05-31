<?php

/**
 * Restructures the Storefront header into two bars:
 *
 *   Top bar  (.col-full, white)              → logo (left) + store contact info (right)
 *   Lower bar (.storefront-primary-navigation, black) → mega menu + product search + My Account + cart
 *
 * Storefront builds its header by firing `storefront_header` with hooked callbacks at set
 * priorities (see header.php). The top `.col-full` container spans priorities 0–41; the
 * primary-navigation wrapper opens at 42 and closes at 68. We keep the wrappers as-is and only
 * re-home individual components by priority:
 *
 *   - storefront_product_search is moved out of the top bar (40) into the lower bar (55).
 *   - Contact info is rendered in the top bar where the search used to sit (40).
 *   - A My Account link is rendered in the lower bar, just before the cart (58, cart is 60).
 */
class TRM_Header_Hooks extends TRM_Core
{
    public function __construct()
    {
        // Defer to after_setup_theme: the parent (Storefront) registers its storefront_header
        // callbacks (including storefront_product_search at priority 40) when its functions.php
        // loads, which runs AFTER this child theme's functions.php. Re-homing them at construct
        // time would therefore miss the parent's registration and leave a duplicate search. By
        // after_setup_theme both themes have fully loaded, so the remove_action below actually hits.
        add_action('after_setup_theme', [$this, 'register_hook_callbacks'], 11);
    }

    public function register_hook_callbacks()
    {
        // Move the product search from the top header row into the lower navigation bar.
        remove_action('storefront_header', 'storefront_product_search', 40);
        add_action('storefront_header', 'storefront_product_search', 55);

        // Store contact details take the search's old slot in the top (white) bar.
        add_action('storefront_header', [$this, 'render_header_contact_info'], 40);

        // My Account link in the lower (black) bar, immediately before the cart (priority 60).
        add_action('storefront_header', [$this, 'render_header_account_link'], 58);
    }

    /**
     * Render the store phone + email in the header's top bar as tel:/mailto: links.
     * Values come from the WC → Settings → General options written by
     * TRM_WC_Hooks::add_contact_info_general_setting(). Each item is omitted when its option is empty;
     * the whole block is skipped when both are empty.
     *
     * Hook: storefront_header (priority 40)
     *
     * @return void
     */
    public function render_header_contact_info()
    {
        $phone = get_option('trm_contact_phone', '');
        $email = get_option('trm_contact_email', '');

        $phone = is_string($phone) ? trim($phone) : '';
        $email = is_string($email) ? trim($email) : '';

        if ($phone === '' && $email === '') {
            return;
        }

        // tel: links shouldn't contain spaces/formatting; strip everything but digits and a leading +.
        $tel_href = preg_replace('/(?!^\+)[^\d]/', '', $phone);

        echo '<div class="trm-header-contact">';

        if ($phone !== '') {
            printf(
                '<a class="trm-header-contact__item trm-header-contact__item--phone" href="tel:%1$s">'
                    . '<span class="trm-header-contact__icon" aria-hidden="true">%2$s</span>'
                    . '<span class="trm-header-contact__text">'
                    . '<span class="trm-header-contact__label">%3$s</span>'
                    . '<span class="trm-header-contact__value">%4$s</span>'
                    . '</span></a>',
                esc_attr($tel_href),
                $this->phone_icon_svg(),
                esc_html__('Telephone', 'the-realm-malta'),
                esc_html($phone)
            );
        }

        if ($email !== '') {
            printf(
                '<a class="trm-header-contact__item trm-header-contact__item--email" href="mailto:%1$s">'
                    . '<span class="trm-header-contact__icon" aria-hidden="true">%2$s</span>'
                    . '<span class="trm-header-contact__text">'
                    . '<span class="trm-header-contact__label">%3$s</span>'
                    . '<span class="trm-header-contact__value">%4$s</span>'
                    . '</span></a>',
                esc_attr($email),
                $this->email_icon_svg(),
                esc_html__('e-mail:', 'the-realm-malta'),
                esc_html($email)
            );
        }

        echo '</div>';
    }

    /**
     * Render a My Account link in the lower navigation bar.
     * Points at the WooCommerce account page (falls back to home if the page isn't configured).
     *
     * Hook: storefront_header (priority 58)
     *
     * @return void
     */
    public function render_header_account_link()
    {
        $account_url = '';
        if (function_exists('wc_get_page_permalink')) {
            $account_url = wc_get_page_permalink('myaccount');
        }
        if (empty($account_url)) {
            $account_url = home_url('/');
        }

        $label = is_user_logged_in()
            ? __('My Account', 'the-realm-malta')
            : __('Login / Register', 'the-realm-malta');

        printf(
            '<div class="trm-header-account"><a class="trm-header-account__link" href="%1$s" aria-label="%2$s">'
                . '<span class="trm-header-account__icon" aria-hidden="true">%3$s</span>'
                . '<span class="screen-reader-text">%2$s</span>'
                . '</a></div>',
            esc_url($account_url),
            esc_attr($label),
            $this->account_icon_svg()
        );
    }

    private function phone_icon_svg()
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/></svg>';
    }

    private function email_icon_svg()
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/></svg>';
    }

    private function account_icon_svg()
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>';
    }
}
