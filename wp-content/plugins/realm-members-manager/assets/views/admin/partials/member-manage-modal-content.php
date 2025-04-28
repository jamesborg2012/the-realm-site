<?php

$user = get_user_by('id', $user_id);

$name = $user->first_name . ' ' . $user->last_name;
$membership_status = get_user_meta($user->ID, 'rmm_membership_status', true);

if ($membership_status == 'active') {
    $membership_number = get_user_meta($user->ID, 'rmm_membership_number', true);

    $name = "#" . $membership_number . " - " . $name;
}

$expires_at = get_user_meta($user->ID, 'rmm_membership_expire', true);

$email = $user->user_email;
$phone = get_user_meta($user->ID, 'billing_phone', true);

$contact_details = "Phone: " . $phone . ' - Email: ' . $email;

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
                <input type="text" name="rmm_membership_expires" id="rmm_membership_expires" value="<?= $expires_at ?>">
            </label>
        </div>
        <div class='save-button-container'>
            <input type="hidden" name='user_id' value="<?= $user_id ?>">
            <button type='submit' class='button button-primary update-member-details'>Update Member Details</button>
        </div>
    </form>
</div>