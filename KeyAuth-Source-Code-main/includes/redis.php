<?php
$docRoot = (isset($_SERVER['DOCUMENT_ROOT']) && ($_SERVER['DOCUMENT_ROOT'] == "/usr/share/nginx/html/panel" || $_SERVER['DOCUMENT_ROOT'] == "/usr/share/nginx/html/api")) ? "/usr/share/nginx/html" : ($_SERVER['DOCUMENT_ROOT'] ?? __DIR__ . '/..');
if (file_exists($docRoot . '/includes/credentials.php')) {
    include_once $docRoot . '/includes/credentials.php';
} else {
    include_once __DIR__ . '/credentials.php';
}

global $redis;

if (!class_exists('Redis')) {
    $redis = null;
} else {
    try {
        $redis = new Redis();
        $redis->connect('127.0.0.1', 6379, 1.5);
        global $redisPass;
        if(!empty($redisPass)) {
            $redis->auth($redisPass);
        }
    } catch (\Throwable $e) {
        $redis = null;
    }
}
