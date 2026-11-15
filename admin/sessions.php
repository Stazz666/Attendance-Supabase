<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole(['admin']);

$message = '';
$messageType = 'success';

if (isset($_GET['updated'])) {
    $message = 'Attendance session updated successfully.';
} elseif (isset($_GET['deleted'])) {
    $message = 'Attendance session deleted successfully.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfTokenIsValid()) {
        $message = 'Your form session expired. Reload the page and try again.';
        $messageType = 'danger';
    } elseif (($_POST['action'] ?? '') === 'toggle_session') {
        $sessionId = filter_var($_POST['session_id'] ?? null, FILTER_VALIDATE_INT);
        $newStatus = requestString($_POST, 'status');
        if ($sessionId && in_array($newStatus, ['active', 'inactive', 'expired'], true)) {
            $stmt = mysqli_prepare($conn, 'UPDATE attendance_sessions SET status = ? WHERE id = ?');
            mysqli_stmt_bind_param($stmt, 'si', $newStatus, $sessionId);
            mysqli_stmt_execute($stmt);
            $message = 'Session status updated successfully.';
        } else {
            $message = 'Invalid session status request.';
            $messageType = 'danger';
        }
    }
    elseif (($_POST['action'] ?? '') === 'delete_session') {
        $sessionId = filter_var($_POST['session_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$sessionId || $sessionId <= 0) {
            $message = 'Invalid attendance session.';
            $messageType = 'danger';
        } else {
            try {
                $delete = mysqli_prepare($conn, 'DELETE FROM attendance_sessions WHERE id = ? AND course_id IS NULL AND year_level_id IS NULL AND section_id IS NULL AND NOT EXISTS (SELECT 1 FROM attendance_records WHERE session_id = ?)');
                mysqli_stmt_bind_param($delete, 'ii', $sessionId, $sessionId);
                mysqli_stmt_execute($delete);
                if (mysqli_stmt_affected_rows($delete) === 1) {
                    redirect('/Attendance/admin/sessions.php?deleted=1');
                }

                $check = mysqli_prepare($conn, 'SELECT EXISTS(SELECT 1 FROM attendance_sessions WHERE id = ? AND course_id IS NULL AND year_level_id IS NULL AND section_id IS NULL) AS session_exists, EXISTS(SELECT 1 FROM attendance_records WHERE session_id = ?) AS has_records');
                mysqli_stmt_bind_param($check, 'ii', $sessionId, $sessionId);
                mysqli_stmt_execute($check);
                $deleteState = mysqli_fetch_assoc(mysqli_stmt_get_result($check));
                if (!$deleteState || !(int) $deleteState['session_exists']) {
                    $message = 'Attendance session was not found.';
                } elseif ((int) $deleteState['has_records']) {
                    $message = 'This session has attendance records and cannot be deleted. Deactivate or expire it instead.';
                } else {
                    $message = 'Attendance session could not be deleted. Please try again.';
                }
                $messageType = 'danger';
            } catch (mysqli_sql_exception $exception) {
                error_log('Attendance session deletion failed: ' . $exception->getMessage());
                $message = $exception->getCode() === 1451
                    ? 'This session has attendance records and cannot be deleted. Deactivate or expire it instead.'
                    : 'Attendance session could not be deleted. Please try again.';
                $messageType = 'danger';
            }
        }
    }
    elseif (($_POST['action'] ?? '') === 'create_session') {
        $sessionName = requestString($_POST, 'session_name');
        $sessionDate = requestString($_POST, 'session_date');
        $startTime = requestString($_POST, 'start_time');
        $endTime = requestString($_POST, 'end_time');
        $lateThreshold = requestString($_POST, 'late_threshold');
        $days = ['Day 1', 'Day 2', 'Day 3', 'Day 4', 'Day 5', 'Day 6'];
        $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $sessionDate, new DateTimeZone('Asia/Manila'));
        $validDate = $parsedDate && $parsedDate->format('Y-m-d') === $sessionDate;
        $validTimes = preg_match('/^\d{2}:\d{2}$/', $startTime)
            && preg_match('/^\d{2}:\d{2}$/', $endTime)
            && preg_match('/^\d{2}:\d{2}$/', $lateThreshold)
            && $startTime < $endTime
            && $lateThreshold >= $startTime
            && $lateThreshold <= $endTime;

        if (!in_array($sessionName, $days, true) || !$validDate || !$validTimes) {
            $message = 'Choose a Day 1–Day 6 session, a valid date, and times where Start < Late ≤ End.';
            $messageType = 'danger';
        } else {
            $stmt = mysqli_prepare($conn, 'SELECT id FROM attendance_sessions WHERE session_name = ? AND session_date = ? LIMIT 1');
            mysqli_stmt_bind_param($stmt, 'ss', $sessionName, $sessionDate);
            mysqli_stmt_execute($stmt);
            if (mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))) {
                $message = 'This session already exists for the selected date.';
                $messageType = 'danger';
            } else {
                $globalDateStmt = mysqli_prepare($conn, 'SELECT id FROM attendance_sessions WHERE session_date = ? AND course_id IS NULL AND year_level_id IS NULL AND section_id IS NULL LIMIT 1');
                mysqli_stmt_bind_param($globalDateStmt, 's', $sessionDate);
                mysqli_stmt_execute($globalDateStmt);
                if (mysqli_fetch_assoc(mysqli_stmt_get_result($globalDateStmt))) {
                    $message = 'A global attendance session already exists for the selected date.';
                    $messageType = 'danger';
                } else {
                try {
                    $token = bin2hex(random_bytes(32));
                    $creatorId = (int) $_SESSION['user']['id'];
                    $creatorRole = 'admin';
                    $status = 'active';
                    $stmt = mysqli_prepare($conn, 'INSERT INTO attendance_sessions (creator_id, creator_role, session_name, course_id, year_level_id, section_id, session_date, start_time, end_time, late_threshold, qr_token, status) VALUES (?, ?, ?, NULL, NULL, NULL, ?, ?, ?, ?, ?, ?)');
                    mysqli_stmt_bind_param($stmt, 'issssssss', $creatorId, $creatorRole, $sessionName, $sessionDate, $startTime, $endTime, $lateThreshold, $token, $status);
                    mysqli_stmt_execute($stmt);
                    $message = 'Global attendance session created successfully.';
                } catch (mysqli_sql_exception $exception) {
                    if ($exception->getCode() === 1062) {
                        $message = 'This session already exists for the selected date.';
                        $messageType = 'danger';
                    } else {
                        error_log('Global attendance session creation failed: ' . $exception->getMessage());
                        $message = 'The session could not be saved. Check the database schema and try again.';
                        $messageType = 'danger';
                    }
                }
                }
            }
        }
    }
}

