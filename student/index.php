<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole(['student']);

$user = currentUser();
$student = getStudentByUserId($conn, (int) $user['id']);
if (!$student) {
    redirect('/Attendance/logout.php');
}

$courseName = getCourseName($conn, (int) $student['course_id']);
$yearName = getYearName($conn, (int) $student['year_level_id']);
$sectionName = getSectionName($conn, (int) $student['section_id']);
$studentId = (int) $student['id'];

$countStmt = mysqli_prepare($conn, "SELECT
    SUM(status = 'PRESENT') AS present_count,
    SUM(status = 'LATE') AS late_count,
    SUM(status = 'ABSENT') AS absent_count,
    SUM(time_in IS NOT NULL AND time_out IS NULL) AS incomplete_count
    FROM attendance_records WHERE student_id = ?");
mysqli_stmt_bind_param($countStmt, 'i', $studentId);
mysqli_stmt_execute($countStmt);
$counts = mysqli_fetch_assoc(mysqli_stmt_get_result($countStmt));

$nowManila = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
$todayManila = $nowManila->format('Y-m-d');
$eventCountsStmt = mysqli_prepare($conn, "SELECT
    SUM(attendance.status = 'PRESENT') AS present_count,
    SUM(attendance.status = 'LATE') AS late_count
    FROM event_attendance attendance
    WHERE attendance.student_id = ?");
mysqli_stmt_bind_param($eventCountsStmt, 'i', $studentId);
mysqli_stmt_execute($eventCountsStmt);
$eventCounts = mysqli_fetch_assoc(mysqli_stmt_get_result($eventCountsStmt));

$eventAbsentStmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total
    FROM event_student_qrs qr
    JOIN events event ON event.id = qr.event_id
    LEFT JOIN event_attendance attendance ON attendance.event_id = qr.event_id AND attendance.student_id = qr.student_id
    WHERE qr.student_id = ? AND attendance.id IS NULL
      AND CONCAT(event.event_date, ' ', event.end_time) < ?");
$currentDateTime = $nowManila->format('Y-m-d H:i:s');
mysqli_stmt_bind_param($eventAbsentStmt, 'is', $studentId, $currentDateTime);
mysqli_stmt_execute($eventAbsentStmt);
$eventAbsentCount = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($eventAbsentStmt))['total'];

$missingStmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total
    FROM attendance_sessions session
    LEFT JOIN attendance_records record ON record.session_id = session.id AND record.student_id = ?
    WHERE session.session_date <= ?
      AND CONCAT(session.session_date, ' ', session.end_time) < ?
      AND session.status <> 'inactive'
      AND (session.course_id IS NULL OR session.course_id = ?)
      AND (session.year_level_id IS NULL OR session.year_level_id = ?)
      AND (session.section_id IS NULL OR session.section_id = ?)
      AND record.id IS NULL");
mysqli_stmt_bind_param($missingStmt, 'issiii', $studentId, $todayManila, $currentDateTime, $student['course_id'], $student['year_level_id'], $student['section_id']);
mysqli_stmt_execute($missingStmt);
$missingAttendance = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($missingStmt))['total'];
$absentCount = (int) ($counts['absent_count'] ?? 0) + $missingAttendance + $eventAbsentCount;

