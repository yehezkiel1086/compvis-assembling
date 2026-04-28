<?php

$serverName = "10.19.16.21";
$database   = "prod_control";
$username   = "sql_pre";
$password   = "User@eng1";

$conn = new PDO(
    "sqlsrv:Server=$serverName;Database=$database;Encrypt=no;TrustServerCertificate=yes",
    $username,
    $password
);

$selected_date = $_GET['filter_date'] ?? '';
$shift = $_GET['shift'] ?? '';

$where = [];

if($selected_date != ''){
    $where[] = "CAST(created_at AS DATE) = '$selected_date'";
}

if($shift == "1"){
    $where[] = "CAST(created_at AS TIME) BETWEEN '07:00:00' AND '16:00:00'";
}
elseif($shift == "2"){
    $where[] = "CAST(created_at AS TIME) BETWEEN '16:00:01' AND '23:59:59'";
}
elseif($shift == "3"){
    $where[] = "CAST(created_at AS TIME) BETWEEN '00:00:00' AND '06:59:59'";
}

$whereSQL = count($where) ? "WHERE ".implode(" AND ", $where) : "";

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
status,
image_path
FROM assy_vision
$whereSQL
ORDER BY created_at DESC
";

$data = $conn->query($query)->fetchAll(PDO::FETCH_ASSOC);

# ================= EXPORT =================
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=Export_Assy_'.date('Ymd_His').'.csv');

$output = fopen('php://output', 'w');

# 🔥 FIX EXCEL INDONESIA (PAKAI ;)
$delimiter = ";";

# INFO
fputcsv($output, ['EXPORT DATA ASSY'], $delimiter);
fputcsv($output, ['Tanggal', $selected_date ?: 'Semua'], $delimiter);
fputcsv($output, ['Shift', $shift ?: 'Semua'], $delimiter);
fputcsv($output, ['Total Data', count($data)], $delimiter);
fputcsv($output, [], $delimiter);

# HEADER
fputcsv($output, [
'TIME',
'BARCODE',
'JENIS',
'TYPE POLE',
'POLE',
'STICKER',
'TYPE BATTERY',
'EMBOSS',
'DATECODE',
'STATUS',
'IMAGE PATH'
], $delimiter);

# DATA
foreach($data as $row){
    fputcsv($output, [
        $row['created_at'],
        $row['barcode'],
        $row['jenis'],
        $row['type_pole'],
        $row['pole'],
        $row['sticker'],
        $row['type_battery'],
        $row['emboss'],
        $row['datecode'],
        $row['status'],
        $row['image_path']
    ], $delimiter);
}

fclose($output);
exit;