$sessions = mysqli_query($conn, "SELECT session.*, (SELECT COUNT(*) FROM attendance_records WHERE session_id = session.id) AS attendance_count FROM attendance_sessions session WHERE course_id IS NULL AND year_level_id IS NULL AND section_id IS NULL ORDER BY session_date DESC, FIELD(session_name, 'Day 1','Day 2','Day 3','Day 4','Day 5','Day 6'), id DESC");

include __DIR__ . '/../includes/header.php';
?>

<div class="card p-4 mb-4">
    <h4 class="fw-bold mb-3">Centralized QR Attendance Sessions</h4>
    <?php if ($message !== ''): ?>
        <div class="alert alert-<?= htmlspecialchars($messageType); ?>"><?= htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <form method="post" class="row g-3">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="action" value="create_session">
        <div class="col-md-3">
            <label class="form-label">Session Name</label>
            <select class="form-select" name="session_name" required>
                <option value="">Select</option>
                <?php foreach (['Day 1','Day 2','Day 3','Day 4','Day 5','Day 6'] as $day): ?>
                    <option value="<?= htmlspecialchars($day); ?>"><?= htmlspecialchars($day); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Attendance Date</label>
            <input type="date" class="form-control" name="session_date" value="<?= date('Y-m-d'); ?>" required>
        </div>
        <div class="col-md-2">
            <label class="form-label">Start Time</label>
            <input type="time" class="form-control" name="start_time" value="08:00" required>
        </div>
        <div class="col-md-2">
            <label class="form-label">End Time</label>
            <input type="time" class="form-control" name="end_time" value="17:00" required>
        </div>
        <div class="col-md-2">
            <label class="form-label">Late Time</label>
            <input type="time" class="form-control" name="late_threshold" value="08:15" required>
        </div>
        <div class="col-12">
            <button class="btn btn-primary" type="submit">Generate Global QR Session</button>
        </div>
    </form>
