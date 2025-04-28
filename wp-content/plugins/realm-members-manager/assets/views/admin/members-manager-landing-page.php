<div class='container'>
    <h1>The Realm Members</h1>
    <h3>Use this page to manage all of the members of the realm</h3>

    <div class='info-section'>
        <p style="font-size: 1rem; display: flex; align-items: center; gap: 0.5rem;">
            Inactive users will have this color
            <span style="display: inline-block; width: 20px; height: 20px; background-color: #950606; border-radius: 3px;"></span>
        </p>
    </div>

    <div class="container create-member-container">
        <button class="show-new-member-modal button button-primary" type="button">Create New Member</button>
    </div>

    <div class='users-container container'>
        <table class='rmm-members-table' width="75%">
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
                    <tr class='member-row <?= $user['is_member_flag'] ? 'member' : 'not-a-member' ?>'>
                        <td><?= $user['member_number'] ?></td>
                        <td><?= $user['name'] ?></td>
                        <td><?= $user['email'] ?></td>
                        <td><?= $user['phone'] ?></td>
                        <td><?= $user['is_member'] ?></td>
                        <td><?= $user['expires_at'] ?></td>
                        <td class='action-col'><button class='manage-member-btn button button-primary' data-user-id="<?= $user['id'] ?>">Manage</button></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div id='manage-member-modal' class='member-manage-modal rmm-modal'>
    <button type="button" class="modal-close-button">&times;</button>
    <h2>Manage Member</h2>
    <div class='modal-content'></div>
</div>

<div id="create-user-modal" class="create-user-modal rmm-modal">
    <button type="button" class="modal-close-button">&times;</button>
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