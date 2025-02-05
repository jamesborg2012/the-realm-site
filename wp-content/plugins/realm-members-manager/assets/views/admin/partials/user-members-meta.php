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
                        <option value=<?= $key ?> <?= $key == $user_membership_status ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
    </tbody>
</table>