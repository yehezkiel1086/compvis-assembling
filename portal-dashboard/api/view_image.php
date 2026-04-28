<?php
// =====================================
// VIEW IMAGE + BUTTON KEMBALI (BENAR)
// =====================================

$path = $_GET['path'] ?? '';
$filename = basename($path);

$baseDir = __DIR__ . '/uploads/';
$file = realpath($baseDir . $filename);

if (!$file || !file_exists($file)) {
    http_response_code(404);
    die("Image not found");
}

// ambil mime
$mime = mime_content_type($file);

// encode image ke base64 supaya bisa ditampilkan di HTML
$imageData = base64_encode(file_get_contents($file));
$imageSrc  = "data:$mime;base64,$imageData";
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>View Image</title>

<style>
body {
    margin: 0;
    padding: 30px;
    background: radial-gradient(circle at top, #0f1c38, #070b18);
    font-family: Arial, Helvetica, sans-serif;
    color: #fff;
    text-align: center;
}

.btn-back {
    display: inline-block;
    margin-bottom: 20px;
    padding: 10px 22px;
    background: #4f7dd1;
    color: #fff;
    text-decoration: none;
    border-radius: 8px;
    font-size: 14px;
}

.btn-back:hover {
    background: #6b95e5;
}

.image-box {
    display: inline-block;
    padding: 15px;
    background: #071225;
    border-radius: 12px;
    border: 2px solid #4f7dd1;
}

.image-box img {
    max-width: 90vw;
    max-height: 80vh;
    border-radius: 8px;
}
</style>
</head>

<body>

<a href="../Tabel_Data_Hariini.php" class="btn-back">⬅ Kembali ke Tabel</a>

<div class="image-box">
    <img src="<?= $imageSrc ?>" alt="Captured Image">
</div>

</body>
</html>
