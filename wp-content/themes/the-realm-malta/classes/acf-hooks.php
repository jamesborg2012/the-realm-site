<?php

class TRM_ACF_Hooks extends TRM_Core
{
    public function __construct()
    {
        $this->register_hook_callbacks();
    }

    public function register_hook_callbacks()
    {
        add_action('acf/init', [$this, 'register_acf_blocks']);
    }
    
    public function register_acf_blocks() {
        if ( !function_exists( 'acf_register_block_type' ) ) {
            return;
        }

        acf_register_block_type( array(
            'name' => 'account-creation',
            'title' => __( 'Account Creation' ),
            'description' => __( 'Account Creation block' ),
            'mode' => 'preview',
            'render_template' => 'parts/blocks/block-account-creation.php',
            'category' => 'trm-blocks',
            'icon' => 'info-outline',
            'supports' => array( 'anchor' => true ),
            'keywords' => array( 'account', 'creation', 'form' ),
        ) );
    }
}