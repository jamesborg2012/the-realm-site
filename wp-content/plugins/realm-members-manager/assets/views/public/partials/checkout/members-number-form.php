<?php
/**
 * Member-number form shown above the checkout form.
 *
 * @var bool   $auto_applied   True when the logged-in user is an active member (discount is automatic).
 * @var string $applied_number Member number a guest has applied this session (empty if none).
 */
$auto_applied   = !empty($auto_applied);
$applied_number = isset($applied_number) ? $applied_number : '';
?>

<div class="realm-member-number-container">
    <?php if ($auto_applied): ?>

        <?php wc_print_notice("Your member discount has been applied to this order.", "success"); ?>

    <?php elseif ($applied_number !== ''): ?>

        <?php wc_print_notice("Member discount applied for membership number " . esc_html($applied_number) . ".", "success"); ?>

        <div class="membership-number-form-container">
            <input type="text" name="membership-number" id="membership-number" placeholder="123456" value="<?php echo esc_attr($applied_number); ?>">
            <button class="button membership-number-btn submit-membership-number" type="button">Update Member Discount</button>
        </div>

    <?php else: ?>

        <?php wc_print_notice("Already a member of the Realm? Enter your membership number to claim your discount at checkout!", "notice"); ?>

        <div class="membership-number-form-container">
            <input type="text" name="membership-number" id="membership-number" placeholder="123456" value="">
            <button class="button membership-number-btn submit-membership-number" type="button">Apply Member Discount</button>
        </div>

    <?php endif; ?>
</div>
