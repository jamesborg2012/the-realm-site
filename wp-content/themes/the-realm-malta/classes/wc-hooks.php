<?php

class TRM_WC_Hooks extends TRM_Core
{
    public function __construct()
    {
        $this->remove_wc_hooks();
        $this->register_hook_callbacks();
    }

    public function remove_wc_hooks()
    {
        remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_meta', 40);
    }

    public function register_hook_callbacks() {}
}
