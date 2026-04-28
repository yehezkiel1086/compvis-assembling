<?php

// CRITICAL: Set memory limit BEFORE anything else
ini_set('memory_limit', '1024M');
ini_set('max_execution_time', 300);

// Disable error display for clean PDF output
error_reporting(0);
ini_set('display_errors', 0);

require_once 'dompdf/autoload.inc.php';

use Dompdf\Dompdf;

$serverName = "10.19.16.21";
$database   = "prod_control";
$username   = "sql_pre";
$password   = "User@eng1";

$conn = new PDO(
    "sqlsrv:Server=$serverName;Database=$database;Encrypt=no;TrustServerCertificate=yes",
    $username,
    $password
);

# ================= FILTER =================
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

# Count total rows separately
$count_query = "SELECT COUNT(*) FROM assy_vision $whereSQL";
$total_count = $conn->query($count_query)->fetchColumn();

# Limit data for PDF to prevent memory issues
$max_rows = 200; // Limit to 200 rows for PDF (DomPDF is memory intensive)
$limited_total = min($total_count, $max_rows);

# ================= QUERY =================
$query = "
SELECT TOP $max_rows
created_at,
barcode,
jenis,
type_pole,
pole,
sticker,
type_battery,
emboss,
datecode,
status
FROM assy_vision
$whereSQL
ORDER BY created_at DESC
";

$stmt = $conn->query($query);

# Build HTML table with streaming approach
ob_start();
echo '
<h2 style="text-align:center;">DATA HASIL INSPEKSI</h2>

<p><b>Tanggal:</b> '.($selected_date ?: 'Semua').'</p>
<p><b>Shift:</b> '.($shift ?: 'Semua').'</p>
<p><b>Total Data:</b> '.$total_count.' (PDF limited to '.$limited_total.' records)</p>

<table border="1" cellpadding="5" cellspacing="0" width="100%">
<tr style="background:#ccc;">
<th>Time</th>
<th>Barcode</th>
<th>Jenis</th>
<th>Type Pole</th>
<th>Pole</th>
<th>Sticker</th>
<th>Type Battery</th>
<th>Emboss</th>
<th>Datecode</th>
<th>Status</th>
</tr>
';

# Stream data row by row
while($row = $stmt->fetch(PDO::FETCH_ASSOC)){
    echo '
    <tr>
        <td>'.htmlspecialchars($row['created_at']).'</td>
        <td>'.htmlspecialchars($row['barcode']).'</td>
        <td>'.htmlspecialchars($row['jenis']).'</td>
        <td>'.htmlspecialchars($row['type_pole']).'</td>
        <td>'.htmlspecialchars($row['pole']).'</td>
        <td>'.htmlspecialchars($row['sticker']).'</td>
        <td>'.htmlspecialchars($row['type_battery']).'</td>
        <td>'.htmlspecialchars($row['emboss']).'</td>
        <td>'.htmlspecialchars($row['datecode']).'</td>
        <td>'.htmlspecialchars($row['status']).'</td>
    </tr>
    ';
}

echo '</table>';

$html = ob_get_clean();

# ================= GENERATE PDF =================
$dompdf = new Dompdf();
$dompdf->loadHtml($html);

# landscape biar muat banyak kolom
$dompdf->setPaper('A4', 'landscape');

$dompdf->render();
$dompdf->stream("Export_Assy_".date('Ymd_His').".pdf", ["Attachment" => true]);

?>