</div>

<div class="card p-4">
    <h5 class="fw-bold mb-3">Generated QR Codes</h5>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>Session</th>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Status</th>
                    <th>QR</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($session = mysqli_fetch_assoc($sessions)): ?>
                    <?php $qrLink = globalAppUrl('attendance/index.php?token=' . urlencode($session['qr_token'])); ?>
                    <tr>
                        <td><?= htmlspecialchars($session['session_name']); ?></td>
                        <td><?= htmlspecialchars($session['session_date']); ?></td>
                        <td><?= htmlspecialchars($session['start_time']); ?> - <?= htmlspecialchars($session['end_time']); ?></td>
                        <td>
                            <form method="post" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="action" value="toggle_session">
                                <input type="hidden" name="session_id" value="<?= (int) $session['id']; ?>">
                                <select class="form-select form-select-sm d-inline-block" name="status" onchange="this.form.submit()" style="width: auto;">
                                    <option value="active" <?= $session['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?= $session['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                    <option value="expired" <?= $session['status'] === 'expired' ? 'selected' : ''; ?>>Expired</option>
                                </select>
                            </form>
                        </td>
                        <td>
                            <span class="qr-code"><img src="<?= htmlspecialchars(globalAppUrl('admin/qr.php?id=' . (int) $session['id']), ENT_QUOTES, 'UTF-8'); ?>" alt="QR code for <?= htmlspecialchars($session['session_name'], ENT_QUOTES, 'UTF-8'); ?>" width="120" height="120"></span>
                        </td>
                        <td>
                            <div class="d-flex gap-2 flex-wrap">
                                <?php if ((int) $session['attendance_count'] === 0): ?>
                                    <a class="btn btn-sm btn-outline-primary" href="/Attendance/admin/session-edit.php?id=<?= (int) $session['id']; ?>">Edit</a>
                                <?php else: ?>
                                    <button class="btn btn-sm btn-outline-secondary" type="button" disabled title="Sessions with attendance records cannot be edited.">Edit</button>
                                <?php endif; ?>
                                <a class="btn btn-sm btn-primary" href="<?= htmlspecialchars($qrLink, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">Open</a>
                                <button class="btn btn-sm btn-outline-primary download-qr" type="button" data-download-name="<?= htmlspecialchars($session['session_name'] . '-' . $session['session_date'] . '.png', ENT_QUOTES, 'UTF-8'); ?>">Download PNG</button>
                                <button class="btn btn-sm btn-outline-secondary copy-link" type="button" data-copy-value="<?= htmlspecialchars($qrLink, ENT_QUOTES, 'UTF-8'); ?>">Copy Link</button>
                                <button class="btn btn-sm btn-outline-dark" type="button" onclick="window.print()">Print</button>
                                <form method="post" onsubmit="return confirm('Delete this attendance session? Sessions with attendance records cannot be deleted.');">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="action" value="delete_session">
                                    <input type="hidden" name="session_id" value="<?= (int) $session['id']; ?>">
                                    <button class="btn btn-sm btn-outline-danger" type="submit" <?= (int) $session['attendance_count'] > 0 ? 'disabled title="Sessions with attendance records cannot be deleted."' : ''; ?>>Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    document.querySelectorAll('.download-qr').forEach((button) => {
        button.addEventListener('click', () => {
            const code = button.closest('tr').querySelector('.qr-code');
            const image = new Image();
            image.onload = () => {
                const canvas = document.createElement('canvas');
                canvas.width = 640;
                canvas.height = 640;
                const context = canvas.getContext('2d');
                context.fillStyle = '#fff';
                context.fillRect(0, 0, canvas.width, canvas.height);
                context.drawImage(image, 0, 0, canvas.width, canvas.height);
                const link = document.createElement('a');
                link.href = canvas.toDataURL('image/png');
                link.download = button.dataset.downloadName;
                link.click();
            };
            image.onerror = () => window.alert('QR code could not be loaded. Reload the page and try again.');
            image.src = code.querySelector('img').src;
        });
    });

    document.querySelectorAll('.copy-link').forEach((button) => {
        button.addEventListener('click', async () => {
            try {
                await navigator.clipboard.writeText(button.dataset.copyValue);
                button.textContent = 'Copied';
            } catch (error) {
                window.prompt('Copy this attendance link:', button.dataset.copyValue);
            }
        });
    });
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
