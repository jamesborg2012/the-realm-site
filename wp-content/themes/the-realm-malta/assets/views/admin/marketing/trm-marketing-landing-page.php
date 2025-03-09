<?php

$marketing_users = get_users([
    'role' => 'marketing',
]);

$table_data = [];
foreach ($marketing_users as $marketing_user) {
    $wc_orders = wc_get_orders([
        'status' => 'wc-processing',
        'wc-completed',
        'customer' => $marketing_user->ID
    ]);

    /**
     * @var WC_Order $wc_order
     * @var WC_Order_Item $order_item
     */
    foreach ($wc_orders as $wc_order) {
        $order_items = $wc_order->get_items();
        foreach ($order_items as $order_item) {
            $table_data[] = [
                'date' => date('dS F Y H:i:s', strtotime($wc_order->get_date_created())),
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
    <table class="marketing-purchases">
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
            <?php foreach ($table_data as $table_row): ?>
                <tr>
                    <td><?= $table_row['date'] ?></td>
                    <td><?= $table_row['item'] ?></td>
                    <td><?= $table_row['quantity'] ?></td>
                    <td><a href="<?= $table_row['order'] ?>">Access Order</a></td>
                    <td><?= $table_row['total'] ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>