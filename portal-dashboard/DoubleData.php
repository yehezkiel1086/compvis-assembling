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
}
catch(PDOException $e){
die("Connection failed: ".$e->getMessage());
}

$selected_date = $_GET['filter_date'] ?? '';
$shift = $_GET['shift'] ?? '';

$where = [];

if($selected_date != '' && $shift != ''){
    $next_day = date('Y-m-d', strtotime($selected_date . ' +1 day'));

    if($shift == "1"){
        $where[] = "created_at BETWEEN '$selected_date 07:30:00' AND '$selected_date 16:29:59'";
    } elseif($shift == "2"){
        $where[] = "created_at BETWEEN '$selected_date 16:30:00' AND '$next_day 00:29:59'";
    } elseif($shift == "3"){
        $where[] = "created_at BETWEEN '$next_day 00:30:00' AND '$next_day 07:29:59'";
    }
}
elseif($selected_date != ''){
    $where[] = "created_at BETWEEN '$selected_date 00:00:00' AND '$selected_date 23:59:59'";
}

$whereSQL = count($where) ? "WHERE ".implode(" AND ", $where) : "";

/* ===============================
   📅 DUPLICATE PER HARI
================================= */

$calendar_query = "
SELECT 
    CONVERT(date, created_at) as tanggal,
    barcode,
    COUNT(*) as jumlah
FROM assy_vision
GROUP BY CONVERT(date, created_at), barcode
HAVING COUNT(*) > 1
ORDER BY tanggal DESC
";

$stmt = $conn->query($calendar_query);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$calendar_map = [];

foreach($rows as $r){
    $tgl = $r['tanggal'];

    if(!isset($calendar_map[$tgl])){
        $calendar_map[$tgl] = [];
    }

    $calendar_map[$tgl][] = [
        'barcode' => $r['barcode'],
        'jumlah' => $r['jumlah']
    ];
}

?>

<!DOCTYPE html>
<html>
<head>

<title>Double Data Monitoring</title>

<style>
body{
background:#061A40;
color:white;
font-family:Segoe UI;
margin:0;
}

.header{
font-size:30px;
background:#4D79D8;
padding:15px;
}

.container{
padding:20px;
}

.card{
padding:15px;
border-radius:12px;
}

.btn{
padding:5px 10px;
border:none;
border-radius:6px;
cursor:pointer;
margin-top:5px;
}

</style>

</head>

<body>

<div class="header">
⚠ Double Barcode Monitoring
</div>

<div class="container">

<!-- 🔍 FILTER -->
<form method="GET" style="margin-bottom:20px;">
<input type="date" name="filter_date" value="<?= $selected_date ?>">
<select name="shift">
<option value="">All Shift</option>
<option value="1">Shift 1</option>
<option value="2">Shift 2</option>
<option value="3">Shift 3</option>
</select>
<button type="submit">Filter</button>
</form>

<!-- 📅 KALENDER SIMPLE -->
<div style="display:grid; grid-template-columns:repeat(5,1fr); gap:15px;">

<?php
for($i=0; $i<30; $i++){

$date = date('Y-m-d', strtotime("-$i days"));

$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime("-1 days"));

$bg = "#152D6B";
if($date == $today) $bg="#00cc66";
elseif($date == $yesterday) $bg="#ffaa00";

$total = isset($calendar_map[$date]) ? count($calendar_map[$date]) : 0;
?>

<div class="card" style="background:<?= $bg ?>;">

<div style="font-size:18px; font-weight:bold;">
<?= date('d M', strtotime($date)) ?>
</div>

<div style="margin:8px 0;">
<?= $total ?> Duplicate
</div>

<?php if($total > 0){ ?>
<button class="btn" onclick="toggle('d<?= $i ?>')">
Lihat Detail ▼
</button>

<div id="d<?= $i ?>" style="display:none; margin-top:10px; font-size:13px;">

<?php
foreach($calendar_map[$date] as $item){

$color = $item['jumlah'] >= 3 ? '#ff4b4b' : '#ffff66';

echo "<div style='color:$color'>
{$item['barcode']} ({$item['jumlah']}x)
</div>";
}
?>

<a href="?filter_date=<?= $date ?>" style="color:white; font-size:12px;">
➡ Lihat di tabel
</a>

</div>
<?php } ?>

</div>

<?php } ?>

</div>

</div>

<script>
function toggle(id){
var el = document.getElementById(id);
el.style.display = (el.style.display === "none") ? "block" : "none";
}
</script>

</body>
</html>