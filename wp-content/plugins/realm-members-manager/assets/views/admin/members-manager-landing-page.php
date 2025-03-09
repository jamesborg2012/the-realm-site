<div class='container'>
    <h1>The Realm Members</h1>
    <h3>Use this page to manage all of the members of the realm</h3>

    <div class="container create-member-container">
        <button class="show-new-member-modal button primary" type="button">Create New Member</button>
    </div>

    <div class='users-container container'>
        <table width="70%">
            <thead>
                <tr>
                    <th>Member Number</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Member Status</th>
                    <th>Membership Expires</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users_data as $user): ?>
                    <tr>
                        <td><?= $user['member_number'] ?></td>
                        <td><?= $user['name'] ?></td>
                        <td><?= $user['email'] ?></td>
                        <td><?= $user['phone'] ?></td>
                        <td><?= $user['is_member'] ?></td>
                        <td><?= $user['expires_at'] ?></td>
                        <td><button class='manage-member-btn' data-user-id="<?= $user['id'] ?>">Manage</button></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class='member-manage-modal'>
    <h2>Manage Member</h2>
    <div class='modal-content'></div>
</div>

<div id="create-user-modal" class="create-user-modal modal">
    <div class="modal-container">
        <h2>Create new Realm Member</h2>
        <div class="member-number-container input-container">
            <label>
                Member Number:
                <input name="rmm_membership_number" id="rmm_membership_number">
            </label>
        </div>
        <div class="member-name-container input-container">
            <label>
                Name:
                <input name="rmm_member_name" id="rmm_member_name">
            </label>
            <label>
                Surname:
                <input name="rmm_member_surname" id="rmm_member_surname">
            </label>
        </div>
        <div class="member-info-container input-container">
            <label>
                Email:
                <input name="rmm_member_email" id="rmm_member_email">
            </label>
            <label>
                Phone Number:
                <input name="rmm_member_phone" id="rmm_member_phone">
            </label>
        </div>
        <div class="button-container">
            <button type="button" class="button button-primary create-member">Create Member</button>
        </div>
    </div>
</div>