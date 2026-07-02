<?php

if (!defined('ABSPATH')) {
    die('ACCESS_DENIED');
}

class RMM_Upload_Handler
{
    private $core_class;

    public function __construct()
    {
        $this->core_class = Realm_Members_Manager_Core::get_instance();
    }

    public function handle_uploaded_file($post_data, $file)
    {
        $csv_data = file_get_contents($file);

        $lines = explode("\n", $csv_data);

        $header = str_getcsv(array_shift($lines));

        foreach ($header as &$header_col) {
            $header_col = strtolower($header_col);
            $header_col = str_replace(' ', '_', $header_col);
        }

        $file_data = [];
        foreach ($lines as $line) {
            if (empty($line)) {
                continue;
            }

            $data = str_getcsv($line);

            $file_data[] = array_combine($header, $data);
        }

        $compared_comodities = [];

        if (!empty($file_data)) {
            foreach ($file_data as $row) {
                $query_args = [
                    'meta_query' => [
                        'relation' => 'OR',
                        [
                            'key' => 'rmm_membership_number',
                            'value' => $row['member_number'],
                            'compare' => '=',
                        ]
                    ]
                ];

                if (!empty($row['mobile_number'])) {
                    $query_args['meta_query'][] = [
                        [
                            'key' => 'billing_phone',
                            'value' => $row['mobile_number'],
                            'compare' => '=',
                        ]

                    ];
                }

                $user_query = new WP_User_Query($query_args);
                $user = $user_query->get_results();

                if (!empty($user)) {
                    continue;
                }

                $user = wp_insert_user([
                    'user_pass' => wp_generate_password(),
                    'role' => 'customer',
                    'first_name' => $row['first_name'],
                    'last_name' => $row['last_name'],
                    'user_login' => strtolower($row['first_name']) . '_' . strtolower($row['last_name']) . substr($row['mobile_number'], -1, 4),
                ]);

                if (is_wp_error($user)) {
                    //TODO
                }

                update_user_meta($user->id, 'billing_phone', $row['mobile_number']);
                update_user_meta($user->id, 'rmm_membership_number', $row['member_number']);
                update_user_meta($user->id, 'rmm_membership_status', 'active');

                $expire_date = str_replace('/', '-', $row['expire']);
                $expire_date_ts = strtotime($expire_date);

                $expire_date = date('Y-m-d', $expire_date_ts);
                $coupon_expiry_date = date('Y-m-d', $expire_date_ts);

                update_user_meta($user->id, 'rmm_membership_expiry', $expire_date);

                // Member discounts are applied directly to the cart per tax class (item 23);
                // imported members no longer get store/online-only coupons.
                unset($coupon_expiry_date);
            }
        }
    }
}
