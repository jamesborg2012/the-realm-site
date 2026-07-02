<?php
/**
 * My Account → Membership tab.
 *
 * @var bool   $is_active
 * @var bool   $is_expired
 * @var bool   $is_pending
 * @var string $status
 * @var string $number
 * @var string $expire
 * @var string $price
 * @var string $expiry_display
 * @var float  $store_discount
 * @var float  $online_discount
 * @var string $notice
 */

if (!defined('ABSPATH')) {
    die('ACCESS_DENIED');
}

$notices = [
    'applied'        => ['success', 'Thanks! Your membership application has been received and is now under review. We\'ll be in touch to finalise it.'],
    'linked'         => ['success', 'Thanks! We\'ve recorded your membership number and it\'s now under review.'],
    'number_missing' => ['error', 'Please enter your membership number.'],
    'number_taken'   => ['error', 'That membership number is already linked to another account. Please contact us if you think this is a mistake.'],
    'already'        => ['notice', 'You\'re already an active member.'],
];

if ($notice !== '' && isset($notices[$notice])) {
    wc_print_notice(esc_html($notices[$notice][1]), $notices[$notice][0]);
}
?>

<div class="rmm-membership">

    <?php if ($is_active) : ?>

        <h3 class="rmm-membership__heading">Your Membership</h3>
        <p>You're an active member of The Realm — enjoy your discounts every time you shop.</p>

        <table class="rmm-membership__details shop_table">
            <tbody>
                <?php if (!empty($number)) : ?>
                    <tr>
                        <th>Membership Number</th>
                        <td><?php echo esc_html($number); ?></td>
                    </tr>
                <?php endif; ?>
                <tr>
                    <th>Status</th>
                    <td><span class="rmm-membership__badge rmm-membership__badge--active">Active</span></td>
                </tr>
                <?php if (!empty($expire)) : ?>
                    <tr>
                        <th>Valid Until</th>
                        <td><?php echo esc_html(date_i18n('F j, Y', strtotime($expire))); ?></td>
                    </tr>
                <?php endif; ?>
                <tr>
                    <th>Your Discount</th>
                    <td><?php echo esc_html(rtrim(rtrim(number_format($store_discount, 2), '0'), '.')); ?>% off in store &amp; online<?php if ($online_discount > 0) : ?>, <?php echo esc_html(rtrim(rtrim(number_format($online_discount, 2), '0'), '.')); ?>% on online-only products<?php endif; ?></td>
                </tr>
            </tbody>
        </table>

    <?php elseif ($is_pending) : ?>

        <h3 class="rmm-membership__heading">Membership Under Review</h3>
        <p>Your membership application is being reviewed. We'll be in touch shortly to finalise it — thank you for joining The Realm community!</p>
        <?php if (!empty($number)) : ?>
            <p><strong>Membership Number:</strong> <?php echo esc_html($number); ?></p>
        <?php endif; ?>

    <?php else : ?>

        <?php if ($is_expired) : ?>
            <h3 class="rmm-membership__heading">Renew Your Membership</h3>
            <p>Your membership has expired. Renew below to keep enjoying member discounts.</p>
        <?php else : ?>
            <h3 class="rmm-membership__heading">Become a Member</h3>
            <p>Join The Realm membership and enjoy <strong><?php echo esc_html(rtrim(rtrim(number_format($store_discount, 2), '0'), '.')); ?>% off</strong> products in store and online, plus access to gaming tables at The Realm location!</p>
        <?php endif; ?>

        <ul class="rmm-membership__offer">
            <?php if ($price !== '' && $price !== null) : ?>
                <li><strong>Donation:</strong> <?php echo wp_kses_post(wc_price((float) $price)); ?></li>
            <?php endif; ?>
            <?php if (!empty($expiry_display)) : ?>
                <li><strong>Valid Until:</strong> <?php echo esc_html($expiry_display); ?></li>
            <?php endif; ?>
            <li>Renews yearly at the end of the year.</li>
        </ul>

        <form method="post" class="rmm-membership__form rmm-membership__form--apply">
            <?php wp_nonce_field('rmm_membership_action', 'rmm_membership_nonce'); ?>
            <input type="hidden" name="rmm_membership_action" value="apply" />
            <button type="submit" class="button"><?php echo $is_expired ? 'Renew Membership' : 'Become a Member'; ?></button>
        </form>

        <hr class="rmm-membership__sep" />

        <h4 class="rmm-membership__subheading">Already have a membership number?</h4>
        <p>If you're already a member of The Realm, enter your membership number below to link it to your account.</p>

        <form method="post" class="rmm-membership__form rmm-membership__form--link">
            <?php wp_nonce_field('rmm_membership_action', 'rmm_membership_nonce'); ?>
            <input type="hidden" name="rmm_membership_action" value="link_number" />
            <p class="form-row">
                <label for="rmm_membership_number">Membership Number</label>
                <input type="text" id="rmm_membership_number" name="rmm_membership_number" class="input-text" placeholder="123456" />
            </p>
            <button type="submit" class="button">Link Membership Number</button>
        </form>

    <?php endif; ?>

</div>
