<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole(['admin']);

$totalStudents = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT COUNT(*) AS total FROM students'))['total'];
$totalBSCS = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM students s JOIN courses c ON c.id = s.course_id WHERE c.code = 'BSCS'"))['total'];
$totalBSCpE = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM students s JOIN courses c ON c.id = s.course_id WHERE c.code = 'BSCpE'"))['total'];
$totalFirst = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM students s JOIN year_levels y ON y.id = s.year_level_id WHERE y.name = '1st Year'"))['total'];
$totalSecond = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM students s JOIN year_levels y ON y.id = s.year_level_id WHERE y.name = '2nd Year'"))['total'];
$totalThird = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM students s JOIN year_levels y ON y.id = s.year_level_id WHERE y.name = '3rd Year'"))['total'];
$totalFourth = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM students s JOIN year_levels y ON y.id = s.year_level_id WHERE y.name = '4th Year'"))['total'];
$nowManila = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
$todayManila = $nowManila->format('Y-m-d');
$currentDateTime = $nowManila->format('Y-m-d H:i:s');
$todayStatsStmt = mysqli_prepare($conn, "SELECT
    COUNT(DISTINCT CASE WHEN status = 'PRESENT' THEN student_id END) AS present_total,
    COUNT(DISTINCT CASE WHEN status = 'LATE' THEN student_id END) AS late_total,
    COUNT(DISTINCT student_id) AS time_in_total
    FROM (
        SELECT student_id, status FROM attendance_records WHERE attendance_date = ? AND time_in IS NOT NULL
        UNION ALL
        SELECT student_id, status FROM event_attendance WHERE DATE(time_in) = ?
    ) today_attendance");
mysqli_stmt_bind_param($todayStatsStmt, 'ss', $todayManila, $todayManila);
mysqli_stmt_execute($todayStatsStmt);
$todayStats = mysqli_fetch_assoc(mysqli_stmt_get_result($todayStatsStmt));
$presentToday = (int) $todayStats['present_total'];
$lateToday = (int) $todayStats['late_total'];
$timeInToday = (int) $todayStats['time_in_total'];
$timeOutToday = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM attendance_records WHERE attendance_date = CURDATE() AND time_out IS NOT NULL"))['total'];
$incompleteToday = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM attendance_records WHERE attendance_date = CURDATE() AND time_in IS NOT NULL AND time_out IS NULL"))['total'];
$legacyAbsentToday = (int) mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM attendance_sessions session JOIN students student ON (session.course_id IS NULL OR session.course_id = student.course_id) AND (session.year_level_id IS NULL OR session.year_level_id = student.year_level_id) AND (session.section_id IS NULL OR session.section_id = student.section_id) LEFT JOIN attendance_records record ON record.session_id = session.id AND record.student_id = student.id WHERE session.session_date = CURDATE() AND session.end_time < CURTIME() AND session.status <> 'inactive' AND record.time_in IS NULL"))['total'];
$eventAbsentStmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total
    FROM event_student_qrs qr
    JOIN events event ON event.id = qr.event_id
    LEFT JOIN event_attendance attendance ON attendance.event_id = qr.event_id AND attendance.student_id = qr.student_id
    WHERE CONCAT(event.event_date, ' ', event.end_time) < ? AND attendance.id IS NULL");
mysqli_stmt_bind_param($eventAbsentStmt, 's', $currentDateTime);
mysqli_stmt_execute($eventAbsentStmt);
$eventAbsentToday = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($eventAbsentStmt))['total'];
$absentToday = $legacyAbsentToday + $eventAbsentToday;

$recentRecords = mysqli_query($conn, "SELECT * FROM (
        SELECT ar.time_in, ar.time_out, ar.status, s.full_name, c.name AS course_name,
               sec.name AS section_name, session.session_name,
               CONCAT(ar.attendance_date, ' ', COALESCE(ar.time_in, '00:00:00')) AS sort_at,
               'session' AS entry_type
        FROM attendance_records ar
        JOIN attendance_sessions session ON session.id = ar.session_id
        LEFT JOIN students s ON s.id = ar.student_id
        LEFT JOIN courses c ON c.id = s.course_id
        LEFT JOIN sections sec ON sec.id = s.section_id
        UNION ALL
        SELECT TIME(attendance.time_in) AS time_in, NULL AS time_out, attendance.status,
               student.full_name, course.name AS course_name, section.name AS section_name,
               event.event_name AS session_name, attendance.time_in AS sort_at,
               'event' AS entry_type
        FROM event_attendance attendance
        JOIN events event ON event.id = attendance.event_id
        JOIN students student ON student.id = attendance.student_id
        LEFT JOIN courses course ON course.id = student.course_id
        LEFT JOIN sections section ON section.id = student.section_id
    ) recent_activity
    ORDER BY sort_at DESC LIMIT 6");
