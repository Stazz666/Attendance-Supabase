<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole(['faculty']);

$records = mysqli_query($conn, "SELECT ar.*, s.full_name, c.name AS course_name, y.name AS year_name, sec.name AS section_name FROM attendance_records ar LEFT JOIN students s ON s.id = ar.student_id LEFT JOIN courses c ON c.id = ar.course_id LEFT JOIN year_levels y ON y.id = ar.year_level_id LEFT JOIN sections sec ON sec.id = ar.section_id ORDER BY ar.attendance_date DESC, ar.time_in DESC LIMIT 20");

include __DIR__ . '/../includes/header.php';
?>

<div class="card p-4">
    <h4 class="fw-bold mb-3">Faculty Attendance Records</h4>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>Student</th>
                    <th>Course</th>
                    <th>Year</th>
                    <th>Section</th>
                    <th>Date</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = mysqli_fetch_assoc($records)): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['full_name'] ?? 'Unknown'); ?></td>
                        <td><?= htmlspecialchars($row['course_name'] ?? 'Unknown'); ?></td>
                        <td><?= htmlspecialchars($row['year_name'] ?? 'Unknown'); ?></td>
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
