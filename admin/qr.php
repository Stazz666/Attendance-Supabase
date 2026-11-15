<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole(['admin', 'faculty']);
require_once __DIR__ . '/../vendor/autoload.php';

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

$sessionId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$sessionId || $sessionId <= 0) {
    http_response_code(404);
    exit('QR session not found.');
}

$stmt = mysqli_prepare($conn, 'SELECT qr_token FROM attendance_sessions WHERE id = ? AND course_id IS NULL AND year_level_id IS NULL AND section_id IS NULL LIMIT 1');
mysqli_stmt_bind_param($stmt, 'i', $sessionId);
mysqli_stmt_execute($stmt);
$session = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
if (!$session) {
    http_response_code(404);
    exit('QR session not found.');
}

$url = globalAppUrl('attendance/index.php?token=' . urlencode($session['qr_token']));
$renderer = new ImageRenderer(new RendererStyle(320), new SvgImageBackEnd());
$writer = new Writer($renderer);
header('Content-Type: image/svg+xml; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');
echo $writer->writeString($url);
