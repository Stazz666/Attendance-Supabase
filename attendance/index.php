<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$token = $_GET['token'] ?? '';
if (!is_string($token) || !preg_match('/^[a-f0-9]{20,128}$/i', $token)) {
    echo '<div class="alert alert-danger m-4">Invalid attendance QR code.</div>';
    exit;
}

$sessionStmt = mysqli_prepare($conn, 'SELECT * FROM attendance_sessions WHERE qr_token = ? LIMIT 1');
mysqli_stmt_bind_param($sessionStmt, 's', $token);
mysqli_stmt_execute($sessionStmt);
$session = mysqli_fetch_assoc(mysqli_stmt_get_result($sessionStmt));
if (!$session) {
    echo '<div class="alert alert-danger m-4">QR code is invalid or expired.</div>';
    exit;
}

$sessionOpen = sessionIsActive($session);
$student = null;
$attendanceRecord = null;
$otherAttendanceRecord = null;
if (isset($_SESSION['user']) && $_SESSION['user']['role'] === 'student') {
    $student = getStudentByUserId($conn, $_SESSION['user']['id']);
    if ($student) {
        if (!studentMayUseSession($session, $student)) {
            $student = null;
        } else {
        $recordStmt = mysqli_prepare($conn, 'SELECT time_in, time_out FROM attendance_records WHERE session_id = ? AND student_id = ? LIMIT 1');
        $sessionId = (int) $session['id'];
        $studentId = (int) $student['id'];
        mysqli_stmt_bind_param($recordStmt, 'ii', $sessionId, $studentId);
        mysqli_stmt_execute($recordStmt);
        $attendanceRecord = mysqli_fetch_assoc(mysqli_stmt_get_result($recordStmt));
        if (!$attendanceRecord) {
            $dailyStmt = mysqli_prepare($conn, 'SELECT session.session_name, record.time_in, record.time_out FROM attendance_records record JOIN attendance_sessions session ON session.id = record.session_id WHERE record.student_id = ? AND record.attendance_date = ? LIMIT 1');
            $sessionDate = $session['session_date'];
            mysqli_stmt_bind_param($dailyStmt, 'is', $studentId, $sessionDate);
            mysqli_stmt_execute($dailyStmt);
            $otherAttendanceRecord = mysqli_fetch_assoc(mysqli_stmt_get_result($dailyStmt));
        }
        }
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="card p-4 mx-auto mt-5" style="max-width: 700px;">
    <h3 class="fw-bold mb-3">Attendance Session</h3>
    <?php if ($sessionOpen): ?>
        <div class="alert alert-success">This attendance session is active.</div>
    <?php else: ?>
        <div class="alert alert-danger">This attendance session is not active or has expired.</div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-md-6">
            <div class="card bg-light p-3 h-100">
                <div class="text-muted small">Session</div>
                <div class="fs-5 fw-bold"><?= htmlspecialchars($session['session_name']); ?></div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card bg-light p-3 h-100">
                <div class="text-muted small">Attendance Date</div>
                <div class="fs-5 fw-bold"><?= htmlspecialchars($session['session_date']); ?></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card bg-light p-3 h-100">
                <div class="text-muted small">Start</div>
                <div class="fw-bold"><?= htmlspecialchars($session['start_time']); ?></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card bg-light p-3 h-100">
                <div class="text-muted small">Late</div>
                <div class="fw-bold"><?= htmlspecialchars($session['late_threshold']); ?></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card bg-light p-3 h-100">
                <div class="text-muted small">End</div>
                <div class="fw-bold"><?= htmlspecialchars($session['end_time']); ?></div>
            </div>
        </div>

        <div class="col-12">
            <?php if (!empty($_SESSION['attendance_message'])): ?>
                <div class="alert alert-info"><?= htmlspecialchars($_SESSION['attendance_message'], ENT_QUOTES, 'UTF-8'); ?></div>
                <?php unset($_SESSION['attendance_message']); ?>
            <?php endif; ?>
            <?php if (!isset($_SESSION['user'])): ?>
                <div class="alert alert-warning">Please log in to continue with attendance.</div>
                <div class="d-flex gap-2">
                    <a href="/Attendance/index.php?token=<?= urlencode($token); ?>" class="btn btn-primary btn-lg flex-fill">Login</a>
                    <a href="/Attendance/student/register.php?token=<?= urlencode($token); ?>" class="btn btn-outline-primary btn-lg flex-fill">Register</a>
                </div>
            <?php elseif ($student): ?>
                <?php if ($sessionOpen): ?>
                    <?php if ($otherAttendanceRecord): ?>
                        <div class="alert alert-info">
                            Attendance for this date was already started in <?= htmlspecialchars($otherAttendanceRecord['session_name']); ?>.
                            <?php if (!empty($otherAttendanceRecord['time_in'])): ?>Time In: <?= htmlspecialchars($otherAttendanceRecord['time_in']); ?>.<?php endif; ?>
                            <?php if (!empty($otherAttendanceRecord['time_out'])): ?>Time Out: <?= htmlspecialchars($otherAttendanceRecord['time_out']); ?>.<?php endif; ?>
                        </div>
                        <button type="button" class="btn btn-secondary btn-lg w-100" disabled>Attendance already recorded for this date</button>
                    <?php else: ?>
                    <div class="d-grid gap-2">
                        <?php if (!$attendanceRecord || empty($attendanceRecord['time_in'])): ?>
                            <form method="post" action="/Attendance/attendance/confirm.php">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="action" value="time_in">
                                <button type="submit" class="btn btn-primary btn-lg w-100">TIME IN</button>
                            </form>
                        <?php else: ?>
                            <button type="button" class="btn btn-success btn-lg" disabled>TIME IN recorded at <?= htmlspecialchars($attendanceRecord['time_in']); ?></button>
                        <?php endif; ?>
                        <?php if ($attendanceRecord && !empty($attendanceRecord['time_in']) && empty($attendanceRecord['time_out'])): ?>
                            <form method="post" action="/Attendance/attendance/confirm.php">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="action" value="time_out">
                                <button type="submit" class="btn btn-outline-primary btn-lg w-100">TIME OUT</button>
                            </form>
                        <?php elseif ($attendanceRecord && !empty($attendanceRecord['time_out'])): ?>
                            <button type="button" class="btn btn-success btn-lg" disabled>TIME OUT recorded at <?= htmlspecialchars($attendanceRecord['time_out']); ?></button>
                        <?php else: ?>
                            <button type="button" class="btn btn-outline-secondary btn-lg" disabled>TIME OUT (record TIME IN first)</button>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                <?php else: ?>
                    <button class="btn btn-secondary btn-lg w-100" disabled>Session Not Active</button>
                <?php endif; ?>
            <?php else: ?>
                <div class="alert alert-danger">Only registered students can submit attendance.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
