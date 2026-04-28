<?php

// Disable error display for clean Excel output
error_reporting(0);
ini_set('display_errors', 0);

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

$stmt = $conn->query($query);

# Count total rows separately
$count_query = "SELECT COUNT(*) FROM assy_vision $whereSQL";
$total_count = $conn->query($count_query)->fetchColumn();

# ================= EXPORT =================
header('Content-Type: text/html');
header('Content-Disposition: attachment; filename=Export_Assy_'.date('Ymd_His').'.xls');
header('Cache-Control: max-age=0');
header('Pragma: public');

echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
echo '<head>';
echo '<meta http-equiv="Content-type" content="text/html;charset=utf-8" />';
echo '<style>';
echo 'table { border-collapse: collapse; width: 100%; }';
echo 'td { border: 1px solid #ccc; padding: 5px; }';
echo '.header { background-color: #e0e0e0; font-weight: bold; }';
echo '.title { background-color: #f0f0f0; font-weight: bold; text-align: center; }';
echo '</style>';
echo '</head>';
echo '<body>';
echo '<table border="1">';

# INFO SECTION
echo '<tr><td colspan="11" style="font-weight:bold; text-align:center; background-color:#f0f0f0;">EXPORT DATA ASSY</td></tr>';
echo '<tr><td colspan="2"><strong>Tanggal</strong></td><td colspan="9">' . htmlspecialchars($selected_date ?: 'Semua') . '</td></tr>';
echo '<tr><td colspan="2"><strong>Shift</strong></td><td colspan="9">' . htmlspecialchars($shift ?: 'Semua') . '</td></tr>';
echo '<tr><td colspan="2"><strong>Total Data</strong></td><td colspan="9">' . $total_count . '</td></tr>';
echo '<tr><td colspan="11">&nbsp;</td></tr>';

# HEADER
echo '<tr style="background-color:#e0e0e0; font-weight:bold;">';
echo '<td>TIME</td>';
echo '<td>BARCODE</td>';
echo '<td>JENIS</td>';
echo '<td>TYPE POLE</td>';
echo '<td>POLE</td>';
echo '<td>STICKER</td>';
echo '<td>TYPE BATTERY</td>';
echo '<td>EMBOSS</td>';
echo '<td>DATECODE</td>';
echo '<td>STATUS</td>';
echo '<td>IMAGE PATH</td>';
echo '</tr>';

# DATA - Stream results one row at a time
while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo '<tr>';
    echo '<td>' . htmlspecialchars($row['created_at']) . '</td>';
    echo '<td>' . htmlspecialchars($row['barcode']) . '</td>';
    echo '<td>' . htmlspecialchars($row['jenis']) . '</td>';
    echo '<td>' . htmlspecialchars($row['type_pole']) . '</td>';
    echo '<td>' . htmlspecialchars($row['pole']) . '</td>';
    echo '<td>' . htmlspecialchars($row['sticker']) . '</td>';
    echo '<td>' . htmlspecialchars($row['type_battery']) . '</td>';
    echo '<td>' . htmlspecialchars($row['emboss']) . '</td>';
    echo '<td>' . htmlspecialchars($row['datecode']) . '</td>';
    echo '<td>' . htmlspecialchars($row['status']) . '</td>';
    echo '<td>' . strip_tags($row['image_path']) . '</td>';
    echo '</tr>';
}

echo '</table>';
echo '</body>';
echo '</html>';
exit;