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
    die("Connection error: " . $conn->connect_error);
}

$possiblePaths = [
    __DIR__ . '/db_structure.sql',
    __DIR__ . '/KeyAuth-Source-Code-main/db_structure.sql',
    '/var/www/html/db_structure.sql',
    '/var/www/html/KeyAuth-Source-Code-main/db_structure.sql'
];

$sqlFile = null;
foreach ($possiblePaths as $p) {
    if (file_exists($p)) {
        $sqlFile = $p;
        break;
    }
}

if (!$sqlFile) {
    die("db_structure.sql not found in any path!");
}

echo "Found SQL file at: $sqlFile (" . filesize($sqlFile) . " bytes)<br>";

$sql = file_get_contents($sqlFile);
$queries = preg_split('/;\s*[\r\n]+/', $sql);

$created = 0;
foreach ($queries as $q) {
    $q = trim($q);
    if (!empty($q)) {
        if ($conn->query($q)) {
            $created++;
        } else {
            echo "Query warning: " . htmlspecialchars($conn->error) . "<br>";
        }
    }
}

echo "<b>SUCCESS: Executed $created queries!</b><br>";

$res = $conn->query("SHOW TABLES;");
echo "<h3>Total Tables in Database: " . $res->num_rows . "</h3>";
while ($r = $res->fetch_array()) {
    echo $r[0] . " | ";
}
$conn->close();
?>
