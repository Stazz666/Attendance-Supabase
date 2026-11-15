<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole(['admin']);

$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfTokenIsValid()) {
        $message = 'Your form session expired. Reload the page and try again.';
        $messageType = 'danger';
    } else {
    $action = requestString($_POST, 'action', 'create');

    if ($action === 'create') {
        $studentId = requestString($_POST, 'student_id');
        $fullName = requestString($_POST, 'full_name');
        $email = requestString($_POST, 'email');
        $password = requestString($_POST, 'password');
        $courseId = filter_var($_POST['course_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
        $yearId = filter_var($_POST['year_level_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
        $sectionId = filter_var($_POST['section_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;

        if ($studentId === '' || $fullName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '' || $courseId <= 0 || $yearId <= 0 || $sectionId <= 0) {
            $message = 'Please fill in all fields.';
            $messageType = 'danger';
        } else {
            $sectionCheck = mysqli_prepare($conn, 'SELECT id FROM sections WHERE id = ? AND course_id = ? AND year_level_id = ? LIMIT 1');
            mysqli_stmt_bind_param($sectionCheck, 'iii', $sectionId, $courseId, $yearId);
            mysqli_stmt_execute($sectionCheck);
            if (!mysqli_fetch_assoc(mysqli_stmt_get_result($sectionCheck))) {
                $message = 'Choose a section that belongs to the selected course and year level.';
                $messageType = 'danger';
            } else {
                $duplicateCheck = mysqli_prepare($conn, 'SELECT id FROM students WHERE student_id = ? UNION SELECT id FROM users WHERE email = ? LIMIT 1');
                mysqli_stmt_bind_param($duplicateCheck, 'ss', $studentId, $email);
                mysqli_stmt_execute($duplicateCheck);
                if (mysqli_fetch_assoc(mysqli_stmt_get_result($duplicateCheck))) {
                    $message = 'Student ID or email already exists.';
                    $messageType = 'danger';
                } else {
                    try {
                        mysqli_begin_transaction($conn);
                        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                        $userRole = 'student';
                        $stmt = mysqli_prepare($conn, 'INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)');
                        mysqli_stmt_bind_param($stmt, 'ssss', $fullName, $email, $hashedPassword, $userRole);
                        mysqli_stmt_execute($stmt);
                        $userId = mysqli_insert_id($conn);
                        $insertStudent = mysqli_prepare($conn, 'INSERT INTO students (user_id, student_id, full_name, email, course_id, year_level_id, section_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
                        mysqli_stmt_bind_param($insertStudent, 'isssiii', $userId, $studentId, $fullName, $email, $courseId, $yearId, $sectionId);
                        mysqli_stmt_execute($insertStudent);
                        generateStudentEventQrTokens($conn, (int) mysqli_insert_id($conn));
                        mysqli_commit($conn);
                        $message = 'Student record added successfully.';
                    } catch (mysqli_sql_exception | Random\RandomException $exception) {
                        mysqli_rollback($conn);
                        if ($exception->getCode() === 1062) {
                            $message = 'Student ID or email already exists.';
                            $messageType = 'danger';
                        } else {
                            error_log('Admin student creation failed: ' . $exception->getMessage());
                            $message = 'Student could not be saved. Please try again.';
                            $messageType = 'danger';
                        }
                    }
                }
            }
        }
    }

    if ($action === 'delete') {
        $studentId = filter_var($_POST['student_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
        $stmt = mysqli_prepare($conn, 'DELETE FROM students WHERE id = ?');
        mysqli_stmt_bind_param($stmt, 'i', $studentId);
        mysqli_stmt_execute($stmt);
        $message = mysqli_stmt_affected_rows($stmt) === 1 ? 'Student deleted successfully.' : 'Student was not found.';
        $messageType = mysqli_stmt_affected_rows($stmt) === 1 ? 'success' : 'danger';
    }
    }
}

$courses = mysqli_query($conn, 'SELECT * FROM courses ORDER BY name');
$yearLevels = mysqli_query($conn, 'SELECT * FROM year_levels ORDER BY id');
$sections = mysqli_query($conn, 'SELECT * FROM sections ORDER BY name');
$students = mysqli_query($conn, "SELECT s.*, c.name AS course_name, y.name AS year_name, sec.name AS section_name FROM students s LEFT JOIN courses c ON c.id = s.course_id LEFT JOIN year_levels y ON y.id = s.year_level_id LEFT JOIN sections sec ON sec.id = s.section_id ORDER BY s.full_name");

include __DIR__ . '/../includes/header.php';
?>

<div class="card p-4 mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="fw-bold mb-0">Student Management</h4>
        <a href="/Attendance/admin/index.php" class="btn btn-outline-secondary btn-sm">Back to Dashboard</a>
    </div>

    <?php if ($message !== ''): ?>
        <div class="alert alert-<?= htmlspecialchars($messageType); ?>"><?= htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <form method="post" class="row g-3">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="action" value="create">
        <div class="col-md-3">
            <label class="form-label">Student ID</label>
            <input type="text" class="form-control" name="student_id" required>
        </div>
        <div class="col-md-3">
            <label class="form-label">Full Name</label>
            <input type="text" class="form-control" name="full_name" required>
        </div>
        <div class="col-md-3">
            <label class="form-label">Email</label>
            <input type="email" class="form-control" name="email" required>
        </div>
        <div class="col-md-3">
            <label class="form-label">Password</label>
            <input type="password" class="form-control" name="password" required>
        </div>
        <div class="col-md-3">
            <label class="form-label">Course</label>
            <select class="form-select" name="course_id" required>
                <option value="">Select</option>
                <?php while ($course = mysqli_fetch_assoc($courses)): ?>
                    <option value="<?= (int) $course['id']; ?>"><?= htmlspecialchars($course['name']); ?></option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Year Level</label>
            <select class="form-select" name="year_level_id" required>
                <option value="">Select</option>
                <?php while ($year = mysqli_fetch_assoc($yearLevels)): ?>
                    <option value="<?= (int) $year['id']; ?>"><?= htmlspecialchars($year['name']); ?></option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Section</label>
            <select class="form-select" name="section_id" required>
                <option value="">Select</option>
                <?php while ($section = mysqli_fetch_assoc($sections)): ?>
                    <option value="<?= (int) $section['id']; ?>" data-course="<?= (int) $section['course_id']; ?>" data-year="<?= (int) $section['year_level_id']; ?>"><?= htmlspecialchars($section['name']); ?></option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="col-md-3 d-flex align-items-end">
            <button class="btn btn-primary w-100" type="submit">Add Student</button>
        </div>
    </form>
</div>

<div class="card p-4">
    <h5 class="fw-bold mb-3">Student Records</h5>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>Student ID</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Course</th>
                    <th>Year</th>
                    <th>Section</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($student = mysqli_fetch_assoc($students)): ?>
                    <tr>
                        <td><?= htmlspecialchars($student['student_id']); ?></td>
                        <td><?= htmlspecialchars($student['full_name']); ?></td>
                        <td><?= htmlspecialchars($student['email']); ?></td>
                        <td><?= htmlspecialchars($student['course_name'] ?? 'N/A'); ?></td>
                        <td><?= htmlspecialchars($student['year_name'] ?? 'N/A'); ?></td>
                        <td><?= htmlspecialchars($student['section_name'] ?? 'N/A'); ?></td>
                        <td>
                            <form method="post" onsubmit="return confirm('Delete this student?');">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="student_id" value="<?= (int) $student['id']; ?>">
                                <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    const courseSelect = document.querySelector('select[name="course_id"]');
    const yearSelect = document.querySelector('select[name="year_level_id"]');
    const sectionSelect = document.querySelector('select[name="section_id"]');
    function filterSections() {
        const courseId = courseSelect.value;
        const yearId = yearSelect.value;
        sectionSelect.value = '';
        Array.from(sectionSelect.options).forEach((option, index) => {
            if (index === 0) {
                return;
            }
            const matches = option.dataset.course === courseId && option.dataset.year === yearId;
            option.hidden = !matches;
            option.disabled = !matches;
        });
    }
    courseSelect.addEventListener('change', filterSections);
    yearSelect.addEventListener('change', filterSections);
    filterSections();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
