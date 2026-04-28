<?php
$serverName = "10.19.16.21,1433";   // WAJIB tambahkan port
$database   = "prod_control";
$username   = "sql_pre";
$password   = "User@eng2";

try {
    $dsn = "sqlsrv:Server=$serverName;Database=$database;Encrypt=true;TrustServerCertificate=true";

    $conn = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

} catch (PDOException $e) {
    die("Koneksi DB gagal: " . $e->getMessage());
}
