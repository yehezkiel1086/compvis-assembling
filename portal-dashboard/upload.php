<?php

// ================== SETUP ==================
$target_dir = "uploads/";

if (!is_dir($target_dir)) {
    mkdir($target_dir, 0777, true);
}

if (!isset($_FILES['image'])) {
    echo "ERROR: No file uploaded";
    exit;
}

$image = $_FILES['image']['name'];
$tmp   = $_FILES['image']['tmp_name'];

$type_pole    = $_POST['type_pole'] ?? '-';
$sticker      = $_POST['sticker'] ?? '-';
$type_battery = $_POST['type_battery'] ?? '-';
$emboss       = $_POST['emboss'] ?? '-';
$datecode     = $_POST['datecode'] ?? '-';

// ================== UPLOAD FILE ==================
$newname = date("YmdHis") . "_" . basename($image);
$path = $target_dir . $newname;

if (move_uploaded_file($tmp, $path)) {

    // ================== TRY DATABASE ==================
    try {

        $conn = new mysqli("localhost","root","","monitoring_ai");

        if (!$conn->connect_error) {

            $stmt = $conn->prepare("
                INSERT INTO inspection_result
                (created_at,type_pole,sticker,type_battery,emboss,datecode,image_path)
                VALUES (NOW(),?,?,?,?,?,?)
            ");

            $stmt->bind_param(
                "ssssss",
                $type_pole,
                $sticker,
                $type_battery,
                $emboss,
                $datecode,
                $path
            );

            $stmt->execute();
        }

    } catch (Exception $e) {
        // 🔥 DB gagal? biarin aja (tidak ganggu upload)
    }

    // ================== RETURN URL ==================
    $base_url = "http://10.19.22.147:8080/ProjectCameraInspection/";
    echo $base_url . $path;

} else {
    echo "ERROR: Upload gagal";
}
?>