<?php
declare(strict_types=1);

session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Strict', 'use_strict_mode' => true]);

function redirectWithStatus(string $status): never
{
    header('Location: /?status=' . rawurlencode($status) . '#contact', true, 303);
    exit;
}

function loadEnvironment(string $path): void
{
    if (!is_file($path) || !is_readable($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        if (preg_match('/^[A-Z][A-Z0-9_]*$/', $key) !== 1 || getenv($key) !== false) continue;
        putenv($key . '=' . trim($value, "\"'"));
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

loadEnvironment(__DIR__ . '/.env');

$token = (string) ($_POST['csrf_token'] ?? '');
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) redirectWithStatus('invalid');
if (!empty($_POST['company_website'])) redirectWithStatus('sent');
if (time() - (int) ($_SESSION['last_contact_submission'] ?? 0) < 30) redirectWithStatus('limit');

$name = trim((string) ($_POST['name'] ?? ''));
$email = trim((string) ($_POST['email'] ?? ''));
$phone = trim((string) ($_POST['phone'] ?? ''));
$message = trim((string) ($_POST['message'] ?? ''));

if ($name === '' || mb_strlen($name) > 100 || filter_var($email, FILTER_VALIDATE_EMAIL) === false ||
    mb_strlen($email) > 254 || mb_strlen($phone) > 40 || $message === '' || mb_strlen($message) > 3000 ||
    preg_match('/[\r\n]/', $email . $name) === 1) redirectWithStatus('invalid');

$recipient = getenv('CONTACT_TO_EMAIL');
$from = getenv('CONTACT_FROM_EMAIL') ?: 'noreply@vanpresale.com';
if ($recipient === false || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false || filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
    error_log('VanPresale contact form email configuration is missing or invalid.');
    redirectWithStatus('error');
}

$cleanName = preg_replace('/[^\p{L}\p{N} .\'\-]/u', '', $name) ?: 'Website visitor';
$body = "New VanPresale partnership inquiry\n\nName: {$cleanName}\nEmail: {$email}\nPhone: "
    . ($phone !== '' ? $phone : 'Not provided') . "\n\nMessage:\n{$message}\n";
$headers = [
    'From: VanPresale Website <' . $from . '>',
    'Reply-To: ' . $email,
    'Content-Type: text/plain; charset=UTF-8',
];

$_SESSION['last_contact_submission'] = time();
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

if (!mail($recipient, 'VanPresale realtor partnership inquiry', $body, implode("\r\n", $headers))) {
    error_log('VanPresale contact form mail() failed.');
    redirectWithStatus('error');
}

redirectWithStatus('sent');
