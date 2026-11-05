<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$url = (string)($config['sheets']['webhook_url'] ?? '');
$secret = (string)($config['sheets']['webhook_secret'] ?? '');
if ($url === '' || $secret === '') {
    exit("Google Sheets sync is not configured.\n");
}
$rows = $pdo->query("SELECT * FROM sync_queue WHERE synced_at IS NULL AND attempts < 8 ORDER BY id ASC LIMIT 25")->fetchAll();
foreach ($rows as $row) {
    $body = json_encode(['id' => $row['id'], 'entity_type' => $row['entity_type'], 'entity_id' => $row['entity_id'], 'payload' => json_decode($row['payload'], true)], JSON_UNESCAPED_UNICODE);
    $target = $url . (str_contains($url, '?') ? '&' : '?') . 'signature=' . rawurlencode(hash_hmac('sha256', $body, $secret));
    $ch = curl_init($target);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Due-Flow-Signature: ' . hash_hmac('sha256', $body, $secret)], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
    $response = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($status >= 200 && $status < 300 && $response !== false) {
        $stmt = $pdo->prepare('UPDATE sync_queue SET synced_at = ? WHERE id = ?');
        $stmt->execute([$now(), $row['id']]);
    } else {
        $stmt = $pdo->prepare('UPDATE sync_queue SET attempts = attempts + 1, last_error = ? WHERE id = ?');
        $stmt->execute([$error !== '' ? $error : 'HTTP ' . $status, $row['id']]);
    }
}
echo "Processed " . count($rows) . " sync records.\n";
