<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole(['student']);
require_once __DIR__ . '/../vendor/autoload.php';

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

$eventId = filter_var($_GET['event_id'] ?? null, FILTER_VALIDATE_INT);
$student = getStudentByUserId($conn, (int) $_SESSION['user']['id']);
if (!$eventId || $eventId <= 0 || !$student) {
    http_response_code(404);
    exit('Event QR code not found.');
}

$stmt = mysqli_prepare($conn, 'SELECT qr.qr_token FROM event_student_qrs qr WHERE qr.event_id = ? AND qr.student_id = ? LIMIT 1');
$studentId = (int) $student['id'];
mysqli_stmt_bind_param($stmt, 'ii', $eventId, $studentId);
mysqli_stmt_execute($stmt);
$qr = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
if (!$qr) {
    http_response_code(404);
    exit('Event QR code not found.');
}

$renderer = new ImageRenderer(new RendererStyle(400), new SvgImageBackEnd());
$writer = new Writer($renderer);
header('Content-Type: image/svg+xml; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');
echo $writer->writeString($qr['qr_token']);
