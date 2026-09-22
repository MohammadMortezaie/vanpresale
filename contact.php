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

function smtpRead($socket, array $expectedCodes): string
{
    $response = '';
    do {
        $line = fgets($socket, 8192);
        if ($line === false) throw new RuntimeException('SMTP server closed the connection.');
        $response .= $line;
    } while (strlen($line) >= 4 && $line[3] === '-');

    $code = (int) substr($line, 0, 3);
    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException('Unexpected SMTP response: ' . trim($response));
    }
    return $response;
}

function smtpCommand($socket, string $command, array $expectedCodes): string
{
    if (fwrite($socket, $command . "\r\n") === false) {
        throw new RuntimeException('Unable to write to the SMTP server.');
    }
    return smtpRead($socket, $expectedCodes);
}

function sendSmtpMail(string $recipient, string $from, string $replyTo, string $subject, string $body): void
{
    $host = getenv('SMTP_HOST');
    $port = filter_var(getenv('SMTP_PORT') ?: '587', FILTER_VALIDATE_INT);
    $username = getenv('SMTP_USERNAME');
    $password = getenv('SMTP_PASSWORD');
    if ($host === false || $username === false || $password === false || $port === false) {
        throw new RuntimeException('SMTP configuration is missing or invalid.');
    }

    $socket = stream_socket_client("tcp://{$host}:{$port}", $errorCode, $errorMessage, 15);
    if ($socket === false) throw new RuntimeException("SMTP connection failed: {$errorMessage} ({$errorCode}).");
    stream_set_timeout($socket, 15);

    try {
        smtpRead($socket, [220]);
        $clientName = gethostname() ?: 'vanpresale.com';
        smtpCommand($socket, 'EHLO ' . $clientName, [250]);
        smtpCommand($socket, 'STARTTLS', [220]);
        if (stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
            throw new RuntimeException('Unable to establish SMTP TLS encryption.');
        }
        smtpCommand($socket, 'EHLO ' . $clientName, [250]);
        smtpCommand($socket, 'AUTH LOGIN', [334]);
        smtpCommand($socket, base64_encode($username), [334]);
        smtpCommand($socket, base64_encode($password), [235]);
        smtpCommand($socket, 'MAIL FROM:<' . $from . '>', [250]);
        smtpCommand($socket, 'RCPT TO:<' . $recipient . '>', [250, 251]);
        smtpCommand($socket, 'DATA', [354]);

        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@vanpresale.com>',
            'From: VanPresale Website <' . $from . '>',
            'Reply-To: ' . $replyTo,
            'To: ' . $recipient,
            'Subject: ' . $subject,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];
        $message = implode("\n", $headers) . "\n\n" . str_replace("\n.", "\n..", str_replace(["\r\n", "\r"], "\n", $body));
        if (fwrite($socket, str_replace("\n", "\r\n", $message) . "\r\n.\r\n") === false) {
            throw new RuntimeException('Unable to send the SMTP message.');
        }
        smtpRead($socket, [250]);
        smtpCommand($socket, 'QUIT', [221]);
    } finally {
        fclose($socket);
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
$from = getenv('CONTACT_FROM_EMAIL') ?: 'donotreply@vanpresale.com';
if ($recipient === false || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false || filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
    error_log('VanPresale contact form email configuration is missing or invalid.');
    redirectWithStatus('error');
}

$cleanName = preg_replace('/[^\p{L}\p{N} .\'\-]/u', '', $name) ?: 'Website visitor';
$body = "New VanPresale partnership inquiry\n\nName: {$cleanName}\nEmail: {$email}\nPhone: "
    . ($phone !== '' ? $phone : 'Not provided') . "\n\nMessage:\n{$message}\n";
$_SESSION['last_contact_submission'] = time();
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

try {
    sendSmtpMail($recipient, $from, $email, 'VanPresale realtor partnership inquiry', $body);
} catch (Throwable $exception) {
    error_log('VanPresale contact form SMTP delivery failed: ' . $exception->getMessage());
    redirectWithStatus('error');
}

redirectWithStatus('sent');
