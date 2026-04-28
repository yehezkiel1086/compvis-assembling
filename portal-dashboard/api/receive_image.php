<?php

$conn = new mysqli("localhost","root","","monitoring_ai");

$target_dir = "../uploads/";

$image = $_FILES['image']['name'];
$tmp   = $_FILES['image']['tmp_name'];

$type_pole    = $_POST['type_pole'];
$sticker      = $_POST['sticker'];
$type_battery = $_POST['type_battery'];
$emboss       = $_POST['emboss'];
$datecode     = $_POST['datecode'];

$newname = date("YmdHis")."_".$image;
$path = $target_dir.$newname;

move_uploaded_file($tmp,$path);

$conn->query("
INSERT INTO inspection_result
(created_at,type_pole,sticker,type_battery,emboss,datecode,image_path)
VALUES
(NOW(),'$type_pole','$sticker','$type_battery','$emboss','$datecode','$path')
");

echo "SUCCESS";

?>