$activeSessions = mysqli_query($conn, "SELECT asn.* FROM attendance_sessions asn WHERE asn.session_date = CURDATE() AND asn.status = 'active' AND asn.course_id IS NULL AND asn.year_level_id IS NULL AND asn.section_id IS NULL AND asn.start_time <= CURTIME() AND asn.end_time >= CURTIME() ORDER BY asn.start_time ASC LIMIT 6");
$dayStats = mysqli_query($conn, "SELECT session.session_name, COUNT(record.id) AS total FROM attendance_sessions session LEFT JOIN attendance_records record ON record.session_id = session.id WHERE session.course_id IS NULL AND session.year_level_id IS NULL AND session.section_id IS NULL GROUP BY session.id, session.session_name ORDER BY session.session_date DESC, FIELD(session.session_name, 'Day 1','Day 2','Day 3','Day 4','Day 5','Day 6')");

$studentsByCourse = mysqli_query($conn, "SELECT c.name, COUNT(s.id) AS total FROM students s RIGHT JOIN courses c ON c.id = s.course_id GROUP BY c.id");
$studentsByYear = mysqli_query($conn, "SELECT y.name, COUNT(s.id) AS total FROM students s RIGHT JOIN year_levels y ON y.id = s.year_level_id GROUP BY y.id");

include __DIR__ . '/../includes/header.php';
?>

<div class="card p-3 mb-4">
    <h5 class="fw-bold mb-3">Event Attendance</h5>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-primary" href="/Attendance/admin/events.php">Create Event &amp; Generate QR</a>
        <a class="btn btn-outline-primary" href="/Attendance/admin/scanner.php">QR Scanner</a>
        <a class="btn btn-outline-secondary" href="/Attendance/admin/events.php">Event Management</a>
        <a class="btn btn-outline-secondary" href="/Attendance/admin/event-attendance.php">Event Attendance</a>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-xl-3 col-md-6">
        <div class="card stat-card h-100 p-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small">Total Students</div>
                    <div class="fs-3 fw-bold"><?= number_format($totalStudents); ?></div>
                </div>
                <div class="fs-1 text-primary"><i class="bi bi-people-fill"></i></div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card stat-card h-100 p-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small">BSCS</div>
                    <div class="fs-3 fw-bold"><?= number_format($totalBSCS); ?></div>
                </div>
                <div class="fs-1 text-primary"><i class="bi bi-mortarboard-fill"></i></div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card stat-card h-100 p-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small">BSCpE</div>
                    <div class="fs-3 fw-bold"><?= number_format($totalBSCpE); ?></div>
                </div>
                <div class="fs-1 text-primary"><i class="bi bi-bookmark-check-fill"></i></div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card stat-card h-100 p-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small">Present Today</div>
                    <div class="fs-3 fw-bold"><?= number_format($presentToday); ?></div>
                </div>
                <div class="fs-1 text-success"><i class="bi bi-check-circle-fill"></i></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-xl-3 col-md-6">
        <div class="card p-3 h-100">
            <div class="text-muted small">1st Year</div>
            <div class="fs-3 fw-bold"><?= number_format($totalFirst); ?></div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card p-3 h-100">
            <div class="text-muted small">2nd Year</div>
            <div class="fs-3 fw-bold"><?= number_format($totalSecond); ?></div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card p-3 h-100">
            <div class="text-muted small">3rd Year</div>
            <div class="fs-3 fw-bold"><?= number_format($totalThird); ?></div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card p-3 h-100">
            <div class="text-muted small">4th Year</div>
            <div class="fs-3 fw-bold"><?= number_format($totalFourth); ?></div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-4">
        <div class="card p-3 h-100">
            <h5 class="fw-bold mb-3">Today's Attendance</h5>
            <div class="d-flex justify-content-between mb-2"><span>Present</span><strong><?= number_format($presentToday); ?></strong></div>
            <div class="d-flex justify-content-between mb-2"><span>Late</span><strong><?= number_format($lateToday); ?></strong></div>
            <div class="d-flex justify-content-between mb-2"><span>Absent</span><strong><?= number_format($absentToday); ?></strong></div>
            <div class="d-flex justify-content-between mb-2"><span>Time In</span><strong><?= number_format($timeInToday); ?></strong></div>
            <div class="d-flex justify-content-between mb-2"><span>Time Out</span><strong><?= number_format($timeOutToday); ?></strong></div>
            <div class="d-flex justify-content-between"><span>Incomplete</span><strong><?= number_format($incompleteToday); ?></strong></div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card p-3 h-100">
            <h5 class="fw-bold mb-3">Attendance Overview</h5>
            <canvas id="attendanceChart"></canvas>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card p-3 h-100">
            <h5 class="fw-bold mb-3">Students by Course</h5>
            <canvas id="courseChart"></canvas>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card p-3 h-100">
            <h5 class="fw-bold mb-3">Students by Year Level</h5>
            <canvas id="yearChart"></canvas>
        </div>
    </div>
