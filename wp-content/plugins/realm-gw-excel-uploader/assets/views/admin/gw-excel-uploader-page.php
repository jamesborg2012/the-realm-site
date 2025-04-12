<?php
if (!empty($_FILES)) {
    if ($_FILES['gw_file']['tmp_name'] && $_FILES['gw_file']['error'] == UPLOAD_ERR_OK) {
        $class = new Realm_GWEU_Upload_Handler();
        $class->handle_uploaded_file($_POST, $_FILES['gw_file']['tmp_name']);
    }
}

?>

<h1>GW Excel Uploader</h1>
<h3>Use this page to upload GW Excels with code changes to update products on the site</h3>
<span>Make sure the files are of type CSV (.csv)</span>

<form class="gw-excel-upload-form" enctype="multipart/form-data" method="POST">
    <input type="file" name="gw_file" id="gw_file">
    <button type="submit">Upload</button>
</form>