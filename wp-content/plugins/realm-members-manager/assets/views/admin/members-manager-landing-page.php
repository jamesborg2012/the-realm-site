<div class='container'>
    <h1>The Realm Members</h1>
    <h3>Use this page to manage all of the members of the realm</h3>

    <div class='users-container container'>
        <table width="70%">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Member Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users_data as $user): ?>
                    <tr>
                        <td><?= $user['name'] ?></td>
                        <td><?= $user['email'] ?></td>
                        <td><?= $user['phone'] ?></td>
                        <td><?= $user['is_member'] ?></td>
                        <td>TODO</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>