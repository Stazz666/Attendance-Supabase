<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole(['faculty']);

$sessions = mysqli_query($conn, "SELECT id, session_name, session_date, start_time, end_time, late_threshold, qr_token, status FROM attendance_sessions WHERE course_id IS NULL AND year_level_id IS NULL AND section_id IS NULL ORDER BY session_date DESC, FIELD(session_name, 'Day 1','Day 2','Day 3','Day 4','Day 5','Day 6')");

include __DIR__ . '/../includes/header.php';
?>

<div class="card p-4">
    <h4 class="fw-bold mb-3">Global Attendance Sessions</h4>
    <p class="text-muted">Attendance sessions and QR codes are managed centrally by the administrator.</p>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr><th>Session</th><th>Date</th><th>Schedule</th><th>Status</th><th>QR Code</th><th>Link</th></tr>
            </thead>
            <tbody>
                <?php while ($session = mysqli_fetch_assoc($sessions)): ?>
                    <?php $qrLink = globalAppUrl('attendance/index.php?token=' . urlencode($session['qr_token'])); ?>
                    <tr>
                        <td><?= htmlspecialchars($session['session_name']); ?></td>
                        <td><?= htmlspecialchars($session['session_date']); ?></td>
                        <td><?= htmlspecialchars($session['start_time']); ?> - <?= htmlspecialchars($session['end_time']); ?></td>
                        <td><?= htmlspecialchars(getSessionStatusLabel($session['status'])); ?></td>
                        <td><img src="<?= htmlspecialchars(globalAppUrl('admin/qr.php?id=' . (int) $session['id']), ENT_QUOTES, 'UTF-8'); ?>" alt="QR code for <?= htmlspecialchars($session['session_name'], ENT_QUOTES, 'UTF-8'); ?>" width="120" height="120"></td>
                        <td><a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars($qrLink, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">Open Attendance</a></td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
