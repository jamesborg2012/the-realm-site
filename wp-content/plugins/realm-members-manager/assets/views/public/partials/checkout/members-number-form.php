<?php
$session_value = WC()->session->get('member_number');
$session_member_number = empty($session_value) ? '' : $session_value;
?>

<div class="realm-member-number-container">
    <?php if ($session_member_number == ''): ?>
        <?php wc_print_notice("Already a member of the Realm? Enter your membership number to claim your discount at checkout!", "notice"); ?>
    <?php endif; ?>

    <div class="membership-number-form-container">
        <input type="text" name="membership-number" id="membership-number" placeholder="123456" value="<?= $session_member_number ?>">
        <button class="button membership-number-btn submit-membership-number" type="button">Apply Member Discount</button>
    </div>
</div>