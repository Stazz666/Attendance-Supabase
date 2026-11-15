<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole(['faculty']);

$stats = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total_today FROM attendance_records WHERE DATE(attendance_date) = CURDATE()"));
$present = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM attendance_records WHERE DATE(attendance_date) = CURDATE() AND status = 'PRESENT'"));
$late = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM attendance_records WHERE DATE(attendance_date) = CURDATE() AND status = 'LATE'"));
$activeSessions = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM attendance_sessions WHERE session_date = CURDATE() AND start_time <= CURTIME() AND end_time >= CURTIME()"));

$recentEntries = mysqli_query($conn, "SELECT ar.*, s.full_name, c.name AS course_name, y.name AS year_name, sec.name AS section_name FROM attendance_records ar LEFT JOIN students s ON s.id = ar.student_id LEFT JOIN courses c ON c.id = ar.course_id LEFT JOIN year_levels y ON y.id = ar.year_level_id LEFT JOIN sections sec ON sec.id = ar.section_id ORDER BY ar.attendance_date DESC, ar.time_in DESC LIMIT 8");

include __DIR__ . '/../includes/header.php';
?>

<div class="row g-4 mb-4">
    <div class="col-md-3">
        <div class="card p-3 stat-card h-100">
            <div class="text-muted small">Total Records</div>
            <div class="fs-3 fw-bold"><?= number_format((int) $stats['total_today']); ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card p-3 stat-card h-100">
            <div class="text-muted small">Present</div>
            <div class="fs-3 fw-bold text-success"><?= number_format((int) $present['total']); ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card p-3 stat-card h-100">
            <div class="text-muted small">Late</div>
            <div class="fs-3 fw-bold text-warning"><?= number_format((int) $late['total']); ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card p-3 stat-card h-100">
            <div class="text-muted small">Active Sessions</div>
            <div class="fs-3 fw-bold text-primary"><?= number_format((int) $activeSessions['total']); ?></div>
        </div>
    </div>
</div>

<div class="card p-4">
    <h4 class="fw-bold mb-3">Recent Attendance</h4>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>Student</th>
                    <th>Course</th>
                    <th>Section</th>
                    <th>Date</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = mysqli_fetch_assoc($recentEntries)): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['full_name'] ?? 'Unknown'); ?></td>
                        <td><?= htmlspecialchars($row['course_name'] ?? 'Unknown'); ?></td>
                        <td><?= htmlspecialchars($row['section_name'] ?? 'Unknown'); ?></td>
                        <td><?= htmlspecialchars($row['attendance_date']); ?></td>
                        <td>
                            <span class="badge bg-<?= strtolower($row['status']) === 'present' ? 'success' : (strtolower($row['status']) === 'late' ? 'warning' : 'secondary'); ?> text-white">
                                <?= htmlspecialchars($row['status']); ?>
                            </span>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
