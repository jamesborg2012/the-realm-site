<div class='container'>
    <h1>The Realm Members</h1>
    <h3>Use this page to manage all of the members of the realm</h3>

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