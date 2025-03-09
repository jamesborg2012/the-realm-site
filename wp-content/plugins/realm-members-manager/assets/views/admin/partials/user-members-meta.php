<?php

$membership_statuses = [
    'not_active' => 'Not Active',
    'active' => 'Active'
];

?>

<h2>The Realm Members Related</h2>
<table class="form-table">
    <tbody>
        <tr>
            <th><label for="rmm_membership_status">Membership Status</label></th>
            <td>
                <select name="rmm_membership_status" id="rmm_membership_status">
                    <?php foreach ($membership_statuses as $key => $label): ?>
                        <option value=<?= $key ?> <?= $key == $rmm_membership_status ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr>
            <th><label for="rmm_membership_number">Member Number</label></th>
            <td>
                <input name="rmm_membership_number" id="rmm_membership_number" value="<?= $rmm_membership_number ?>">
            </td>
            </td>
        </tr>
        <tr>
            <th><label for="rmm_membership_expire">Membership Expiry Date</label></th>
            <td>
                <input name="rmm_membership_expire" id="rmm_membership_expire" value="<?= $rmm_membership_expire ?>">
            </td>
            </td>
        </tr>
    </tbody>
</table>