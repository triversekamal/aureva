<?php
declare(strict_types=1);

// ---- Configuration ----
// TODO: replace with the real sales inbox before going live.
$notifyEmail = 'sales@example.com';
$logFile = __DIR__ . '/leads.csv';

header('Content-Type: application/json');

function respond(bool $success, string $message = ''): void {
    http_response_code($success ? 200 : 400);
    echo json_encode(['success' => $success, 'message' => $message]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(false, 'Invalid request method.');
}

// Honeypot: a hidden field bots tend to fill in but real visitors never see/fill.
if (trim((string)($_POST['website'] ?? '')) !== '') {
    respond(true, 'Thank you!'); // pretend success, drop silently
}

$name = trim((string)($_POST['name'] ?? ''));
$phone = preg_replace('/\D/', '', (string)($_POST['phone'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$interest = trim((string)($_POST['interest'] ?? ''));
$consent = trim((string)($_POST['consent'] ?? '')) !== '';

$utmSource = trim((string)($_POST['utm_source'] ?? ''));
$utmMedium = trim((string)($_POST['utm_medium'] ?? ''));
$utmCampaign = trim((string)($_POST['utm_campaign'] ?? ''));
$utmTerm = trim((string)($_POST['utm_term'] ?? ''));
$gclid = trim((string)($_POST['gclid'] ?? ''));
$pageUrl = trim((string)($_POST['page_url'] ?? ''));

// ---- Server-side validation (mirrors the client-side checks; never trust the client alone) ----
$errors = [];
if (mb_strlen($name) < 2) $errors[] = 'name';
if (!preg_match('/^[6-9]\d{9}$/', $phone)) $errors[] = 'phone';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'email';
if (!$consent) $errors[] = 'consent';

if (!empty($errors)) {
    respond(false, 'Please check: ' . implode(', ', $errors));
}

// Strip CR/LF so form values can never inject extra email headers.
$stripCrlf = static fn(string $v): string => str_replace(["\r", "\n"], '', $v);
$name = $stripCrlf($name);
$email = $stripCrlf($email);

// ---- Persist every lead to a CSV so nothing is lost even if email delivery fails ----
$row = [
    date('Y-m-d H:i:s'),
    $name,
    $phone,
    $email,
    $interest,
    $utmSource,
    $utmMedium,
    $utmCampaign,
    $utmTerm,
    $gclid,
    $pageUrl,
];

$isNewFile = !file_exists($logFile);
$fp = @fopen($logFile, 'a');
if ($fp !== false) {
    if (flock($fp, LOCK_EX)) {
        if ($isNewFile) {
            fputcsv($fp, ['Timestamp', 'Name', 'Phone', 'Email', 'Interest', 'UTM Source', 'UTM Medium', 'UTM Campaign', 'UTM Term', 'GCLID', 'Page URL']);
        }
        fputcsv($fp, $row);
        flock($fp, LOCK_UN);
    }
    fclose($fp);
}

// ---- Best-effort email notification (a failed mail() must not fail the lead capture) ----
$subject = 'New DLF Aureva enquiry from ' . $name;
$body = "New enquiry received:\n\n"
    . "Name: {$name}\n"
    . "Phone: {$phone}\n"
    . "Email: {$email}\n"
    . "Interest: {$interest}\n"
    . "UTM Source: {$utmSource}\n"
    . "UTM Medium: {$utmMedium}\n"
    . "UTM Campaign: {$utmCampaign}\n"
    . "UTM Term: {$utmTerm}\n"
    . "GCLID: {$gclid}\n"
    . "Page URL: {$pageUrl}\n";

$headers = "From: no-reply@" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . "\r\n"
    . "Reply-To: {$email}\r\n"
    . "Content-Type: text/plain; charset=UTF-8";

@mail($notifyEmail, $subject, $body, $headers);

respond(true, 'Thank you! We will contact you shortly.');
