<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole(['admin']);

$message = '';
$messageType = 'success';

if (isset($_GET['updated'])) {
    $message = 'Event details updated successfully.';
} elseif (isset($_GET['created'])) {
    $generated = filter_var($_GET['created'], FILTER_VALIDATE_INT);
    if ($generated !== false) {
        $message = 'Event created successfully. ' . (int) $generated . ' unique student QR code(s) generated.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfTokenIsValid()) {
        $message = 'Your form session expired. Reload the page and try again.';
        $messageType = 'danger';
    } elseif (requestString($_POST, 'action') === 'toggle') {
        $eventId = filter_var($_POST['event_id'] ?? null, FILTER_VALIDATE_INT);
        $status = requestString($_POST, 'status');
        if (!$eventId || $eventId <= 0 || !in_array($status, ['active', 'inactive'], true)) {
            $message = 'Invalid event status request.';
            $messageType = 'danger';
        } else {
            $existsStmt = mysqli_prepare($conn, 'SELECT id FROM events WHERE id = ? LIMIT 1');
            mysqli_stmt_bind_param($existsStmt, 'i', $eventId);
            mysqli_stmt_execute($existsStmt);
            if (!mysqli_fetch_assoc(mysqli_stmt_get_result($existsStmt))) {
                $message = 'Event was not found.';
                $messageType = 'danger';
            } else {
                $stmt = mysqli_prepare($conn, 'UPDATE events SET status = ? WHERE id = ?');
                mysqli_stmt_bind_param($stmt, 'si', $status, $eventId);
                mysqli_stmt_execute($stmt);
                $message = 'Event status updated.';
            }
        }
    } elseif (requestString($_POST, 'action') === 'create') {
        $eventName = requestString($_POST, 'event_name');
        $eventDate = requestString($_POST, 'event_date');
        $startTime = requestString($_POST, 'start_time');
        $endTime = requestString($_POST, 'end_time');
        $lateTime = requestString($_POST, 'late_time');

        $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $eventDate, new DateTimeZone('Asia/Manila'));
        $validDate = $parsedDate !== false && $parsedDate->format('Y-m-d') === $eventDate;
        $validTime = static function (string $value): bool {
            if (!preg_match('/^\d{2}:\d{2}$/', $value)) {
                return false;
            }
            $parsed = DateTimeImmutable::createFromFormat('!H:i', $value);
            return $parsed !== false && $parsed->format('H:i') === $value;
        };
        $validTimes = $validTime($startTime) && $validTime($endTime) && $validTime($lateTime)
            && $startTime < $endTime && $lateTime >= $startTime && $lateTime <= $endTime;

        if ($eventName === '' || mb_strlen($eventName, 'UTF-8') > 150 || !$validDate || !$validTimes) {
            $message = 'Enter an event name, valid date, and schedule where Start Time < End Time and Late Time is within the schedule.';
            $messageType = 'danger';
        } else {
            $transactionStarted = false;
            try {
                mysqli_begin_transaction($conn);
                $transactionStarted = true;
                $adminId = (int) $_SESSION['user']['id'];
                $status = 'active';
                $insert = mysqli_prepare($conn, 'INSERT INTO events (event_name, event_date, start_time, end_time, late_time, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
                mysqli_stmt_bind_param($insert, 'ssssssi', $eventName, $eventDate, $startTime, $endTime, $lateTime, $status, $adminId);
                mysqli_stmt_execute($insert);
                $eventId = (int) mysqli_insert_id($conn);
                $generated = generateEventQrTokens($conn, $eventId);
                mysqli_commit($conn);
                $transactionStarted = false;
                redirect('/Attendance/admin/events.php?created=' . $generated);
            } catch (mysqli_sql_exception | Random\RandomException $exception) {
                if ($transactionStarted) {
                    mysqli_rollback($conn);
                }
                error_log('Event creation failed: ' . $exception->getMessage());
                $message = 'The event and QR codes could not be saved. Please try again.';
                $messageType = 'danger';
            }
        }
    }
}

