<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $user = getLoggedInUser($pdo);
    $tv = getOrCreateTvDevice($pdo, $user['id'] ?? null);

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'poll') {
        $stmt = $pdo->prepare("
            SELECT id, command_name, payload_text
            FROM remote_commands
            WHERE tv_device_id = ? AND is_consumed = 0
            ORDER BY id ASC
            LIMIT 30
        ");
        $stmt->execute([(int)$tv['id']]);
        $commands = $stmt->fetchAll();

        if ($commands) {
            $ids = array_map(fn($r) => (int)$r['id'], $commands);
            $pdo->exec("UPDATE remote_commands SET is_consumed = 1, consumed_at = NOW() WHERE id IN (" . implode(',', $ids) . ")");
        }

        echo json_encode([
            'status' => 'ok',
            'tv_device_id' => (int)$tv['id'],
            'device_code' => $tv['device_code'],
            'commands' => $commands,
        ]);
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'heartbeat') {
        $stmt = $pdo->prepare("UPDATE tv_devices SET is_online = 1, last_seen_at = NOW() WHERE id = ?");
        $stmt->execute([(int)$tv['id']]);

        echo json_encode([
            'status' => 'ok',
            'device_code' => $tv['device_code']
        ]);
        exit;
    }

    if ($action === 'set_channel') {
        $channelId = (int)($_POST['channel_id'] ?? 0);
        if ($channelId <= 0) {
            throw new RuntimeException('Invalid channel');
        }

        $stmt = $pdo->prepare("UPDATE tv_devices SET current_channel_id = ?, last_seen_at = NOW() WHERE id = ?");
        $stmt->execute([$channelId, (int)$tv['id']]);

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