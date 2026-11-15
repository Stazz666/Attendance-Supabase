<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole(['admin']);

$sessionId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$sessionId || $sessionId <= 0) {
    http_response_code(404);
    exit('Attendance session not found.');
}

$stmt = mysqli_prepare($conn, 'SELECT session.id, session.session_name, session.session_date, session.start_time, session.end_time, session.late_threshold, EXISTS(SELECT 1 FROM attendance_records WHERE session_id = session.id) AS has_records FROM attendance_sessions session WHERE session.id = ? AND session.course_id IS NULL AND session.year_level_id IS NULL AND session.section_id IS NULL LIMIT 1');
mysqli_stmt_bind_param($stmt, 'i', $sessionId);
mysqli_stmt_execute($stmt);
$session = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
if (!$session) {
    http_response_code(404);
    exit('Attendance session not found.');
}

$message = '';
$messageType = 'danger';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sessionName = requestString($_POST, 'session_name');
    $sessionDate = requestString($_POST, 'session_date');
    $startTime = requestString($_POST, 'start_time');
    $endTime = requestString($_POST, 'end_time');
    $lateThreshold = requestString($_POST, 'late_threshold');
    $days = ['Day 1', 'Day 2', 'Day 3', 'Day 4', 'Day 5', 'Day 6'];
    $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $sessionDate, new DateTimeZone('Asia/Manila'));
    $validDate = $parsedDate !== false && $parsedDate->format('Y-m-d') === $sessionDate;
    $validTime = static function (string $value): bool {
        if (!preg_match('/^\d{2}:\d{2}$/', $value)) {
            return false;
        }
        $parsed = DateTimeImmutable::createFromFormat('!H:i', $value);
        return $parsed !== false && $parsed->format('H:i') === $value;
    };
    $validTimes = $validTime($startTime) && $validTime($endTime) && $validTime($lateThreshold)
        && $startTime < $endTime && $lateThreshold >= $startTime && $lateThreshold <= $endTime;

    if (!csrfTokenIsValid()) {
        $message = 'Your form session expired. Reload the page and try again.';
    } elseif ((int) $session['has_records'] > 0) {
        $message = 'This session already has attendance records and cannot be edited.';
    } elseif (!in_array($sessionName, $days, true) || !$validDate || !$validTimes) {
        $message = 'Choose a Day 1–Day 6 session, a valid date, and times where Start < Late ≤ End.';
    } else {
        $duplicate = mysqli_prepare($conn, 'SELECT id FROM attendance_sessions WHERE id <> ? AND ((session_name = ? AND session_date = ?) OR (session_date = ? AND course_id IS NULL AND year_level_id IS NULL AND section_id IS NULL)) LIMIT 1');
        mysqli_stmt_bind_param($duplicate, 'isss', $sessionId, $sessionName, $sessionDate, $sessionDate);
        mysqli_stmt_execute($duplicate);
        if (mysqli_fetch_assoc(mysqli_stmt_get_result($duplicate))) {
            $message = 'Another session already uses this day or date.';
        } else {
            try {
                $update = mysqli_prepare($conn, 'UPDATE attendance_sessions SET session_name = ?, session_date = ?, start_time = ?, end_time = ?, late_threshold = ? WHERE id = ? AND course_id IS NULL AND year_level_id IS NULL AND section_id IS NULL');
                mysqli_stmt_bind_param($update, 'sssssi', $sessionName, $sessionDate, $startTime, $endTime, $lateThreshold, $sessionId);
                mysqli_stmt_execute($update);
                redirect('/Attendance/admin/sessions.php?updated=1');
            } catch (mysqli_sql_exception $exception) {
                error_log('Attendance session update failed: ' . $exception->getMessage());
                $message = $exception->getCode() === 1062
                    ? 'Another session already uses this day and date.'
                    : 'Attendance session could not be updated. Please try again.';
            }
        }
    }

    $session = array_merge($session, [
        'session_name' => $sessionName,
        'session_date' => $sessionDate,
        'start_time' => $startTime,
        'end_time' => $endTime,
        'late_threshold' => $lateThreshold,
    ]);
}

include __DIR__ . '/../includes/header.php';
?>
<div class="card p-4">
    <h4 class="fw-bold mb-3">Edit Attendance Session</h4>
    <?php if ($message !== ''): ?>
        <div class="alert alert-<?= htmlspecialchars($messageType, ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <form method="post" class="row g-3">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
        <div class="col-md-3">
            <label class="form-label" for="session_name">Session Name</label>
            <select class="form-select" id="session_name" name="session_name" required>
                <?php foreach (['Day 1', 'Day 2', 'Day 3', 'Day 4', 'Day 5', 'Day 6'] as $day): ?>
                    <option value="<?= htmlspecialchars($day, ENT_QUOTES, 'UTF-8'); ?>" <?= $session['session_name'] === $day ? 'selected' : ''; ?>><?= htmlspecialchars($day, ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label" for="session_date">Attendance Date</label>
            <input class="form-control" id="session_date" type="date" name="session_date" value="<?= htmlspecialchars($session['session_date'], ENT_QUOTES, 'UTF-8'); ?>" required>
        </div>
        <div class="col-md-2">
            <label class="form-label" for="start_time">Start Time</label>
            <input class="form-control" id="start_time" type="time" name="start_time" value="<?= htmlspecialchars(substr($session['start_time'], 0, 5), ENT_QUOTES, 'UTF-8'); ?>" required>
        </div>
        <div class="col-md-2">
            <label class="form-label" for="end_time">End Time</label>
            <input class="form-control" id="end_time" type="time" name="end_time" value="<?= htmlspecialchars(substr($session['end_time'], 0, 5), ENT_QUOTES, 'UTF-8'); ?>" required>
        </div>
        <div class="col-md-2">
            <label class="form-label" for="late_threshold">Late Time</label>
            <input class="form-control" id="late_threshold" type="time" name="late_threshold" value="<?= htmlspecialchars(substr($session['late_threshold'], 0, 5), ENT_QUOTES, 'UTF-8'); ?>" required>
        </div>
        <div class="col-12 d-flex gap-2">
            <button class="btn btn-primary" type="submit">Save Changes</button>
            <a class="btn btn-outline-secondary" href="/Attendance/admin/sessions.php">Cancel</a>
        </div>
    </form>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
