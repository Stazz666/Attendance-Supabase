<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole(['student']);

$student = getStudentByUserId($conn, (int) currentUser()['id']);
if (!$student) {
    redirect('/Attendance/logout.php');
}

$stmt = mysqli_prepare($conn, "SELECT record.*, session.session_name, session.session_date, session.end_time, session.status AS session_status, 'session' AS entry_type, CONCAT(session.session_date, ' ', COALESCE(record.time_in, '00:00:00')) AS sort_at FROM attendance_records record JOIN attendance_sessions session ON session.id = record.session_id WHERE record.student_id = ?");
$studentId = (int) $student['id'];
mysqli_stmt_bind_param($stmt, 'i', $studentId);
mysqli_stmt_execute($stmt);
$records = mysqli_stmt_get_result($stmt);
$attendanceEntries = [];
while ($row = mysqli_fetch_assoc($records)) {
    $attendanceEntries[] = $row;
}

$now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
$currentDateTime = $now->format('Y-m-d H:i:s');
$eventStmt = mysqli_prepare($conn, "SELECT event.event_name AS session_name, event.event_date AS session_date, event.end_time,
        event.status AS session_status, DATE_FORMAT(attendance.time_in, '%H:%i:%s') AS time_in,
        NULL AS time_out,
        CASE WHEN attendance.status IS NOT NULL THEN attendance.status
             WHEN CONCAT(event.event_date, ' ', event.end_time) < ? THEN 'ABSENT'
             ELSE 'NOT CHECKED IN' END AS status,
        'event' AS entry_type,
        COALESCE(attendance.time_in, CONCAT(event.event_date, ' 00:00:00')) AS sort_at
    FROM event_student_qrs qr
    JOIN events event ON event.id = qr.event_id
    LEFT JOIN event_attendance attendance ON attendance.event_id = qr.event_id AND attendance.student_id = qr.student_id
    WHERE qr.student_id = ?");
mysqli_stmt_bind_param($eventStmt, 'si', $currentDateTime, $studentId);
mysqli_stmt_execute($eventStmt);
$eventRecords = mysqli_stmt_get_result($eventStmt);
while ($row = mysqli_fetch_assoc($eventRecords)) {
    $attendanceEntries[] = $row;
}
usort($attendanceEntries, static function (array $left, array $right): int {
    return strcmp($right['sort_at'], $left['sort_at']);
});

include __DIR__ . '/../includes/header.php';
?>

<div class="card p-4">
    <h4 class="fw-bold mb-3">My Attendance</h4>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead><tr><th>Session / Event</th><th>Date</th><th>Time In</th><th>Time Out</th><th>Status</th></tr></thead>
            <tbody>
                <?php foreach ($attendanceEntries as $row): ?>
                    <?php $status = $row['entry_type'] === 'event' ? $row['status'] : attendanceStatus($row, $row['session_date'] . ' ' . $row['end_time']); ?>
                    <tr>
                        <td><?= htmlspecialchars($row['session_name']); ?></td>
                        <td><?= htmlspecialchars($row['session_date']); ?></td>
                        <td><?= htmlspecialchars($row['time_in'] ?? 'N/A'); ?></td>
                        <td><?= htmlspecialchars($row['time_out'] ?? 'N/A'); ?></td>
                        <td><?= htmlspecialchars($status); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
