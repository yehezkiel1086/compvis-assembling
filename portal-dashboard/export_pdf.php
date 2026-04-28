<?php

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

# ================= QUERY =================
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
status
FROM assy_vision
$whereSQL
ORDER BY created_at DESC
";

$data = $conn->query($query)->fetchAll(PDO::FETCH_ASSOC);

# ================= HTML PDF =================
$html = '
<h2 style="text-align:center;">DATA HASIL INSPEKSI</h2>

<p><b>Tanggal:</b> '.($selected_date ?: 'Semua').'</p>
<p><b>Shift:</b> '.($shift ?: 'Semua').'</p>
<p><b>Total Data:</b> '.count($data).'</p>

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

foreach($data as $row){
    $html .= '
    <tr>
        <td>'.$row['created_at'].'</td>
        <td>'.$row['barcode'].'</td>
        <td>'.$row['jenis'].'</td>
        <td>'.$row['type_pole'].'</td>
        <td>'.$row['pole'].'</td>
        <td>'.$row['sticker'].'</td>
        <td>'.$row['type_battery'].'</td>
        <td>'.$row['emboss'].'</td>
        <td>'.$row['datecode'].'</td>
        <td>'.$row['status'].'</td>
    </tr>
    ';
}

$html .= '</table>';

# ================= GENERATE PDF =================
$dompdf = new Dompdf();
$dompdf->loadHtml($html);

# landscape biar muat banyak kolom
$dompdf->setPaper('A4', 'landscape');

$dompdf->render();
$dompdf->stream("Export_Assy_".date('Ymd_His').".pdf", ["Attachment" => true]);

?>