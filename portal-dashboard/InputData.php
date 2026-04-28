<?php

$serverName = "10.19.16.21";
$database   = "prod_control";
$username   = "sql_pre";
$password   = "User@eng1";

try {
    $conn = new PDO(
        "sqlsrv:Server=$serverName;Database=$database;Encrypt=no;TrustServerCertificate=yes",
        $username,
        $password
    );
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

$result = null;
$results = [];
$message = "";
$barcode_input = "";
$barcode_serial = "";
$qr_count = 0;

if (isset($_POST['barcode']) && trim($_POST['barcode']) != "") {

    $barcode_input = trim($_POST['barcode']);
    $barcode_serial = $barcode_input;

    // ambil serial jika format URL
    if (stripos($barcode_input, 'Serial=') !== false) {
        $queryString = parse_url($barcode_input, PHP_URL_QUERY);
        if ($queryString) {
            parse_str($queryString, $params);
            if (!empty($params['Serial'])) {
                $barcode_serial = trim($params['Serial']);
            }
        }
    }

    // ========================
    // 🔥 QUERY SEMUA DATA (HISTORY)
    // ========================
    $query = "
        SELECT
            created_at,
            barcode,
            jenis,
            type_pole,
            pole,
            sticker,
            type_battery,
            emboss,
            datecode,
            image_path
        FROM assy_vision
        WHERE barcode = :barcode_full
           OR barcode = :barcode_serial
           OR barcode LIKE :barcode_like
        ORDER BY created_at DESC
    ";

    $stmt = $conn->prepare($query);
    $stmt->execute([
        ':barcode_full'   => $barcode_input,
        ':barcode_serial' => $barcode_serial,
        ':barcode_like'   => '%' . $barcode_serial . '%'
    ]);

    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ========================
    // 🔥 COUNT QR
    // ========================
    if (!empty($barcode_serial)) {
        $countQuery = "
            SELECT COUNT(*) as total
            FROM assy_vision
            WHERE barcode = :barcode_full
               OR barcode = :barcode_serial
               OR barcode LIKE :barcode_like
        ";

        $countStmt = $conn->prepare($countQuery);
        $countStmt->execute([
            ':barcode_full'   => $barcode_input,
            ':barcode_serial' => $barcode_serial,
            ':barcode_like'   => '%' . $barcode_serial . '%'
        ]);

        $countResult = $countStmt->fetch(PDO::FETCH_ASSOC);
        $qr_count = $countResult['total'] ?? 0;
    }

    if (empty($results)) {
        $message = "❌ Data tidak ditemukan!";
    }
}

function statusClass($value) {
    $value = strtoupper(trim($value));

    if ($value === "OK") return "ok";
    if (in_array($value, ["NG","NOK"])) return "ng";
    if ($value === "NO-DETECT") return "warn";

    if (preg_match('/^[A-Z0-9]{11}$/', $value)) {
        return "ok";
    }

    return "";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Input Data Scanner</title>

<style>
body{
    background:#061A40;
    font-family:Segoe UI, Arial, sans-serif;
    color:white;
    margin:0;
    padding:0;
    text-align:center;
}

.header-container{
    display:flex;
    justify-content:space-between;
    align-items:center;
    padding:15px 30px;
    background:#0A1E50;
    box-shadow:0 2px 10px rgba(0,0,0,0.3);
}

.header-title{
    font-size:34px;
    font-weight:bold;
    color:white;
}

.top-buttons{
    display:flex;
    gap:10px;
}

.btn{
    border:none;
    padding:10px 18px;
    font-size:15px;
    border-radius:10px;
    cursor:pointer;
    color:white;
    transition:0.3s;
    text-decoration:none;
    display:inline-block;
}

.btn-dashboard{
    background:#ff4b4b;
    box-shadow:0 0 10px #ff4b4b;
}

.btn-dashboard:hover{
    background:#d63b3b;
}

.btn-refresh{
    background:#00ccff;
    box-shadow:0 0 10px #00ccff;
}

.btn-refresh:hover{
    background:#00aacc;
}

.container{
    padding:30px 20px;
}

.scan-box{
    margin:20px auto;
    max-width:500px;
    background:#0A1E50;
    padding:25px;
    border-radius:18px;
    box-shadow:0 0 20px rgba(0,204,255,0.4);
}

.scan-title{
    font-size:26px;
    font-weight:bold;
    margin-bottom:20px;
}

.scan-input{
    width:90%;
    max-width:400px;
    padding:15px;
    border:none;
    border-radius:12px;
    font-size:18px;
    text-align:center;
    outline:none;
}

.message{
    margin-top:18px;
    font-size:22px;
    font-weight:bold;
    color:#ff6b6b;
}

.card{
    display:flex;
    flex-direction:column;
}

.result-text .info-row{
    border-bottom:1px solid rgba(255,255,255,0.1);
    padding-bottom:6px;
}

.card h2{
    margin-top:0;
    margin-bottom:25px;
    font-size:34px;
}

.info-row{
    font-size:18px;
    margin:14px 0;
    word-break:break-word;
}

.label{
    font-weight:bold;
}

.ok{
    color:#00ff9c;
    font-weight:bold;
}

.ng{
    color:#ff4b4b;
    font-weight:bold;
}

.warn{
    color:#ffcc00;
    font-weight:bold;
}

.img-preview{
    width:350px;
    max-width:90%;
    border-radius:18px;
    margin-top:25px;
    box-shadow:0 0 15px #00ccff;
    cursor:pointer;
}

.small-note{
    margin-top:12px;
    color:#b9d7ff;
    font-size:14px;
}

/* ===== MODAL IMAGE ===== */
.modal {
    display: none;
    position: fixed;
    z-index: 999;
    padding-top: 60px;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.9);
}

.modal-content {
    margin: auto;
    display: block;
    max-width: 80%;
    max-height: 80%;
    border-radius: 20px;
    box-shadow: 0 0 20px #00ccff;
}

.close {
    position: absolute;
    top: 25px;
    right: 40px;
    color: #fff;
    font-size: 40px;
    cursor: pointer;
}

.result-wrapper{
    display:flex;
    align-items:flex-start;
    gap:30px;
}

.result-text{
    width:50%;
    text-align:left;
}

.result-image{
    width:50%;
    display:flex;
    justify-content:center;
    align-items:center;
}

.img-preview{
    width:100%;
    max-width:650px;
    max-height:600px;
    object-fit:contain;
    border-radius:18px;
    box-shadow:0 0 15px #00ccff;
    cursor:pointer;
}

@media (max-width:768px){
    .result-wrapper{
        flex-direction:column;
    }

    .result-text,
    .result-image{
        width:100%;
    }
}

/* ===== GRID INFO ATAS ===== */
.info-grid{
    display:grid;
    grid-template-columns:130px 1fr;
    gap:10px 15px;
    margin-bottom:20px;
}

.info-grid .label{
    font-weight:bold;
    color:#9ecbff;
}

.info-grid .value{
    background:#081c4d;
    padding:8px 12px;
    border-radius:8px;
}

/* ===== GRID CHECK ===== */
.check-grid{
    display:grid;
    grid-template-columns:repeat(2,1fr);
    gap:10px;
    margin-bottom:25px;
}

.check-grid div{
    background:#081c4d;
    padding:10px;
    border-radius:10px;
    display:flex;
    justify-content:space-between;
    font-weight:bold;
}

/* ===== STATUS BESAR ===== */
.final-status{
    text-align:center;
    font-size:42px;
    font-weight:bold;
    padding:15px;
    border-radius:15px;
    margin-top:10px;
}

.final-status.ok{
    background:#003f2f;
    color:#00ff9c;
    box-shadow:0 0 20px #00ff9c;
}

.final-status.ng{
    background:#4d0000;
    color:#ff4b4b;
    box-shadow:0 0 20px #ff4b4b;
}

/* POPUP IMAGE */
.popup{
display:none;
position:fixed;
z-index:999;
padding-top:60px;
left:0;
top:0;
width:100%;
height:100%;
background-color:rgba(0,0,0,0.9);
}

.popup-content{
margin:auto;
display:block;
width:60%;
max-width:800px;
border-radius:10px;
}

.close{
position:absolute;
top:20px;
right:35px;
color:#fff;
font-size:40px;
font-weight:bold;
cursor:pointer;
}
</style>
</head>

<body>

<div class="header-container">
    <div class="header-title">Input Data Scanner</div>
    <div class="top-buttons">
        <a href="index.php" class="btn btn-dashboard">⬅ Dashboard</a>
        <button class="btn btn-refresh" onclick="location.reload()">🔄 Refresh</button>
    </div>
</div>

<div class="container">

<div class="scan-box">
    <div class="scan-title">📷 Scan Barcode</div>

    <form method="POST" id="scanForm" autocomplete="off">
        <input type="text" name="barcode" id="barcodeInput" class="scan-input" autofocus>
    </form>

    <div class="small-note">
        Scanner barcode akan otomatis masuk ke kolom ini lalu submit saat tombol Enter terbaca.
    </div>

    <?php if ($message != "") { ?>
        <div class="message"><?= $message ?></div>
    <?php } ?>
</div>

<?php foreach ($results as $index => $result) { ?>

<?php
$created_at   = $result['created_at'] ?? '-';
$jenis        = $result['jenis'] ?? '-';
$type_pole    = $result['type_pole'] ?? '-';

$pole = $result['pole'] ?? '-';
$sticker = $result['sticker'] ?? '-';
$type_battery = $result['type_battery'] ?? '-';
$emboss = $result['emboss'] ?? '-';
$datecode = $result['datecode'] ?? '-';
$image_path = $result['image_path'] ?? '';

// =======================
// 🔥 BRAND LOGIC
// =======================
$brand = "-";

$cover = $jenis;
$cca   = $emboss;
$barcode_val = $result['barcode'] ?? '-';

$isNoScan = (trim($barcode_val) == "-" || trim($barcode_val) == "");

if($cover == "Biru"){

    if($cca == "OK" && !$isNoScan){
        $brand = "INCOE GOLD";
    }
    elseif($cca == "NOK" && !$isNoScan){
        $brand = "INCOE PREMIUM";
    }
    elseif($cca == "NOK" && $isNoScan){
        $brand = "NON INCOE";
    }

}

$status = "NG";

if (
    $pole == "OK" &&
    $sticker == "OK" &&
    #$emboss == "OK" &&
    !in_array($type_battery, ["NG","NOK"]) &&
    !in_array($datecode, ["NG","NOK"])
){
    $status = "OK";
}
?>

<div class="card">
    <h2>Hasil Scan #<?= $index + 1 ?></h2>

    <div class="result-wrapper">

        <div class="result-text">

            <div class="info-grid">
                <div class="label">Serial</div>
                <div class="value"><?= htmlspecialchars($barcode_serial) ?></div>

                <div class="label">Time</div>
                <div class="value"><?= htmlspecialchars($created_at) ?></div>

                <div class="label">Brand</div>
                <div class="value"><?= $brand ?></div>

                <div class="label">Jenis</div>
                <div class="value"><?= htmlspecialchars($jenis) ?></div>

                <div class="label">Type Pole</div>
                <div class="value"><?= htmlspecialchars($type_pole) ?></div>

                <?php if ($index == 0) { ?>
                <div class="label">Total QR</div>
                <div class="value 
                <?= $qr_count == 1 ? 'ok' : ($qr_count > 1 ? 'ng' : 'warn') ?>">
                    <?= $qr_count ?>
                </div>
                <?php } ?>
            </div>

            <div class="check-grid">
                <div>Pole <span class="<?= statusClass($pole) ?>"><?= $pole ?></span></div>
                <div>Sticker <span class="<?= statusClass($sticker) ?>"><?= $sticker ?></span></div>
                <div>Battery <span class="<?= statusClass($type_battery) ?>"><?= $type_battery ?></span></div>
                <div>CCA <span class="<?= statusClass($emboss) ?>"><?= $emboss ?></span></div>
                <div>Datecode <span class="<?= statusClass($datecode) ?>"><?= $datecode ?></span></div>
            </div>

            <div class="final-status <?= $status=='OK'?'ok':'ng' ?>">
                <?= $status ?>
            </div>

        </div>

        <div class="result-image">
            <?php if (!empty($image_path)) { ?>
                <img class="img-preview"
                src="http://10.19.22.147:8080/ProjectCameraInspection/uploads/<?= basename($image_path) ?>"
                onclick="showImage(this.src)">
            <?php } else { ?>
                <div class="warn">Image tidak tersedia</div>
            <?php } ?>
        </div>

    </div>
</div>

<?php } ?>

<!-- MODAL -->
<div id="imgModal" class="modal">
    <span class="close">&times;</span>
    <img class="modal-content" id="imgZoom">
</div>

<script>
const barcodeInput = document.getElementById("barcodeInput");
const scanForm = document.getElementById("scanForm");

barcodeInput.addEventListener("keypress", function(e){
    if(e.key==="Enter"){
        e.preventDefault();
        scanForm.submit();
    }
});

setInterval(() => {
    barcodeInput.focus();
}, 500);

window.onload = function(){
    barcodeInput.focus();
    barcodeInput.select();
};

const modal = document.getElementById("imgModal");
const modalImg = document.getElementById("imgZoom");
const previewImg = document.getElementById("previewImg");
const closeBtn = document.querySelector(".close");

if (previewImg) {
    previewImg.onclick = function(){
        modal.style.display = "block";
        modalImg.src = this.src;
    }
}

closeBtn.onclick = function(){
    modal.style.display = "none";
}

modal.onclick = function(e){
    if (e.target === modal) {
        modal.style.display = "none";
    }
}

function showImage(src){
document.getElementById("imgPopup").style.display="block";
document.getElementById("popupImg").src=src;
}

function closeImage(){
document.getElementById("imgPopup").style.display="none";
}
</script>

<div id="imgPopup" class="popup">
<span class="close" onclick="closeImage()">&times;</span>
<img class="popup-content" id="popupImg">
</div>

</body>
</html>
