<?php
declare(strict_types=1);

session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Strict',
    'use_strict_mode' => true,
]);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$html = file_get_contents(__DIR__ . '/index.html');
if ($html === false) {
    http_response_code(500);
    exit('Unable to load the page.');
}

$status = isset($_GET['status']) ? (string) $_GET['status'] : '';
$messages = [
    'sent' => '<p class="form-status success" role="status">Thank you. Your message has been sent.</p>',
    'invalid' => '<p class="form-status error" role="alert">Please check the form and try again.</p>',
    'error' => '<p class="form-status error" role="alert">We could not send your message. Please try again shortly.</p>',
    'limit' => '<p class="form-status error" role="alert">Please wait a moment before sending another message.</p>',
];

$html = str_replace(
    ['{{CSRF_TOKEN}}', '{{FORM_STATUS}}'],
    [htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'), $messages[$status] ?? ''],
    $html
);

header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
echo $html;