$historyStmt = mysqli_prepare($conn, 'SELECT record.*, session.session_name, session.session_date, session.end_time, session.status AS session_status, course.name AS course_name, year.name AS year_name, section.name AS section_name FROM attendance_records record JOIN attendance_sessions session ON session.id = record.session_id LEFT JOIN courses course ON course.id = record.course_id LEFT JOIN year_levels year ON year.id = record.year_level_id LEFT JOIN sections section ON section.id = record.section_id WHERE record.student_id = ? ORDER BY session.session_date DESC, session.id DESC LIMIT 10');
mysqli_stmt_bind_param($historyStmt, 'i', $studentId);
mysqli_stmt_execute($historyStmt);
$historyEntries = [];
$legacyHistory = mysqli_stmt_get_result($historyStmt);
while ($entry = mysqli_fetch_assoc($legacyHistory)) {
    $entry['entry_type'] = 'session';
    $entry['sort_at'] = $entry['session_date'] . ' ' . ($entry['time_in'] ?? '00:00:00');
    $historyEntries[] = $entry;
}
$eventHistoryStmt = mysqli_prepare($conn, 'SELECT event.event_name AS session_name, event.event_date AS session_date, event.end_time, event.status AS session_status, attendance.time_in, attendance.status FROM event_attendance attendance JOIN events event ON event.id = attendance.event_id WHERE attendance.student_id = ? ORDER BY attendance.time_in DESC LIMIT 10');
mysqli_stmt_bind_param($eventHistoryStmt, 'i', $studentId);
mysqli_stmt_execute($eventHistoryStmt);
$eventHistory = mysqli_stmt_get_result($eventHistoryStmt);
while ($entry = mysqli_fetch_assoc($eventHistory)) {
    $entry['entry_type'] = 'event';
    $entry['sort_at'] = $entry['time_in'];
    $historyEntries[] = $entry;
}
usort($historyEntries, static function (array $left, array $right): int {
    return strcmp($right['sort_at'], $left['sort_at']);
});
$historyEntries = array_slice($historyEntries, 0, 10);

$eventQrStmt = mysqli_prepare($conn, 'SELECT event.id, event.event_name, event.event_date, event.start_time, event.end_time, event.status FROM event_student_qrs qr JOIN events event ON event.id = qr.event_id WHERE qr.student_id = ? ORDER BY event.event_date DESC, event.id DESC');
mysqli_stmt_bind_param($eventQrStmt, 'i', $studentId);
mysqli_stmt_execute($eventQrStmt);
$eventQrs = mysqli_stmt_get_result($eventQrStmt);

$activeSession = null;
$activeStmt = mysqli_prepare($conn, 'SELECT * FROM attendance_sessions WHERE session_date = CURDATE() AND status = ? AND start_time <= CURTIME() AND end_time >= CURTIME() AND (course_id IS NULL OR course_id = ?) AND (year_level_id IS NULL OR year_level_id = ?) AND (section_id IS NULL OR section_id = ?) ORDER BY start_time LIMIT 1');
$activeStatus = 'active';
mysqli_stmt_bind_param($activeStmt, 'siii', $activeStatus, $student['course_id'], $student['year_level_id'], $student['section_id']);
mysqli_stmt_execute($activeStmt);
$activeSession = mysqli_fetch_assoc(mysqli_stmt_get_result($activeStmt));

