<div class="container">
    <h1>Member Discount Management</h1>
    <h3>Enter the percentage discount to provide to members and non-members</h3>
    <form method="post" action="options.php">
        <?php
        settings_fields('rmm_member_discount_setting_group');
        do_settings_sections('rmm-member-discount-settings');
        submit_button();
        ?>
    </form>
</div>