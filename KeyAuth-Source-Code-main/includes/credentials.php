<?php
/*
All sensitive credentials stored here.
Supports local hosting, Aiven Cloud, Render, Railway, and Docker.
*/

error_reporting(0);

$databaseHost = getenv('DB_HOST') ?: (getenv('MYSQLHOST') ?: "localhost");
$databasePort = getenv('DB_PORT') ?: (getenv('MYSQLPORT') ?: "3306");
$databaseUsername = getenv('DB_USER') ?: (getenv('MYSQLUSER') ?: "root");
$databasePassword = getenv('DB_PASS') ?: (getenv('MYSQLPASSWORD') !== false ? getenv('MYSQLPASSWORD') : "");
$databaseName = getenv('DB_NAME') ?: (getenv('MYSQLDATABASE') ?: "keyauth");
$mysqlRequireSSL = (getenv('DB_SSL') === 'true' || getenv('MYSQL_SSL') === 'true');

// If MYSQL_URL or DATABASE_URL is provided (e.g. from Aiven or Render/Railway)
$urlEnv = getenv('MYSQL_URL') ?: getenv('DATABASE_URL');
if (!empty($urlEnv)) {
    $dbUrl = parse_url($urlEnv);
    if (!empty($dbUrl['host'])) {
        $databaseHost = $dbUrl['host'];
        $databasePort = !empty($dbUrl['port']) ? $dbUrl['port'] : $databasePort;
        $databaseUsername = $dbUrl['user'] ?? $databaseUsername;
        $databasePassword = $dbUrl['pass'] ?? $databasePassword;
        $databaseName = ltrim($dbUrl['path'], '/') ?: $databaseName;
    }
    if (strpos($urlEnv, 'ssl-mode=REQUIRED') !== false || strpos($databaseHost, 'aivencloud.com') !== false) {
        $mysqlRequireSSL = true;
    }
}

if (strpos($databaseHost, 'aivencloud.com') !== false) {
    $mysqlRequireSSL = true;
}

$logwebhook = getenv('LOG_WEBHOOK') ?: "";
$adminwebhook = getenv('ADMIN_WEBHOOK') ?: "";
$redisServers = [];
$redisPass = getenv('REDIS_PASSWORD') ?: "";
$keyauthStatsToken = "";
$webhookun = "KeyAuth Logs";
$adminwebhookun = "KeyAuth Admin Logs";
$awsAccessKey = "";
$awsSecretKey = "";
