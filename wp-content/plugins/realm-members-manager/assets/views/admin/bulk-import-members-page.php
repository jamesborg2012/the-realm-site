<?php

if (!empty($_POST)) {
    if ($_FILES['members_file']['tmp_name'] && $_FILES['members_file']['error'] == UPLOAD_ERR_OK) {
        $class = new RMM_Upload_Handler();
        $class->handle_uploaded_file($_POST, $_FILES['members_file']['tmp_name']);
    }
}

?>

<h1>Bulk Import Realm Members from CSV</h1>

<form class="rmm-bulk-import-form" enctype="multipart/form-data" method="POST" name="test">
    <input type="file" name="members_file">
    <input type="submit" name="submit" value="Import">
</form>