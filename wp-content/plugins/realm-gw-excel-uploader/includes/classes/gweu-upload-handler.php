<?php

use Automattic\WooCommerce\Admin\API\AI\Product;

class Realm_GWEU_Upload_Handler
{
    private $core_class;

    public function __construct()
    {
        $this->core_class = Realm_Gw_Excel_Uploader_Core::get_instance();
    }

    public function handle_uploaded_file($post_data, $file)
    {
        $csv_data = file_get_contents($file);
        $lines = explode(PHP_EOL, $csv_data);

        $file_data = [];

        foreach ($lines as $line) {
            if (str_contains($line, 'Barcode') || str_contains($line, 'barcode')) {
                continue;
            }

            $contents = str_getcsv($line);

            $file_data[] = [
                'sku' => $contents[0],
                'new_sku' => $contents[1],
                'product_code' => $contents[2],
                'ssc_code' => $contents[3],
                'old_barcode' => $contents[4],
                'old_product_code' => $contents[5],
                'old_ssc_code' => $contents[6],
            ];
        }

        foreach ($file_data as $row) {
            $product_sku = $row['sku'];

            $product_id = wc_get_product_id_by_sku($product_sku);

            if ($product_id <= 0) {
                //TODO - Error Handling
            }

            $product = new WC_Product($product_id);

            $product_code = $product->get_meta('_product_code', true);
            $ssc_code = $product->get_meta('_ssc_code', true);

            $old_barcodes = $product->get_meta('trm_gw_old_barcodes', true);
            $old_product_codes = $product->get_meta('trm_gw_old_product_codes', true);
            $old_ssc_codes = $product->get_meta('trm_gw_old_ssc_codes', true);

            $old_barcodes = $this->update_old_codes($row['old_barcode'], $old_barcodes);
            $old_product_codes = $this->update_old_codes($row['old_product_code'], $old_product_codes);
            $old_ssc_codes = $this->update_old_codes($row['old_ssc_code'], $old_ssc_codes);

            $product->set_sku($row['new_sku']);
            $product->update_meta_data('_product_code', $row['product_code']);
            $product->update_meta_data('_ssc_code', $row['ssc_code']);

            $product->update_meta_data('trm_gw_old_barcodes', $old_barcodes);
            $product->update_meta_data('trm_gw_old_product_codes', $old_product_codes);
            $product->update_meta_data('trm_gw_old_ssc_codes', $old_ssc_codes);

            $product->save();
        }
    }

    /**
     * Updates the existing old meta codes with additional ones from the CSV
     */
    private function update_old_codes($csv_codes, $meta_codes): array
    {
        if (empty($meta_codes)) {
            $meta_codes = [];
        }

        $lookup = array_flip(array_map('serialize', $meta_codes));

        $codes = str_replace(['; ', ' ;'], ';', $csv_codes);
        $codes = explode(';', $csv_codes);

        foreach ($codes as $code) {
            $needle = serialize($code);

            if (isset($lookup[$needle])) {
                continue;
            }

            $meta_codes[] = [$code];
        }

        return $meta_codes;
    }
}
