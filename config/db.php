<?php
declare(strict_types=1);

session_start();

define('DB_HOST', 'localhost');
define('DB_NAME', 'u833894165_viudesktop');
define('DB_USER', 'u833894165_viudesktop');
define('DB_PASS', 'Dheer@96');
define('BASE_URL', 'https://zerocastor.com/desktop');

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (Throwable $e) {
    http_response_code(500);
    exit('Database connection failed');
}

function getSetting(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT * FROM settings ORDER BY id ASC LIMIT 1");
    $row = $stmt->fetch();
    return is_array($row) ? $row : [];
}

function fetchJsonUrl(string $url): ?array
{
    if ($url === '') {
        return null;
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: Mozilla/5.0 ZerocastTV'
            ],
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
    } else {
        $body = @file_get_contents($url);
    }

    if ($body === false || $body === null) {
        return null;
    }

    $json = json_decode($body, true);
    return is_array($json) ? $json : null;
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function normalizeMobile(string $mobile): string
{
    return preg_replace('/\D+/', '', trim($mobile)) ?? '';
}

function createRememberLogin(PDO $pdo, int $userId): void
{
    $selector = bin2hex(random_bytes(8));
    $token = bin2hex(random_bytes(32));
    $tokenHash = password_hash($token, PASSWORD_DEFAULT);
    $expiresAt = date('Y-m-d H:i:s', strtotime('+180 days'));

    $stmt = $pdo->prepare("
        INSERT INTO user_tokens (user_id, selector, token_hash, expires_at)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$userId, $selector, $tokenHash, $expiresAt]);

    setcookie(
        'zerocast_remember',
        $selector . ':' . $token,
        [
            'expires' => strtotime($expiresAt),
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]
    );
}

function getLoggedInUser(PDO $pdo): ?array
{
    if (!empty($_SESSION['user_id'])) {
        $stmt = $pdo->prepare("SELECT id, mobile, display_name FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$_SESSION['user_id']]);
        $user = $stmt->fetch();
        if ($user) {
            return $user;
        }
    }

    $cookie = $_COOKIE['zerocast_remember'] ?? '';
    if ($cookie === '' || strpos($cookie, ':') === false) {
        return null;
    }

    [$selector, $token] = explode(':', $cookie, 2);

    $stmt = $pdo->prepare("
        SELECT ut.*, u.mobile, u.display_name
        FROM user_tokens ut
        INNER JOIN users u ON u.id = ut.user_id
        WHERE ut.selector = ? AND ut.expires_at > NOW()
        LIMIT 1
    ");
    $stmt->execute([$selector]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    if (!password_verify($token, $row['token_hash'])) {
        return null;
    }

    $_SESSION['user_id'] = (int)$row['user_id'];

    return [
        'id' => (int)$row['user_id'],
        'mobile' => $row['mobile'],
        'display_name' => $row['display_name'],
    ];
}

function requireLogin(PDO $pdo): array
{
    $user = getLoggedInUser($pdo);
    if (!$user) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => 'error',
            'message' => 'Login required'
        ]);
        exit;
    }
    return $user;
}

function getOrCreateTvDevice(PDO $pdo, ?int $userId = null): array
{
    if (empty($_COOKIE['zerocast_tv_code'])) {
        $deviceCode = strtoupper(bin2hex(random_bytes(4)));
        setcookie(
            'zerocast_tv_code',
            $deviceCode,
            [
                'expires' => strtotime('+365 days'),
                'path' => '/',
                'secure' => true,
                'httponly' => false,
                'samesite' => 'Lax',
            ]
        );
        $_COOKIE['zerocast_tv_code'] = $deviceCode;
    }

    $deviceCode = preg_replace('/[^A-Z0-9]/', '', strtoupper($_COOKIE['zerocast_tv_code'])) ?? '';

    $stmt = $pdo->prepare("SELECT * FROM tv_devices WHERE device_code = ? LIMIT 1");
    $stmt->execute([$deviceCode]);
    $device = $stmt->fetch();

    if (!$device) {
        $insert = $pdo->prepare("
            INSERT INTO tv_devices (user_id, device_code, device_name, current_channel_id, is_online, last_seen_at)
            VALUES (?, ?, 'Living Room TV', 2, 1, NOW())
        ");
        $insert->execute([$userId, $deviceCode]);

        $stmt->execute([$deviceCode]);
        $device = $stmt->fetch();
    } else {
        $update = $pdo->prepare("
            UPDATE tv_devices
            SET user_id = COALESCE(?, user_id), is_online = 1, last_seen_at = NOW()
            WHERE id = ?
        ");
        $update->execute([$userId, (int)$device['id']]);

        $stmt->execute([$deviceCode]);
        $device = $stmt->fetch();
    }

    return $device;
}