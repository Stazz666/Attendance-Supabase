<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole(['admin']);

$eventId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$eventId || $eventId <= 0) {
    http_response_code(404);
    exit('Event not found.');
}

$stmt = mysqli_prepare($conn, 'SELECT * FROM events WHERE id = ? LIMIT 1');
mysqli_stmt_bind_param($stmt, 'i', $eventId);
mysqli_stmt_execute($stmt);
$event = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
if (!$event) {
    http_response_code(404);
    exit('Event not found.');
}

$message = '';
$messageType = 'success';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = requestString($_POST, 'event_name');
    $date = requestString($_POST, 'event_date');
    $start = requestString($_POST, 'start_time');
    $end = requestString($_POST, 'end_time');
    $late = requestString($_POST, 'late_time');
    $status = requestString($_POST, 'status');
    $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Asia/Manila'));
    $validDate = $parsedDate !== false && $parsedDate->format('Y-m-d') === $date;
    $validTime = static function (string $value): bool {
        if (!preg_match('/^\d{2}:\d{2}$/', $value)) {
            return false;
        }
        $parsed = DateTimeImmutable::createFromFormat('!H:i', $value);
        return $parsed !== false && $parsed->format('H:i') === $value;
    };

    if (!csrfTokenIsValid()) {
        $message = 'Your form session expired. Reload the page and try again.';
        $messageType = 'danger';
    } elseif ($name === '' || mb_strlen($name, 'UTF-8') > 150 || !$validDate || !$validTime($start) || !$validTime($end) || !$validTime($late)
        || $start >= $end || $late < $start || $late > $end || !in_array($status, ['active', 'inactive'], true)) {
        $message = 'Check the event name, date, schedule, late time, and status.';
        $messageType = 'danger';
    } else {
        try {
            $update = mysqli_prepare($conn, 'UPDATE events SET event_name = ?, event_date = ?, start_time = ?, end_time = ?, late_time = ?, status = ? WHERE id = ?');
            mysqli_stmt_bind_param($update, 'ssssssi', $name, $date, $start, $end, $late, $status, $eventId);
            mysqli_stmt_execute($update);
            redirect('/Attendance/admin/events.php?updated=1');
        } catch (mysqli_sql_exception $exception) {
            error_log('Event update failed: ' . $exception->getMessage());
            $message = 'Event could not be updated. Please try again.';
            $messageType = 'danger';
        }
    }

    $event = array_merge($event, [
        'event_name' => $name,
        'event_date' => $date,
        'start_time' => $start,
        'end_time' => $end,
        'late_time' => $late,
        'status' => $status,
    ]);
}

include __DIR__ . '/../includes/header.php';
?>
<div class="card p-4">
    <h4 class="fw-bold mb-3">Edit Event</h4>
    <?php if ($message !== ''): ?>
        <div class="alert alert-<?= htmlspecialchars($messageType, ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <form method="post" class="row g-3">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
        <div class="col-md-4"><label class="form-label" for="event_name">Event Name</label><input class="form-control" id="event_name" name="event_name" maxlength="150" value="<?= htmlspecialchars($event['event_name'], ENT_QUOTES, 'UTF-8'); ?>" required></div>
        <div class="col-md-2"><label class="form-label" for="event_date">Event Date</label><input class="form-control" id="event_date" type="date" name="event_date" value="<?= htmlspecialchars($event['event_date'], ENT_QUOTES, 'UTF-8'); ?>" required></div>
        <div class="col-md-2"><label class="form-label" for="start_time">Start Time</label><input class="form-control" id="start_time" type="time" name="start_time" value="<?= htmlspecialchars(substr($event['start_time'], 0, 5), ENT_QUOTES, 'UTF-8'); ?>" required></div>
        <div class="col-md-2"><label class="form-label" for="end_time">End Time</label><input class="form-control" id="end_time" type="time" name="end_time" value="<?= htmlspecialchars(substr($event['end_time'], 0, 5), ENT_QUOTES, 'UTF-8'); ?>" required></div>
        <div class="col-md-2"><label class="form-label" for="late_time">Late Time</label><input class="form-control" id="late_time" type="time" name="late_time" value="<?= htmlspecialchars(substr($event['late_time'], 0, 5), ENT_QUOTES, 'UTF-8'); ?>" required></div>
        <div class="col-md-3"><label class="form-label" for="status">Status</label><select class="form-select" id="status" name="status"><option value="active" <?= $event['status'] === 'active' ? 'selected' : ''; ?>>Active</option><option value="inactive" <?= $event['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option></select></div>
        <div class="col-12 d-flex gap-2"><button class="btn btn-primary" type="submit">Save Changes</button><a class="btn btn-outline-secondary" href="/Attendance/admin/events.php">Back to Events</a></div>
    </form>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
