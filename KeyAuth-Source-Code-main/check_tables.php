<?php
require_once __DIR__ . '/includes/credentials.php';

$port = !empty($databasePort) ? intval($databasePort) : 3306;
$host = $databaseHost;
if (strpos($host, ':') !== false) {
    $parts = explode(':', $host);
    $host = $parts[0];
    $port = intval($parts[1]);
}

$conn = new mysqli();
$flags = 0;
if ($mysqlRequireSSL) {
    $conn->ssl_set(NULL, NULL, NULL, NULL, NULL);
    $flags = MYSQLI_CLIENT_SSL;
}

$conn->real_connect($host, $databaseUsername, $databasePassword, $databaseName, $port, NULL, $flags);
if ($conn->connect_error) { die("DB Error: " . $conn->connect_error); }

$res = $conn->query("SHOW TABLES;");
echo "TABLES COUNT: " . $res->num_rows . "\n";
while ($row = $res->fetch_array()) {
    echo "- " . $row[0] . "\n";
}
?>
