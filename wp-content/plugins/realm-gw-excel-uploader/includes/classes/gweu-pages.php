<?php

class GWEU_Pages extends Realm_Gw_Excel_Uploader_Core
{
    public function __construct()
    {
        add_action('admin_menu', [$this, 'register_menu_pages']);
    }

    public function register_menu_pages()
    {
        add_menu_page(
            'GW Excel Uploader',
            'GW Excel Uploader',
            'manage_options',
            'gw-excel-uploader',
            [$this, 'render_gw_excel_uploader']
        );
    }

    public function render_gw_excel_uploader()
    {
        echo $this->render_template(
            'admin/gw-excel-uploader-page',
        );
    }
}