$days = ['Day 1', 'Day 2', 'Day 3', 'Day 4', 'Day 5', 'Day 6'];
$daySummaries = [];
foreach ($days as $day) {
    $dayStmt = mysqli_prepare($conn, 'SELECT * FROM attendance_sessions WHERE session_name = ? AND (course_id IS NULL OR course_id = ?) AND (year_level_id IS NULL OR year_level_id = ?) AND (section_id IS NULL OR section_id = ?) ORDER BY session_date DESC, id DESC LIMIT 1');
    mysqli_stmt_bind_param($dayStmt, 'siii', $day, $student['course_id'], $student['year_level_id'], $student['section_id']);
    mysqli_stmt_execute($dayStmt);
    $daySession = mysqli_fetch_assoc(mysqli_stmt_get_result($dayStmt));
    if ($daySession) {
        $daySessionId = (int) $daySession['id'];
        $dayRecordStmt = mysqli_prepare($conn, 'SELECT time_in, time_out, status FROM attendance_records WHERE session_id = ? AND student_id = ? LIMIT 1');
        mysqli_stmt_bind_param($dayRecordStmt, 'ii', $daySessionId, $studentId);
        mysqli_stmt_execute($dayRecordStmt);
        $dayRecord = mysqli_fetch_assoc(mysqli_stmt_get_result($dayRecordStmt)) ?: [];
        $daySummaries[$day] = [
            'date' => $daySession['session_date'],
            'time_in' => $dayRecord['time_in'] ?? null,
            'time_out' => $dayRecord['time_out'] ?? null,
            'status' => attendanceStatus($dayRecord + ['session_status' => $daySession['status']], $daySession['session_date'] . ' ' . $daySession['end_time']),
        ];
    } else {
        $daySummaries[$day] = ['date' => null, 'time_in' => null, 'time_out' => null, 'status' => 'NOT SCHEDULED'];
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="card p-4 mb-4">
    <h4 class="fw-bold mb-3">Student Dashboard</h4>
    <div class="row g-3">
        <div class="col-md-4"><div class="border rounded-3 p-3 h-100"><div class="text-muted small">Student Name</div><strong><?= htmlspecialchars($student['full_name']); ?></strong></div></div>
        <div class="col-md-4"><div class="border rounded-3 p-3 h-100"><div class="text-muted small">Student ID</div><strong><?= htmlspecialchars($student['student_id']); ?></strong></div></div>
        <div class="col-md-4"><div class="border rounded-3 p-3 h-100"><div class="text-muted small">Course</div><strong><?= htmlspecialchars($courseName); ?></strong></div></div>
        <div class="col-md-4"><div class="border rounded-3 p-3 h-100"><div class="text-muted small">Year Level</div><strong><?= htmlspecialchars($yearName); ?></strong></div></div>
        <div class="col-md-4"><div class="border rounded-3 p-3 h-100"><div class="text-muted small">Section</div><strong><?= htmlspecialchars($sectionName); ?></strong></div></div>
        <div class="col-md-4"><div class="border rounded-3 p-3 h-100"><div class="text-muted small">Current Attendance Day</div><strong><?= htmlspecialchars($activeSession['session_name'] ?? 'No active session'); ?></strong></div></div>
    </div>
</div>

<div class="row g-4 mb-4">
    <?php foreach ([
        ['Present', (int) ($counts['present_count'] ?? 0) + (int) ($eventCounts['present_count'] ?? 0), 'success'],
        ['Late', (int) ($counts['late_count'] ?? 0) + (int) ($eventCounts['late_count'] ?? 0), 'warning'],
        ['Absent', $absentCount, 'secondary'],
        ['Incomplete Attendance', (int) ($counts['incomplete_count'] ?? 0), 'info'],
    ] as [$label, $total, $color]): ?>
        <div class="col-sm-6 col-xl-3"><div class="card p-3 stat-card h-100"><div class="text-muted small"><?= htmlspecialchars($label); ?></div><div class="fs-3 fw-bold text-<?= $color; ?>"><?= number_format($total); ?></div></div></div>
    <?php endforeach; ?>
</div>

<div class="card p-4 mb-4">
    <h5 class="fw-bold mb-3">My Event QR Codes</h5>
    <?php if (mysqli_num_rows($eventQrs) > 0): ?>
        <div class="row g-3">
            <?php while ($eventQr = mysqli_fetch_assoc($eventQrs)): ?>
                <?php
                $qrUrl = '/Attendance/student/event-qr.php?event_id=' . (int) $eventQr['id'];
                $modalId = 'eventQrModal' . (int) $eventQr['id'];
                ?>
                <div class="col-sm-6 col-xl-4">
                    <div class="border rounded-3 p-3 h-100">
                        <div class="d-flex justify-content-between gap-2">
                            <div>
                                <div class="fw-bold"><?= htmlspecialchars($eventQr['event_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <div class="small text-muted"><?= htmlspecialchars($eventQr['event_date'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <span class="badge bg-<?= $eventQr['status'] === 'active' ? 'success' : 'secondary'; ?>"><?= htmlspecialchars(ucfirst($eventQr['status']), ENT_QUOTES, 'UTF-8'); ?></span>
                                <div class="small"><?= htmlspecialchars($student['full_name'] . ' · ' . $student['student_id'], ENT_QUOTES, 'UTF-8'); ?></div>
                            </div>
                            <img src="<?= htmlspecialchars($qrUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="Personal QR code for <?= htmlspecialchars($eventQr['event_name'], ENT_QUOTES, 'UTF-8'); ?>" width="110" height="110" loading="lazy">
                        </div>
                        <div class="d-flex flex-wrap gap-2 mt-3">
                            <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars($qrUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">View QR Code</a>
                            <button class="btn btn-sm btn-outline-secondary enlarge-event-qr" type="button" data-bs-toggle="modal" data-bs-target="#<?= htmlspecialchars($modalId, ENT_QUOTES, 'UTF-8'); ?>">Enlarge QR Code</button>
                            <button class="btn btn-sm btn-primary download-event-qr" type="button" data-qr-url="<?= htmlspecialchars($qrUrl, ENT_QUOTES, 'UTF-8'); ?>" data-download-name="<?= htmlspecialchars(preg_replace('/[^A-Za-z0-9_-]+/', '-', $eventQr['event_name']) . '-QR.png', ENT_QUOTES, 'UTF-8'); ?>">Download QR Code</button>
                        </div>
                    </div>
                </div>
                <div class="modal fade" id="<?= htmlspecialchars($modalId, ENT_QUOTES, 'UTF-8'); ?>" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header"><h5 class="modal-title"><?= htmlspecialchars($eventQr['event_name'], ENT_QUOTES, 'UTF-8'); ?> · Personal QR</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                            <div class="modal-body text-center">
                                <img src="<?= htmlspecialchars($qrUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="Enlarged personal event QR code" class="img-fluid" style="max-width: 400px;">
                                <div class="mt-2"><?= htmlspecialchars($student['full_name'] . ' · ' . $student['student_id'], ENT_QUOTES, 'UTF-8'); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>
    <?php else: ?>
        <p class="text-muted mb-0">No event QR codes have been assigned to your account yet.</p>
    <?php endif; ?>
</div>

<div class="card p-4 mb-4">
    <h5 class="fw-bold mb-3">Six-Day Attendance Overview</h5>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead><tr><th>Day</th><th>Date</th><th>Time In</th><th>Time Out</th><th>Status</th></tr></thead>
            <tbody>
                <?php foreach ($days as $day): $summary = $daySummaries[$day]; ?>
                    <tr>
                        <td><?= htmlspecialchars($day); ?></td>
                        <td><?= htmlspecialchars($summary['date'] ?? 'N/A'); ?></td>
                        <td><?= htmlspecialchars($summary['time_in'] ?? 'N/A'); ?></td>
                        <td><?= htmlspecialchars($summary['time_out'] ?? 'N/A'); ?></td>
                        <td><?= htmlspecialchars($summary['status']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card p-4">
    <h5 class="fw-bold mb-3">Recent Attendance History</h5>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead><tr><th>Day</th><th>Date</th><th>Time In</th><th>Time Out</th><th>Status</th></tr></thead>
            <tbody>
                <?php foreach ($historyEntries as $entry): ?>
                    <?php $status = $entry['entry_type'] === 'event' ? strtoupper($entry['status']) : attendanceStatus($entry, $entry['session_date'] . ' ' . $entry['end_time']); ?>
                    <tr>
                        <td><?= htmlspecialchars($entry['session_name']); ?></td>
                        <td><?= htmlspecialchars($entry['session_date']); ?></td>
                        <td><?= htmlspecialchars($entry['time_in'] ? (strlen($entry['time_in']) > 8 ? substr($entry['time_in'], 11) : $entry['time_in']) : 'N/A'); ?></td>
                        <td><?= htmlspecialchars($entry['time_out'] ?? 'N/A'); ?></td>
                        <td><?= htmlspecialchars($status); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<script>
document.querySelectorAll('.download-event-qr').forEach((button) => {
    button.addEventListener('click', async () => {
        try {
            const image = new Image();
            image.src = button.dataset.qrUrl;
            await new Promise((resolve, reject) => {
                image.onload = resolve;
                image.onerror = reject;
            });
            const canvas = document.createElement('canvas');
            canvas.width = canvas.height = 800;
            const context = canvas.getContext('2d');
            context.fillStyle = '#fff';
            context.fillRect(0, 0, canvas.width, canvas.height);
            context.drawImage(image, 0, 0, canvas.width, canvas.height);
            const link = document.createElement('a');
            link.href = canvas.toDataURL('image/png');
            link.download = button.dataset.downloadName;
            link.click();
        } catch (error) {
            window.alert('QR code could not be downloaded. Reload the page and try again.');
        }
    });
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
