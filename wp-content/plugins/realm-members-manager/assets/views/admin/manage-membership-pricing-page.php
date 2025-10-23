<div class="container">
    <h1>Membership Pricings Management</h1>
    <h3>Enter the price for each set of durations that a membership will cost</h3>
    <form method="post" action="options.php">
        <?php
        settings_fields('rmm_membership_pricing_setting_group');
        do_settings_sections('rmm-membership-pricing-settings');
        submit_button();
        ?>
    </form>
</div>