<?php

$user = get_user_by('id', $user_id);

$name = $user->first_name . ' ' . $user->last_name;
$membership_status = get_user_meta($user->ID, 'rmm_membership_status', true);
$membership_number = get_user_meta($user->ID, 'rmm_membership_number', true);
$has_membership_number = !empty($membership_number);

if ($membership_status == 'active') {
    $name = "#" . $membership_number . " - " . $name;
}

$expires_at = get_user_meta($user->ID, 'rmm_membership_expire', true);
$expires_at_normalized = '';
if (!empty($expires_at)) {
    $ts = strtotime(str_replace('/', '-', $expires_at));
    if ($ts !== false) {
        $expires_at_normalized = date('Y-m-d', $ts);
    }
}
$expires_min = date('Y-m-d', strtotime('-1 year'));
$expires_max = date('Y-12-31', strtotime('+1 year'));

$email = $user->user_email;
$phone = get_user_meta($user->ID, 'billing_phone', true);

$contact_details = 'Email: ' . $email;

$membership_status_options = [
    'active' => 'Member',
    'deactive' => 'Not a Member'
];
?>

<div class="member-name">
    <strong><?= $name ?></strong>
</div>
<div class="member-contact">
    <p><?= $contact_details ?></p>
</div>
<div class="membership-details flex-grid">
    <form action='#' method="POST" class='update-member-details-form'>
        <div class="member-first-name-container">
            <label for="rmm_member_first_name">
                First Name
                <input type="text" name="rmm_member_first_name" id="rmm_member_first_name" value="<?= esc_attr($user->first_name) ?>" required>
            </label>
        </div>
        <div class="member-last-name-container">
            <label for="rmm_member_last_name">
                Last Name
                <input type="text" name="rmm_member_last_name" id="rmm_member_last_name" value="<?= esc_attr($user->last_name) ?>" required>
            </label>
        </div>
        <div class="member-phone-container">
            <label for="rmm_member_phone">
                Phone Number <span class="optional-hint">(optional)</span>
                <input type="text" name="rmm_member_phone" id="rmm_member_phone" value="<?= esc_attr($phone) ?>">
            </label>
        </div>
        <div class="membership-number-container">
            <label for="rmm_membership_number">
                Membership Number
                <input type="text" name="rmm_membership_number" id="rmm_membership_number" value="<?= esc_attr($membership_number) ?>" <?= $has_membership_number ? 'disabled' : 'required' ?>>
            </label>
        </div>
        <div class="memerbship-status-container">
            <label for="rmm_membership_status">
                Membership Status
                <select id="rmm_membership_status" name="rmm_membership_status" class="membership-status-select">
                    <?php foreach ($membership_status_options as $value => $label):
                    ?>
                        <option value="<?= $value ?>" <?= $membership_status == $value ? 'selected' : '' ?>>
                            <?= $label ?>
                        </option>
                    <?php
                    endforeach; ?>
                </select>
            </label>
        </div>
        <div class="membership-expire-container">
            <label for="rmm_membership_expires">
                Membership Expiry Date
                <input type="date" name="rmm_membership_expires" id="rmm_membership_expires" value="<?= esc_attr($expires_at_normalized) ?>" min="<?= esc_attr($expires_min) ?>" max="<?= esc_attr($expires_max) ?>">
            </label>
        </div>
        <div class='save-button-container'>
            <input type="hidden" name='user_id' value="<?= $user_id ?>">
            <button type='submit' class='button button-primary update-member-details'>Update Member Details</button>
        </div>
    </form>
</div>