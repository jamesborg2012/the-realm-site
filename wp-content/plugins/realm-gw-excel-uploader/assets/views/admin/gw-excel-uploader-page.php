<?php

if (!empty($_POST)) {
    if ($_FILES['gw_file']['tmp_name'] && $_FILES['gw_file']['error'] == UPLOAD_ERR_OK) {
        $class = new Realm_GWEU_Upload_Handler();
        $class->handle_uploaded_file($_POST, $_FILES['gw_file']['tmp_name']);
    }
}

?>

<h1>GW Excel Uploader</h1>
<h3>Use this page to upload GW Excels to put products in the website</h3>
<span>Make sure the files are of type CSV</span>

<form class="gw-excel-upload-form" enctype="multipart/form-data" method="POST">
    <label>
        Type of file uploaded
        <select class="file-type-select" name="file_type">
            <option value="">Select Type</option>
            <option value="eu_pricelist">EU Pricelist</option>
            <option value="insertions">Insertions</option>
            <option value="deletions">Deletions</option>
            <option value="code_changes">Code Changes</option>
        </select>
    </label>

    <input type="file" name="gw_file">

    <button type=" submit">Upload</button>
</form>