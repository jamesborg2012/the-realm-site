<?php

class Realm_GWEU_Upload_Handler
{
    private $core_class;

    public function __construct()
    {
        $this->core_class = Realm_Gw_Excel_Uploader_Core::get_instance();
    }

    public function handle_uploaded_file($post_data, $file)
    {
        $file_type = $post_data['file_type'] ?? '';

        if (empty($file_type)) {
            return;
            //throw error
        }

        $file_data = $this->get_file_data($file_type, $file);
    }

    public function get_file_data($file_type, $file)
    {
        $file_data = file_get_contents($file);

        $this->core_class->write_log($file_data);
    }
}
