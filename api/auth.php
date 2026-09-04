<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_POST['action'] ?? '';

try {
    if ($action === 'register') {
        $name = trim((string)($_POST['name'] ?? ''));
        $mobile = normalizeMobile((string)($_POST['mobile'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        if ($name === '' || $mobile === '' || $password === '') {
            throw new RuntimeException('All fields are required');
        }

        if (strlen($mobile) < 9) {
            throw new RuntimeException('Enter valid mobile number');
        }

        if (strlen($password) < 4) {
            throw new RuntimeException('Password too short');
        }

        $check = $pdo->prepare("SELECT id FROM users WHERE mobile = ? LIMIT 1");
        $check->execute([$mobile]);
        if ($check->fetch()) {
            throw new RuntimeException('Mobile number already registered');
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (mobile, password_hash, display_name) VALUES (?, ?, ?)");
        $stmt->execute([$mobile, $hash, $name]);

        $userId = (int)$pdo->lastInsertId();
        $_SESSION['user_id'] = $userId;
        createRememberLogin($pdo, $userId);

        echo json_encode(['status' => 'ok']);
        exit;
    }

    if ($action === 'login') {
        $mobile = normalizeMobile((string)($_POST['mobile'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        $stmt = $pdo->prepare("SELECT * FROM users WHERE mobile = ? LIMIT 1");
        $stmt->execute([$mobile]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            throw new RuntimeException('Invalid mobile or password');
        }

        $_SESSION['user_id'] = (int)$user['id'];
        createRememberLogin($pdo, (int)$user['id']);

        echo json_encode(['status' => 'ok']);
        exit;
    }

    if ($action === 'logout') {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        if (!empty($_COOKIE['zerocast_remember'])) {
            [$selector] = explode(':', $_COOKIE['zerocast_remember'], 2);
            $stmt = $pdo->prepare("DELETE FROM user_tokens WHERE selector = ?");
            $stmt->execute([$selector]);
        }

        setcookie('zerocast_remember', '', time() - 3600, '/');
        echo json_encode(['status' => 'ok']);
        exit;
    }

    throw new RuntimeException('Invalid action');
} catch (Throwable $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}