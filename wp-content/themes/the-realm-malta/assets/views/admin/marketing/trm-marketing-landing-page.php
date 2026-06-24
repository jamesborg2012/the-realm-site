<?php

$marketing_users = get_users([
    'role' => 'marketing',
]);

$table_data = [];
foreach ($marketing_users as $marketing_user) {
    $wc_orders = wc_get_orders([
        'status'      => ['wc-processing', 'wc-completed'],
        'customer_id' => $marketing_user->ID,
        'limit'       => -1,
        'orderby'     => 'date',
        'order'       => 'DESC',
    ]);

    /**
     * @var WC_Order $wc_order
     * @var WC_Order_Item $order_item
     */
    foreach ($wc_orders as $wc_order) {
        $date_created = $wc_order->get_date_created();
        $order_date   = $date_created ? $date_created->date('dS F Y H:i:s') : '';

        $order_items = $wc_order->get_items();
        foreach ($order_items as $order_item) {
            $table_data[] = [
                'date' => $order_date,
                'item' => $order_item->get_name(),
                'quantity' => $order_item->get_quantity(),
                'order' => $wc_order->get_edit_order_url(),
                'total' => "€" . round(floatval($order_item->get_total()) + floatval($order_item->get_total_tax()), 2),
            ];
        }
    }
}

?>


<div class="container">
    <div class="title-container">
        <h1>The Realm - Marketing Related</h1>
    </div>
    <table class="marketing-purchases" width="70%">
        <thead>
            <tr>
                <th>Date</th>
                <th>Item Purchased</th>
                <th>Quantity</th>
                <th>Related Order</th>
                <th>Total (€)</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($table_data)): ?>
                <tr>
                    <td colspan="5">No marketing purchases found yet.</td>
                </tr>
            <?php endif; ?>
            <?php foreach ($table_data as $table_row): ?>
                <tr>
                    <td><?= esc_html($table_row['date']) ?></td>
                    <td><?= esc_html($table_row['item']) ?></td>
                    <td><?= esc_html($table_row['quantity']) ?></td>
                    <td><a class='order-button' href="<?= esc_url($table_row['order']) ?>">ACCESS ORDER</a></td>
                    <td><?= esc_html($table_row['total']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>