</div>

<div class="row g-4 mt-1">
    <div class="col-lg-7">
        <div class="card p-3 h-100">
            <h5 class="fw-bold mb-3">Recent Attendance Activity</h5>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Course</th>
                            <th>Section</th>
                            <th>Day / Event</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = mysqli_fetch_assoc($recentRecords)): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['full_name'] ?? 'Unknown'); ?></td>
                                <td><?= htmlspecialchars($row['course_name'] ?? 'Unknown'); ?></td>
                                <td><?= htmlspecialchars($row['section_name'] ?? 'Unknown'); ?></td>
                                <td><?= htmlspecialchars($row['session_name']); ?></td>
                                <td>
                                    <?php $displayStatus = $row['entry_type'] === 'event' ? strtoupper($row['status']) : attendanceStatus($row); ?>
                                    <span class="badge bg-<?= $displayStatus === 'PRESENT' ? 'success' : ($displayStatus === 'LATE' ? 'warning' : ($displayStatus === 'INCOMPLETE' ? 'info' : 'secondary')); ?> text-white">
                                        <?= htmlspecialchars($displayStatus); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card p-3 h-100">
            <h5 class="fw-bold mb-3">Active Attendance Sessions</h5>
            <?php if (mysqli_num_rows($activeSessions) > 0): ?>
                <div class="list-group list-group-flush">
                    <?php while ($session = mysqli_fetch_assoc($activeSessions)): ?>
                        <div class="list-group-item px-0">
                            <div class="d-flex justify-content-between">
                                <strong><?= htmlspecialchars($session['session_name'] ?? 'Session'); ?></strong>
                                <span class="badge bg-success">Active</span>
                            </div>
                            <div class="small text-muted">Global attendance session for all registered students</div>
                            <div class="small text-muted"><?= htmlspecialchars($session['start_time']); ?> to <?= htmlspecialchars($session['end_time']); ?></div>
                        </div>
                    <?php endwhile; ?>
                </div>
            <?php else: ?>
                <p class="text-muted mb-0">No active attendance sessions today.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card p-3 mt-4">
    <h5 class="fw-bold mb-3">Attendance Statistics by Day</h5>
    <div class="row g-3">
        <?php $dayTotals = []; while ($dayRow = mysqli_fetch_assoc($dayStats)) { $dayTotals[$dayRow['session_name']] = (int) $dayRow['total']; } ?>
        <?php foreach (['Day 1', 'Day 2', 'Day 3', 'Day 4', 'Day 5', 'Day 6'] as $dayName): ?>
            <div class="col-6 col-md-4 col-xl-2">
                <div class="border rounded-3 p-3 h-100">
                    <div class="text-muted small"><?= htmlspecialchars($dayName); ?></div>
                    <div class="fs-4 fw-bold"><?= number_format($dayTotals[$dayName] ?? 0); ?></div>
                    <div class="small text-muted">attendance records</div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
  const attendanceLabels = <?= json_encode(['Present', 'Late', 'Absent', 'Incomplete'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
  const attendanceTotals = <?= json_encode([(int) $presentToday, (int) $lateToday, (int) $absentToday, (int) $incompleteToday]); ?>;
  const attendanceData = {
    labels: attendanceLabels,
    datasets: [{
      label: 'Today',
      data: attendanceTotals,
      backgroundColor: ['#1abc9c', '#f4b740', '#7a8699', '#2b6eea']
    }]
  };

  new Chart(document.getElementById('attendanceChart'), {
    type: 'bar',
    data: attendanceData,
    options: { responsive: true, maintainAspectRatio: false }
  });

  const courseNames = [];
  const courseTotals = [];
  <?php while ($row = mysqli_fetch_assoc($studentsByCourse)): ?>
    courseNames.push(<?= json_encode($row['name'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>);
    courseTotals.push(<?= (int) $row['total']; ?>);
  <?php endwhile; ?>

  new Chart(document.getElementById('courseChart'), {
    type: 'pie',
    data: {
      labels: courseNames,
      datasets: [{ data: courseTotals, backgroundColor: ['#2b6eea', '#5b9df9'] }]
    },
    options: { responsive: true, maintainAspectRatio: false }
  });

  const yearNames = [];
  const yearTotals = [];
  <?php while ($row = mysqli_fetch_assoc($studentsByYear)): ?>
    yearNames.push(<?= json_encode($row['name'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>);
    yearTotals.push(<?= (int) $row['total']; ?>);
  <?php endwhile; ?>

  new Chart(document.getElementById('yearChart'), {
    type: 'doughnut',
    data: {
      labels: yearNames,
      datasets: [{ data: yearTotals, backgroundColor: ['#3b82f6', '#60a5fa', '#93c5fd', '#dbeafe'] }]
    },
    options: { responsive: true, maintainAspectRatio: false }
  });
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
