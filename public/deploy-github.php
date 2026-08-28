<?php

declare(strict_types=1);
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'method_not_allowed']);
    exit;
}
$secret = trim((string) @file_get_contents(__DIR__.'/../storage/app/.webhook-secret'));
$payload = (string) file_get_contents('php://input');
$expected = 'sha256='.hash_hmac('sha256', $payload, $secret);
if ($secret === '' || ! hash_equals($expected, (string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? ''))) {
    http_response_code(401);
    echo json_encode(['status' => 'invalid_signature']);
    exit;
}
$event = (string) ($_SERVER['HTTP_X_GITHUB_EVENT'] ?? '');
if ($event === 'ping') {
    echo json_encode(['status' => 'pong']);
    exit;
}
$data = json_decode($payload, true);
if ($event !== 'push' || ($data['ref'] ?? '') !== 'refs/heads/main') {
    http_response_code(202);
    echo json_encode(['status' => 'ignored']);
    exit;
}
http_response_code(202);
echo json_encode(['status' => 'deployment_queued']);
function_exists('fastcgi_finish_request') && fastcgi_finish_request();
exec('sudo -n /usr/local/sbin/telegram-shop-deploy-trigger >/dev/null 2>&1 &');
