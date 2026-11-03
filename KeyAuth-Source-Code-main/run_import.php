<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Prevent PHP 8.2 from dying on query errors
mysqli_report(MYSQLI_REPORT_OFF);

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
if ($conn->connect_error) {
    die("Connection error: " . $conn->connect_error);
}

$sqlFile = __DIR__ . '/db_structure.sql';
if (!file_exists($sqlFile)) {
    die("db_structure.sql not found!");
}

$sql = file_get_contents($sqlFile);
// Clean sql comments and transactions
$sql = preg_replace('/^\/\*!.*?\*\/;/m', '', $sql);

$queries = explode(";\n", str_replace("\r\n", "\n", $sql));

$success = 0;
$fail = 0;

foreach ($queries as $q) {
    $q = trim($q);
    if (!empty($q)) {
        if ($conn->query($q)) {
            $success++;
        } else {
            $fail++;
            echo "Query Notice: " . htmlspecialchars($conn->error) . "<br>";
        }
    }
}

$conn->query("COMMIT;");

$res = $conn->query("SHOW TABLES;");
echo "<h2>TOTAL TABLES IN DATABASE: " . $res->num_rows . "</h2><ul>";
while ($r = $res->fetch_array()) {
    echo "<li>" . htmlspecialchars($r[0]) . "</li>";
}
echo "</ul>";
$conn->close();
?>
