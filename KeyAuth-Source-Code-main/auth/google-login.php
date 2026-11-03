<?php
error_reporting(0);
ini_set('display_errors', '0');

require_once __DIR__ . '/../includes/misc/autoload.phtml';
require_once __DIR__ . '/../includes/dashboard/autoload.phtml';
require_once __DIR__ . '/../includes/api/shared/autoload.phtml';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json');

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (!$data || empty($data['email'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid Google authentication payload.']);
    exit();
}

$email = strtolower(trim($data['email']));
$name = trim($data['name'] ?? '');
$photo = trim($data['photoURL'] ?? 'https://cdn.keyauth.cc/front/assets/img/favicon.png');
$ip = function_exists('api\shared\primary\getIp') ? api\shared\primary\getIp() : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

try {
    $query = misc\mysql\query("SELECT * FROM `accounts` WHERE `email` = SHA1(?)", [$email]);

    if ($query && $query->num_rows > 0) {
        $row = mysqli_fetch_array($query->result);
        if (!empty($row['banned'])) {
            echo json_encode(['success' => false, 'message' => 'Account is banned: ' . $row['banned']]);
            exit();
        }
        $_SESSION['username'] = $row['username'];
        $_SESSION['ownerid'] = $row['ownerid'];
        $_SESSION['role'] = $row['role'];
        $_SESSION['logindate'] = time();
        $_SESSION['img'] = !empty($row['img']) ? $row['img'] : $photo;
        echo json_encode(['success' => true, 'redirect' => '../app/']);
        exit();
    } else {
        $baseUsername = preg_replace('/[^a-zA-Z0-9_]/', '', explode('@', $email)[0]);
        if (empty($baseUsername)) { $baseUsername = 'user_' . substr(md5($email), 0, 6); }
        $username = $baseUsername;
        $checkUser = misc\mysql\query("SELECT 1 FROM `accounts` WHERE `username` = ?", [$username]);
        if ($checkUser && $checkUser->num_rows > 0) {
            $username = $baseUsername . '_' . rand(100, 9999);
        }
        $randomPass = bin2hex(random_bytes(16));
        $passEncrypted = password_hash($randomPass, PASSWORD_BCRYPT);
        $ownerid = function_exists('misc\etc\generateRandomString') ? misc\etc\generateRandomString() : bin2hex(random_bytes(8));

        $insert = misc\mysql\query(
            "INSERT INTO `accounts` (`username`, `email`, `password`, `ownerid`, `role`, `registrationip`, `img`) VALUES (?, SHA1(?), ?, ?, 'tester', ?, ?)",
            [$username, $email, $passEncrypted, $ownerid, $ip, $photo]
        );

        if ($insert) {
            $_SESSION['username'] = $username;
            $_SESSION['ownerid'] = $ownerid;
            $_SESSION['role'] = 'tester';
            $_SESSION['logindate'] = time();
            $_SESSION['img'] = $photo;
            echo json_encode(['success' => true, 'redirect' => '../app/']);
            exit();
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to create user account in database.']);
            exit();
        }
    }
} catch (\Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    exit();
}
