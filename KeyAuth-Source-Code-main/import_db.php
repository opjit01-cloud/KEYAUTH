<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

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
    die("Connection failed: " . $conn->connect_error);
}

echo "Connected successfully to " . htmlspecialchars($databaseName) . " on " . htmlspecialchars($host) . "<br>";

$sqlFile = __DIR__ . '/db_structure.sql';
if (!file_exists($sqlFile)) {
    die("db_structure.sql not found!");
}

$sql = file_get_contents($sqlFile);

if ($conn->multi_query($sql)) {
    do {
        if ($res = $conn->store_result()) {
            $res->free();
        }
    } while ($conn->more_results() && $conn->next_result());
    echo "Multi-query executed successfully!<br>";
} else {
    echo "Multi-query error: " . htmlspecialchars($conn->error) . "<br>";
}

$res = $conn->query("SHOW TABLES;");
echo "<h3>Current Tables in Database (" . $res->num_rows . "):</h3><ul>";
while ($row = $res->fetch_array()) {
    echo "<li>" . htmlspecialchars($row[0]) . "</li>";
}
echo "</ul>";
$conn->close();
?>
