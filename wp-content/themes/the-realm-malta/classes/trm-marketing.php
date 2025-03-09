<?php

class TRM_Marketing_Handler extends TRM_Core
{
    public function __construct()
    {
        $this->register_hook_callbacks();
    }

    public function register_hook_callbacks()
    {
        add_action('admin_menu', [$this, 'register_marketing_manager_menu']);
    }

    public function register_marketing_manager_menu()
    {
        add_menu_page(
            'The Realm Marketing',
            'The Realm Marketing',
            'manage_woocommerce',
            'trm-marketing',
            [$this, 'render_realm_members_page']
        );
    }

    public function render_realm_members_page()
    {
        echo $this->render_template(
            'admin/marketing/trm-marketing-landing-page',
        );
    }
}
