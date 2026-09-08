<?php
/**
 * miko® GmbH – Kontaktformular-Backend
 *
 * Zwei Aufgaben:
 *   1. GET  ?action=token  → liefert ein signiertes, zeitgestempeltes Token
 *                            (für Zeitfenster-Prüfung & Einmal-Verwendung)
 *   2. POST                → validiert, prüft Spam/Missbrauch und versendet per SMTP
 *
 * Schutzschichten:
 *   - Honeypot-Felder (für Bots unsichtbar)
 *   - Signiertes Token (HMAC) → fälschungssicherer Zeitstempel
 *   - Zeitfenster: frühestens nach min_seconds, spätestens nach max_seconds
 *   - Einmal-Token (Replay-Schutz über Nonce-Speicher)
 *   - Rate-Limiting pro IP (Stunde/Tag)
 *   - Strenge Eingabe-Validierung & Längenlimits
 *   - Inhalts-Heuristik (zu viele Links / Steuerzeichen / UTF-8)
 *   - Header-Injection-Schutz
 *   - Server-Pflichtprüfung der Datenschutz-Checkbox
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/lib/PHPMailer/Exception.php';
require __DIR__ . '/lib/PHPMailer/PHPMailer.php';
require __DIR__ . '/lib/PHPMailer/SMTP.php';

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

// ──────────────────────────────────────────────────────────────
//  Konfiguration laden
// ──────────────────────────────────────────────────────────────
$config_file = __DIR__ . '/config.smtp.php';
if (!is_file($config_file)) {
    respond(500, false, 'Server-Konfiguration fehlt. Bitte kontaktieren Sie uns direkt per E-Mail.');
}
$cfg = require $config_file;
$sec = $cfg['security'] ?? [];

$SECRET      = (string)($sec['secret'] ?? '');
$MIN_SECONDS = (int)($sec['min_seconds'] ?? 3);
$MAX_SECONDS = (int)($sec['max_seconds'] ?? 3600);
$MAX_HOUR    = (int)($sec['max_per_hour'] ?? 5);
$MAX_DAY     = (int)($sec['max_per_day'] ?? 15);
$MAX_LINKS   = (int)($sec['max_links'] ?? 3);
$DATA_DIR    = (string)($sec['data_dir'] ?? (__DIR__ . '/data'));
$MAX_MESSAGE = 5000;

if ($SECRET === '' || $SECRET === 'HIER_EIGENES_ZUFALLS_SECRET_EINTRAGEN') {
    respond(500, false, 'Server-Konfiguration unvollständig. Bitte kontaktieren Sie uns direkt per E-Mail.');
}

// ──────────────────────────────────────────────────────────────
//  Hilfsfunktionen
// ──────────────────────────────────────────────────────────────
function respond($code, $success, $message, $extra = []) {
    http_response_code($code);
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

function clean($value) {
    return htmlspecialchars(strip_tags(trim((string)$value)), ENT_QUOTES, 'UTF-8');
}

function client_ip() {
    // Nur REMOTE_ADDR ist vertrauenswürdig; Proxy-Header sind fälschbar.
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

// IP nur als Hash speichern (DSGVO-freundlich)
function ip_hash($secret) {
    return substr(hash_hmac('sha256', client_ip(), $secret), 0, 32);
}

function b64url_encode($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function b64url_decode($s) { return base64_decode(strtr($s, '-_', '+/')); }

// Signiertes Token erzeugen:  base64url(ts.nonce).signatur
function make_token($secret) {
    $ts    = time();
    $nonce = bin2hex(random_bytes(16));
    $payload = $ts . '.' . $nonce;
    $sig   = hash_hmac('sha256', $payload, $secret);
    return b64url_encode($payload) . '.' . $sig;
}

// Token prüfen → gibt [ts, nonce] zurück oder null
function parse_token($token, $secret) {
    $parts = explode('.', (string)$token);
    if (count($parts) !== 2) return null;
    [$payload_b64, $sig] = $parts;
    $payload = b64url_decode($payload_b64);
    if ($payload === false || strpos($payload, '.') === false) return null;
    $expected = hash_hmac('sha256', $payload, $secret);
    if (!hash_equals($expected, (string)$sig)) return null;   // Signatur falsch/gefälscht
    [$ts, $nonce] = explode('.', $payload, 2);
    if (!ctype_digit($ts) || !ctype_xdigit($nonce)) return null;
    return [(int)$ts, $nonce];
}

// JSON-Datei mit exklusiver Sperre lesen/ggf. schreiben (atomar).
// Die Callback-Funktion setzt $GLOBALS['__json_data'] und __json_dirty,
// wenn der neue Stand gespeichert werden soll.
function with_locked_json($file, callable $fn) {
    $GLOBALS['__json_dirty'] = false;
    $dir = dirname($file);
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $fh = @fopen($file, 'c+');
    if (!$fh) return $fn([]); // Datei nicht beschreibbar: nicht blockieren
    flock($fh, LOCK_EX);
    $raw  = stream_get_contents($fh);
    $data = $raw ? json_decode($raw, true) : [];
    if (!is_array($data)) $data = [];
    $result = $fn($data);
    if (!empty($GLOBALS['__json_dirty'])) {
        rewind($fh);
        ftruncate($fh, 0);
        fwrite($fh, json_encode($GLOBALS['__json_data']));
        fflush($fh);
        $GLOBALS['__json_dirty'] = false;
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    return $result;
}

// ──────────────────────────────────────────────────────────────
//  1) GET ?action=token  → Token ausgeben
// ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (($_GET['action'] ?? '') === 'token') {
        respond(200, true, '', ['token' => make_token($SECRET)]);
    }
    respond(405, false, 'Methode nicht erlaubt.');
}

// ──────────────────────────────────────────────────────────────
//  2) POST  → verarbeiten
// ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, false, 'Methode nicht erlaubt.');
}

// ── Honeypots: für echte Nutzer unsichtbar, von Bots gern ausgefüllt ──
if (!empty($_POST['website_url']) || !empty($_POST['email_confirm'])) {
    // Bot: vortäuschen, dass alles ok ist – aber nichts senden.
    respond(200, true, 'Vielen Dank für Ihre Nachricht!');
}

// ── Token & Zeitfenster ──
$parsed = parse_token($_POST['form_token'] ?? '', $SECRET);
if ($parsed === null) {
    respond(400, false, 'Sicherheitsprüfung fehlgeschlagen. Bitte laden Sie die Seite neu und versuchen Sie es erneut.');
}
[$token_ts, $token_nonce] = $parsed;
$elapsed = time() - $token_ts;

if ($elapsed < 0) {
    respond(400, false, 'Ungültiger Zeitstempel. Bitte laden Sie die Seite neu.');
}
if ($elapsed < $MIN_SECONDS) {
    // Zu schnell abgesendet → mit hoher Wahrscheinlichkeit ein Bot.
    respond(429, false, 'Bitte nehmen Sie sich einen Moment Zeit und senden Sie das Formular erneut ab.');
}
if ($elapsed > $MAX_SECONDS) {
    respond(400, false, 'Das Formular ist abgelaufen. Bitte laden Sie die Seite neu und versuchen Sie es erneut.');
}

// ── Einmal-Token (Replay-Schutz) ──
$nonce_file = $DATA_DIR . '/nonces.json';
$nonce_ok = with_locked_json($nonce_file, function ($data) use ($token_nonce, $MAX_SECONDS) {
    $now = time();
    // Abgelaufene Nonces aufräumen
    foreach ($data as $n => $exp) {
        if ($exp < $now) unset($data[$n]);
    }
    if (isset($data[$token_nonce])) {
        $GLOBALS['__json_data'] = $data;
        $GLOBALS['__json_dirty'] = true;
        return false; // bereits verwendet
    }
    $data[$token_nonce] = $now + $MAX_SECONDS;
    $GLOBALS['__json_data'] = $data;
    $GLOBALS['__json_dirty'] = true;
    return true;
});
if (!$nonce_ok) {
    respond(400, false, 'Dieses Formular wurde bereits gesendet. Bitte laden Sie die Seite neu.');
}

// ── Rate-Limiting pro IP ──
$rl_file = $DATA_DIR . '/ratelimit.json';
$iph = ip_hash($SECRET);
$rl = with_locked_json($rl_file, function ($data) use ($iph, $MAX_HOUR, $MAX_DAY) {
    $now = time();
    $entry = $data[$iph] ?? [];
    // nur Zeitstempel der letzten 24h behalten
    $entry = array_values(array_filter($entry, function ($t) use ($now) {
        return $t > $now - 86400;
    }));
    $last_hour = count(array_filter($entry, function ($t) use ($now) {
        return $t > $now - 3600;
    }));
    $last_day = count($entry);

    $allowed = ($last_hour < $MAX_HOUR) && ($last_day < $MAX_DAY);
    if ($allowed) {
        $entry[] = $now;
    }
    $data[$iph] = $entry;

    // Gesamtspeicher klein halten: leere/alte IPs entfernen
    foreach ($data as $k => $list) {
        $list = array_filter($list, function ($t) use ($now) { return $t > $now - 86400; });
        if (empty($list)) unset($data[$k]); else $data[$k] = array_values($list);
    }

    $GLOBALS['__json_data'] = $data;
    $GLOBALS['__json_dirty'] = true;
    return $allowed;
});
if (!$rl) {
    respond(429, false, 'Sie haben in kurzer Zeit zu viele Anfragen gesendet. Bitte versuchen Sie es später erneut oder schreiben Sie uns direkt an info@miko-online.com.');
}

// ── Eingaben bereinigen ──
$name      = clean($_POST['name'] ?? '');
$firma     = clean($_POST['firma'] ?? '');
$email     = clean($_POST['email'] ?? '');
$telefon   = clean($_POST['telefon'] ?? '');
$betreff   = clean($_POST['betreff'] ?? 'Allgemeine Anfrage');
$nachricht = trim((string)($_POST['message'] ?? ''));

// ── Validierung ──
$errors = [];

// Gültiges UTF-8 erzwingen
foreach (['name' => $name, 'email' => $email, 'firma' => $firma, 'telefon' => $telefon, 'message' => $nachricht] as $feld => $wert) {
    if ($wert !== '' && !mb_check_encoding($wert, 'UTF-8')) {
        $errors[] = 'Ungültige Zeichenkodierung.';
        break;
    }
}

// Datenschutz-Checkbox serverseitig prüfen
if (empty($_POST['datenschutz'])) {
    $errors[] = 'Bitte bestätigen Sie die Datenschutzerklärung.';
}

if ($name === '' || mb_strlen($name) > 100) {
    $errors[] = 'Bitte geben Sie einen gültigen Namen ein (max. 100 Zeichen).';
}
if (mb_strlen($firma) > 120) {
    $errors[] = 'Der Firmenname ist zu lang.';
}
if ($email === '' || mb_strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Bitte geben Sie eine gültige E-Mail-Adresse ein.';
}
if (mb_strlen($telefon) > 40) {
    $errors[] = 'Die Telefonnummer ist zu lang.';
}
if ($nachricht === '') {
    $errors[] = 'Bitte geben Sie eine Nachricht ein.';
}
if (mb_strlen($nachricht) > $MAX_MESSAGE) {
    $errors[] = 'Die Nachricht ist zu lang (max. ' . $MAX_MESSAGE . ' Zeichen).';
}

// Steuerzeichen (außer Zeilenumbruch/Tab) in der Nachricht ablehnen
if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $nachricht)) {
    $errors[] = 'Die Nachricht enthält ungültige Zeichen.';
}

// Header-Injection in Kopf-Feldern verhindern
if (preg_match('/[\r\n]/', $name . $email . $firma . $telefon . $betreff)) {
    $errors[] = 'Ungültige Zeichen in den Eingabefeldern.';
}

// Inhalts-Heuristik: zu viele Links → typischer Spam
$link_count = preg_match_all('#https?://#i', $nachricht);
if ($link_count > $MAX_LINKS) {
    $errors[] = 'Ihre Nachricht enthält zu viele Links. Bitte reduzieren Sie diese.';
}

if (!empty($errors)) {
    respond(400, false, implode(' ', array_unique($errors)));
}

// Nachricht erst nach den Prüfungen für die Ausgabe entschärfen
$nachricht_clean = htmlspecialchars($nachricht, ENT_QUOTES, 'UTF-8');

// ── Betreff-Labels ──
$betreff_labels = [
    'werbeartikel'      => 'Werbeartikel',
    'corporate-fashion' => 'Corporate Fashion',
    'arbeitskleidung'   => 'Arbeitskleidung',
    'merchandising'     => 'Merchandising',
    'intrastore'        => 'IntraStore',
    'veredelung'        => 'Veredelung',
    'sonstiges'         => 'Sonstiges',
];
$betreff_text = $betreff_labels[$betreff] ?? $betreff;

// ── E-Mail-Inhalt zusammenbauen ──
$email_betreff = '[miko Website] Kontaktanfrage – ' . $betreff_text;

$email_body  = "Neue Kontaktanfrage über die Website:\n";
$email_body .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
$email_body .= "Name:      $name\n";
if ($firma !== '')   { $email_body .= "Firma:     $firma\n"; }
$email_body .= "E-Mail:    $email\n";
if ($telefon !== '') { $email_body .= "Telefon:   $telefon\n"; }
$email_body .= "Betreff:   $betreff_text\n\n";
$email_body .= "Nachricht:\n";
$email_body .= "──────────────────────────────────────\n";
$email_body .= "$nachricht_clean\n";
$email_body .= "──────────────────────────────────────\n\n";
$email_body .= "Gesendet am:  " . date('d.m.Y, H:i') . " Uhr\n";
$email_body .= "Sendezeit:    " . $elapsed . " Sek. nach Seitenaufruf\n";

// ── Versand per SMTP ──
$mail = new PHPMailer(true);

try {
    $mail->isSMTP();
    $mail->Host       = $cfg['host'];
    $mail->SMTPAuth   = true;
    $mail->Username   = $cfg['username'];
    $mail->Password   = $cfg['password'];
    $mail->Port       = (int) $cfg['port'];
    $mail->CharSet    = PHPMailer::CHARSET_UTF8;
    $mail->SMTPDebug  = (int) ($cfg['debug'] ?? 0);

    if (($cfg['encryption'] ?? 'tls') === 'ssl') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;       // Port 465
    } else {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;    // Port 587
    }

    $mail->setFrom($cfg['from_email'], $cfg['from_name'] ?? 'miko Website');
    $mail->addAddress($cfg['to_email'], $cfg['to_name'] ?? '');
    $mail->addReplyTo($email, $name);

    $mail->Subject = $email_betreff;
    $mail->Body    = $email_body;

    $mail->send();

    respond(200, true, 'Vielen Dank für Ihre Nachricht! Wir melden uns schnellstmöglich bei Ihnen.');
} catch (Exception $e) {
    error_log('Kontaktformular SMTP-Fehler: ' . $mail->ErrorInfo);
    respond(500, false, 'Leider konnte die Nachricht nicht gesendet werden. Bitte versuchen Sie es später erneut oder kontaktieren Sie uns direkt per E-Mail.');
}
