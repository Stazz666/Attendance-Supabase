<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole(['student']);

$user = currentUser();
$student = getStudentByUserId($conn, $user['id']);
if (!$student) {
    redirect('/Attendance/logout.php');
}

$courseName = getCourseName($conn, $student['course_id']);
$yearName = getYearName($conn, $student['year_level_id']);
$sectionName = getSectionName($conn, $student['section_id']);

include __DIR__ . '/../includes/header.php';
?>

<div class="card p-4">
    <h4 class="fw-bold mb-4">My Profile</h4>
    <div class="row g-3">
        <div class="col-md-6">
            <div class="card bg-light p-3 h-100">
                <div class="text-muted small">Student ID</div>
                <div class="fs-5 fw-bold"><?= htmlspecialchars($student['student_id']); ?></div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card bg-light p-3 h-100">
                <div class="text-muted small">Full Name</div>
                <div class="fs-5 fw-bold"><?= htmlspecialchars($student['full_name']); ?></div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card bg-light p-3 h-100">
                <div class="text-muted small">Email</div>
                <div class="fs-5 fw-bold"><?= htmlspecialchars($student['email']); ?></div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card bg-light p-3 h-100">
                <div class="text-muted small">Course</div>
                <div class="fs-5 fw-bold"><?= htmlspecialchars($courseName); ?></div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card bg-light p-3 h-100">
                <div class="text-muted small">Year Level</div>
                <div class="fs-5 fw-bold"><?= htmlspecialchars($yearName); ?></div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card bg-light p-3 h-100">
                <div class="text-muted small">Section</div>
                <div class="fs-5 fw-bold"><?= htmlspecialchars($sectionName); ?></div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
