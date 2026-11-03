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
    if (statusText) statusText.innerText = "Connecting to Google...";

    const provider = new firebase.auth.GoogleAuthProvider();
    provider.addScope('email');
    provider.addScope('profile');

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
                alert(data.message || 'Login failed.');
                if (btn) btn.disabled = false;
                if (statusText) statusText.innerText = "";
            }
        })
        .catch((error) => {
            if (error.code !== 'auth/popup-closed-by-user') {
                alert("Google Sign-In Error: " + error.message);
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

# 5. Inject Google Sign-in button & Firebase scripts into login and register pages
RUN cat << 'EOF' > /tmp/inject.php
<?php
$button = '
<div style="margin: 15px 0; text-align: center; display: flex; align-items: center; justify-content: center; gap: 10px;">
    <div style="flex-grow: 1; border-top: 1px solid #374151;"></div>
    <span style="color: #9CA3AF; font-size: 12px; text-transform: uppercase;">or continue with</span>
    <div style="flex-grow: 1; border-top: 1px solid #374151;"></div>
</div>
<button type="button" id="google-signin-btn" onclick="handleGoogleLogin()" style="width: 100%; display: flex; align-items: center; justify-content: center; gap: 10px; padding: 10px 15px; background: #13131a; border: 1px solid #374151; border-radius: 8px; color: white; font-size: 14px; cursor: pointer; margin-bottom: 12px;">
    <svg width="18" height="18" viewBox="0 0 24 24"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z"/></svg>
    <span>Sign in with Google</span>
</button>
<div id="google-signin-status" style="font-size: 12px; text-align: center; color: #60A5FA; margin-bottom: 8px;"></div>
';

$scripts = '
<script src="https://www.gstatic.com/firebasejs/9.23.0/firebase-app-compat.js"></script>
<script src="https://www.gstatic.com/firebasejs/9.23.0/firebase-auth-compat.js"></script>
<script src="../auth/firebase-auth.js"></script>
';

foreach (["/var/www/html/login/index.php", "/var/www/html/register/index.php"] as $file) {
    if (file_exists($file)) {
        $c = file_get_contents($file);
        if (strpos($c, "google-signin-btn") === false) {
            $c = str_replace("</form>", $button . "\n</form>", $c);
            $c = str_replace("</body>", $scripts . "\n</body>", $c);
            file_put_contents($file, $c);
        }
    }
}
EOF
RUN php /tmp/inject.php && rm -f /tmp/inject.php

# 6. Ensure permissions
RUN chown -R www-data:www-data /var/www/html && chmod -R 755 /var/www/html

EXPOSE 80

# Dynamically set port at startup for Render
CMD ["sh", "-c", "PORT=${PORT:-80}; sed -i \"s/Listen 80/Listen $PORT/\" /etc/apache2/ports.conf && sed -i \"s/<VirtualHost \\*:80>/<VirtualHost \\*:$PORT>/\" /etc/apache2/sites-available/000-default.conf && exec apache2-foreground"]
