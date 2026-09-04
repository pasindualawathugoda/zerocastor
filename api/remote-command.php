<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $user = requireLogin($pdo);

    $tvCode = preg_replace('/[^A-Z0-9]/', '', strtoupper((string)($_POST['tv_code'] ?? ''))) ?? '';
    $command = trim((string)($_POST['command'] ?? ''));
    $payload = trim((string)($_POST['payload'] ?? ''));

    if ($tvCode === '' || $command === '') {
        throw new RuntimeException('Missing TV code or command');
    }

    $allowed = ['channel', 'next', 'prev', 'volume_up', 'volume_down', 'mute', 'fullscreen'];

    if (!in_array($command, $allowed, true)) {
        throw new RuntimeException('Unsupported command');
    }

    $stmt = $pdo->prepare("SELECT * FROM tv_devices WHERE device_code = ? LIMIT 1");
    $stmt->execute([$tvCode]);
    $tv = $stmt->fetch();

    if (!$tv) {
        throw new RuntimeException('TV not found');
    }

    $insert = $pdo->prepare("
        INSERT INTO remote_commands (tv_device_id, user_id, command_name, payload_text, is_consumed)
        VALUES (?, ?, ?, ?, 0)
    ");
    $insert->execute([
        (int)$tv['id'],
        (int)$user['id'],
        $command,
        $payload !== '' ? $payload : null
    ]);

    echo json_encode(['status' => 'ok']);
} catch (Throwable $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}