$now = date('Y-m-d H:i:s');
$events = mysqli_query($conn, "SELECT event.*, COUNT(DISTINCT qr.id) AS qr_count,
        COUNT(DISTINCT CASE WHEN attendance.status = 'PRESENT' THEN attendance.id END) AS present_count,
        COUNT(DISTINCT CASE WHEN attendance.status = 'LATE' THEN attendance.id END) AS late_count,
        (SELECT COUNT(*) FROM students) AS total_students
    FROM events event
    LEFT JOIN event_student_qrs qr ON qr.event_id = event.id
    LEFT JOIN event_attendance attendance ON attendance.event_id = event.id AND attendance.student_id = qr.student_id
    GROUP BY event.id
    ORDER BY event.event_date DESC, event.start_time DESC, event.id DESC");

include __DIR__ . '/../includes/header.php';
?>

<div class="card p-4 mb-4">
    <h4 class="fw-bold mb-3">Create Event &amp; Generate QR</h4>
    <?php if ($message !== ''): ?>
        <div class="alert alert-<?= htmlspecialchars($messageType, ENT_QUOTES, 'UTF-8'); ?>" role="alert"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <form method="post" class="row g-3">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="action" value="create">
        <div class="col-md-4">
            <label class="form-label" for="event_name">Event Name</label>
            <input class="form-control" id="event_name" name="event_name" maxlength="150" required>
        </div>
        <div class="col-md-2">
            <label class="form-label" for="event_date">Event Date</label>
            <input class="form-control" id="event_date" type="date" name="event_date" value="<?= date('Y-m-d'); ?>" required>
        </div>
        <div class="col-md-2">
            <label class="form-label" for="start_time">Start Time</label>
            <input class="form-control" id="start_time" type="time" name="start_time" required>
        </div>
        <div class="col-md-2">
            <label class="form-label" for="end_time">End Time</label>
            <input class="form-control" id="end_time" type="time" name="end_time" required>
        </div>
        <div class="col-md-2">
            <label class="form-label" for="late_time">Late Time</label>
            <input class="form-control" id="late_time" type="time" name="late_time" required>
        </div>
        <div class="col-12">
            <button class="btn btn-primary" type="submit">GENERATE QR CODES</button>
            <span class="text-muted ms-2">A separate QR token is generated for each registered student.</span>
        </div>
    </form>
</div>

<div class="card p-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h5 class="fw-bold mb-0">Event Management</h5>
        <a class="btn btn-outline-primary" href="/Attendance/admin/scanner.php">Open QR Scanner</a>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr><th>Event</th><th>Date</th><th>Schedule</th><th>Students</th><th>QR Codes</th><th>Present</th><th>Late</th><th>Absent</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php while ($event = mysqli_fetch_assoc($events)): ?>
                    <?php
                    $eventStart = $event['event_date'] . ' ' . $event['start_time'];
                    $eventEnd = $event['event_date'] . ' ' . $event['end_time'];
                    $isCompleted = $eventEnd < $now;
                    $eventLabel = $event['status'] !== 'active' ? 'Inactive' : ($eventStart > $now ? 'Upcoming' : ($isCompleted ? 'Completed' : 'Active'));
                    $absent = $isCompleted ? max(0, (int) $event['qr_count'] - (int) $event['present_count'] - (int) $event['late_count']) : 0;
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($event['event_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?= htmlspecialchars($event['event_date'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?= htmlspecialchars(substr($event['start_time'], 0, 5) . ' - ' . substr($event['end_time'], 0, 5), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?= number_format((int) $event['total_students']); ?></td>
                        <td><?= number_format((int) $event['qr_count']); ?></td>
                        <td><?= number_format((int) $event['present_count']); ?></td>
                        <td><?= number_format((int) $event['late_count']); ?></td>
                        <td><?= number_format($absent); ?></td>
                        <td><span class="badge bg-<?= $eventLabel === 'Active' ? 'success' : ($eventLabel === 'Inactive' ? 'secondary' : 'info'); ?>"><?= htmlspecialchars($eventLabel, ENT_QUOTES, 'UTF-8'); ?></span></td>
                        <td>
                            <div class="d-flex flex-wrap gap-1">
                                <a class="btn btn-sm btn-outline-primary" href="/Attendance/admin/event-attendance.php?id=<?= (int) $event['id']; ?>">View Event</a>
                                <a class="btn btn-sm btn-outline-primary" href="/Attendance/admin/event-attendance.php?id=<?= (int) $event['id']; ?>#attendance">View Attendance</a>
                                <a class="btn btn-sm btn-outline-primary" href="/Attendance/admin/scanner.php?event_id=<?= (int) $event['id']; ?>">Open Scanner</a>
                                <a class="btn btn-sm btn-outline-secondary" href="/Attendance/admin/event-edit.php?id=<?= (int) $event['id']; ?>">Edit Event</a>
                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="event_id" value="<?= (int) $event['id']; ?>">
                                    <input type="hidden" name="status" value="<?= $event['status'] === 'active' ? 'inactive' : 'active'; ?>">
                                    <button class="btn btn-sm btn-outline-<?= $event['status'] === 'active' ? 'danger' : 'success'; ?>" type="submit"><?= $event['status'] === 'active' ? 'Deactivate' : 'Activate'; ?></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
