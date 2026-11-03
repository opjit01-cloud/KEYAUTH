FROM php:8.2-apache

# Install required system libraries and PHP extensions
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    libonig-dev \
    libcurl4-openssl-dev \
    zip \
    unzip \
    default-mysql-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd mysqli pdo_mysql mbstring zip curl bcmath \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Enable Apache Rewrite & Headers
RUN a2enmod rewrite headers

# Allow .htaccess overrides so URL routing works
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

WORKDIR /var/www/html
COPY . /var/www/html/

# 1. Create credentials.php (reads Aiven / Render MYSQL_URL and environment variables)
RUN cat << 'EOF' > /var/www/html/includes/credentials.php
<?php
error_reporting(0);
ini_set('display_errors', '0');

$databaseHost = getenv('DB_HOST') ?: (getenv('MYSQLHOST') ?: "localhost");
$databasePort = getenv('DB_PORT') ?: (getenv('MYSQLPORT') ?: "3306");
$databaseUsername = getenv('DB_USER') ?: (getenv('MYSQLUSER') ?: "root");
$databasePassword = getenv('DB_PASS') ?: (getenv('MYSQLPASSWORD') !== false ? getenv('MYSQLPASSWORD') : "");
$databaseName = getenv('DB_NAME') ?: (getenv('MYSQLDATABASE') ?: "defaultdb");
$mysqlRequireSSL = (getenv('DB_SSL') === 'true' || getenv('MYSQL_SSL') === 'true');

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

$logwebhook = "";
$adminwebhook = "";
$redisServers = [];
$redisPass = "";
$keyauthStatsToken = "";
$webhookun = "KeyAuth Logs";
$adminwebhookun = "KeyAuth Admin Logs";
$awsAccessKey = "";
$awsSecretKey = "";
EOF

# 2. Patch includes/redis.php to avoid fatal exit if Redis is missing
RUN cat << 'EOF' > /var/www/html/includes/redis.php
<?php
$docRoot = (isset($_SERVER['DOCUMENT_ROOT']) && ($_SERVER['DOCUMENT_ROOT'] == "/usr/share/nginx/html/panel" || $_SERVER['DOCUMENT_ROOT'] == "/usr/share/nginx/html/api")) ? "/usr/share/nginx/html" : ($_SERVER['DOCUMENT_ROOT'] ?? __DIR__ . '/..');
if (file_exists($docRoot . '/includes/credentials.php')) {
    include_once $docRoot . '/includes/credentials.php';
} else {
    include_once __DIR__ . '/credentials.php';
}

global $redis;
$redis = null;
EOF

# 3. Create auth/firebase-auth.js with DravixAuth credentials
RUN mkdir -p /var/www/html/auth && cat << 'EOF' > /var/www/html/auth/firebase-auth.js
const firebaseConfig = {
  apiKey: "AIzaSyATpueZ9kC6RTXAItsexhzf8e7KTjHzjMI",
  authDomain: "dravixauth.firebaseapp.com",
  projectId: "dravixauth",
  storageBucket: "dravixauth.firebasestorage.app",
  messagingSenderId: "882930624511",
  appId: "1:882930624511:web:4d992cd003765ca48ee9ca",
  measurementId: "G-TSFNRSC63N"
};

if (!firebase.apps.length) {
    firebase.initializeApp(firebaseConfig);
}

function handleGoogleLogin() {
    const btn = document.getElementById("google-signin-btn");
    const statusText = document.getElementById("google-signin-status");
    if (btn) btn.disabled = true;
    if (statusText) {
        statusText.style.display = "block";
        statusText.innerText = "Opening Google Sign-In...";
    }

    const provider = new firebase.auth.GoogleAuthProvider();
    provider.addScope('email');
    provider.addScope('profile');
    provider.setCustomParameters({ prompt: 'select_account' });

    firebase.auth().signInWithPopup(provider)
        .then((result) => {
            const user = result.user;
            if (statusText) statusText.innerText = "Authenticating with KeyAuth...";

            return fetch('../auth/google-login.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    email: user.email,
                    name: user.displayName,
                    photoURL: user.photoURL,
                    uid: user.uid
                })
            });
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                if (statusText) statusText.innerText = "Success! Redirecting...";
                window.location.href = data.redirect || '../app/';
            } else {
                alert("Login Error: " + (data.message || 'Unknown server error.'));
                if (btn) btn.disabled = false;
                if (statusText) statusText.innerText = "";
            }
        })
        .catch((error) => {
            console.error("Firebase Auth Error:", error);
            if (error.code === 'auth/unauthorized-domain') {
                alert("Firebase Domain Whitelist Required!\n\nPlease go to Firebase Console -> Authentication -> Settings -> Authorized domains -> click 'Add domain' and add:\n" + window.location.hostname);
            } else if (error.code === 'auth/popup-blocked') {
                alert("Popup was blocked by your browser! Please allow popups for this site and try again.");
            } else if (error.code !== 'auth/popup-closed-by-user') {
                alert("Google Sign-In Error (" + error.code + "): " + error.message);
            }
            if (btn) btn.disabled = false;
            if (statusText) statusText.innerText = "";
        });
}
EOF

# 4. Create auth/google-login.php endpoint
RUN cat << 'EOF' > /var/www/html/auth/google-login.php
<?php
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
$ip = api\shared\primary\getIp();

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
    $ownerid = misc\etc\generateRandomString();

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
        echo json_encode(['success' => false, 'message' => 'Failed to create user account.']);
        exit();
    }
}
EOF

# 5. Ensure permissions
RUN chown -R www-data:www-data /var/www/html && chmod -R 755 /var/www/html

EXPOSE 80

# Dynamically set port at startup for Render
CMD ["sh", "-c", "PORT=${PORT:-80}; sed -i \"s/Listen 80/Listen $PORT/\" /etc/apache2/ports.conf && sed -i \"s/<VirtualHost \\*:80>/<VirtualHost \\*:$PORT>/\" /etc/apache2/sites-available/000-default.conf && exec apache2-foreground"]
