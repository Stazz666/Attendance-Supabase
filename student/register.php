<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$token = requestString($_GET, 'token');

if (isset($_SESSION['user'])) {
    $redirectTarget = getDashboardUrl($_SESSION['user']['role']);
    if ($token !== '' && $_SESSION['user']['role'] === 'student') {
        $redirectTarget = '/Attendance/attendance/index.php?token=' . urlencode($token);
    }
    redirect($redirectTarget);
}

$message = '';
$messageType = 'success';

$courses = mysqli_query($conn, 'SELECT * FROM courses ORDER BY name');
$yearLevels = mysqli_query($conn, 'SELECT * FROM year_levels ORDER BY id');
$sections = mysqli_query($conn, 'SELECT * FROM sections ORDER BY course_id, year_level_id, name');
$sectionOptions = [];
while ($section = mysqli_fetch_assoc($sections)) {
    $key = (int) $section['course_id'] . ':' . (int) $section['year_level_id'];
    $sectionOptions[$key][] = [
        'id' => (int) $section['id'],
        'name' => $section['name'],
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = requestString($_POST, 'token', $token);
    $studentId = requestString($_POST, 'student_id');
    $fullName = requestString($_POST, 'full_name');
    $courseId = filter_var($_POST['course_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
    $yearId = filter_var($_POST['year_level_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
    $sectionId = filter_var($_POST['section_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
    $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
    $confirmPassword = isset($_POST['confirm_password']) && is_string($_POST['confirm_password']) ? $_POST['confirm_password'] : '';

    if (!csrfTokenIsValid()) {
        $message = 'Your form session expired. Reload the page and try again.';
        $messageType = 'danger';
    } elseif ($studentId === '' || $fullName === '' || $courseId <= 0 || $yearId <= 0 || $sectionId <= 0 || $password === '' || $confirmPassword === '') {
        $message = 'Please complete all required fields.';
        $messageType = 'danger';
    } elseif (!preg_match('/^[A-Za-z0-9._-]{1,50}$/', $studentId)) {
        $message = 'Student ID may contain only letters, numbers, periods, underscores, and hyphens.';
        $messageType = 'danger';
    } elseif ($password !== $confirmPassword) {
        $message = 'Passwords do not match.';
        $messageType = 'danger';
    } elseif (strlen($password) < 8) {
        $message = 'Password must be at least 8 characters long.';
        $messageType = 'danger';
    } else {
        $sectionStmt = mysqli_prepare($conn, 'SELECT id FROM sections WHERE id = ? AND course_id = ? AND year_level_id = ? LIMIT 1');
        mysqli_stmt_bind_param($sectionStmt, 'iii', $sectionId, $courseId, $yearId);
        mysqli_stmt_execute($sectionStmt);
        $validSection = mysqli_fetch_assoc(mysqli_stmt_get_result($sectionStmt));

        $existingStmt = mysqli_prepare($conn, 'SELECT id FROM students WHERE student_id = ? LIMIT 1');
        mysqli_stmt_bind_param($existingStmt, 's', $studentId);
        mysqli_stmt_execute($existingStmt);
        $existingStudent = mysqli_fetch_assoc(mysqli_stmt_get_result($existingStmt));
        if ($existingStudent) {
            $message = 'Student ID already exists. Please use a different one.';
            $messageType = 'danger';
        } elseif (!$validSection) {
            $message = 'Choose a section that belongs to the selected course and year level.';
            $messageType = 'danger';
        } else {
            $email = strtolower($studentId) . '@students.attendance.local';
            $hashed = password_hash($password, PASSWORD_DEFAULT);

            try {
                mysqli_begin_transaction($conn);
                $userRole = 'student';
                $userStmt = mysqli_prepare($conn, 'INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)');
                mysqli_stmt_bind_param($userStmt, 'ssss', $fullName, $email, $hashed, $userRole);
                mysqli_stmt_execute($userStmt);
                $userId = mysqli_insert_id($conn);

                $studentStmt = mysqli_prepare($conn, 'INSERT INTO students (user_id, student_id, full_name, email, course_id, year_level_id, section_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
                mysqli_stmt_bind_param($studentStmt, 'isssiii', $userId, $studentId, $fullName, $email, $courseId, $yearId, $sectionId);
                mysqli_stmt_execute($studentStmt);
                generateStudentEventQrTokens($conn, (int) mysqli_insert_id($conn));
                mysqli_commit($conn);

                if ($token !== '') {
                    redirect('/Attendance/index.php?token=' . urlencode($token));
                }

                $message = 'Registration successful. You can now log in using your Student ID and password.';
                $messageType = 'success';
            } catch (mysqli_sql_exception | Random\RandomException $exception) {
                mysqli_rollback($conn);
                if ($exception->getCode() === 1062) {
                    $message = 'Student ID already exists. Please use a different one.';
                    $messageType = 'danger';
                } else {
                    error_log('Student registration failed: ' . $exception->getMessage());
                    $message = 'Registration could not be completed. Please try again.';
                    $messageType = 'danger';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Registration</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-primary text-white">
                    <h4 class="mb-0">Student Registration</h4>
                </div>
                <div class="card-body p-4">
                    <?php if ($message !== ''): ?>
                        <div class="alert alert-<?= htmlspecialchars($messageType); ?>"><?= htmlspecialchars($message); ?></div>
                    <?php endif; ?>

                    <form method="post" class="row g-3">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="token" value="<?= htmlspecialchars($token); ?>">
                        <div class="col-md-6">
                            <label class="form-label">Student ID Number</label>
                            <input type="text" class="form-control" name="student_id" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Full Name</label>
                            <input type="text" class="form-control" name="full_name" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Course</label>
                            <select class="form-select" name="course_id" id="course_id" required>
                                <option value="">Select</option>
                                <?php
                                mysqli_data_seek($courses, 0);
                                while ($course = mysqli_fetch_assoc($courses)): ?>
                                    <option value="<?= (int) $course['id']; ?>"><?= htmlspecialchars($course['name']); ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Year Level</label>
                            <select class="form-select" name="year_level_id" id="year_level_id" required>
                                <option value="">Select</option>
                                <?php
                                mysqli_data_seek($yearLevels, 0);
                                while ($year = mysqli_fetch_assoc($yearLevels)): ?>
                                    <option value="<?= (int) $year['id']; ?>"><?= htmlspecialchars($year['name']); ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Section</label>
                            <select class="form-select" name="section_id" id="section_id" required disabled>
                                <option value="">Select</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Password</label>
                            <input type="password" class="form-control" name="password" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Confirm Password</label>
                            <input type="password" class="form-control" name="confirm_password" required>
                        </div>
                        <div class="col-12 d-flex justify-content-between align-items-center">
                            <a href="/Attendance/index.php?token=<?= urlencode($token); ?>" class="btn btn-outline-secondary">Back to Login</a>
                            <button class="btn btn-primary" type="submit">Register</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
    const sectionOptions = <?= json_encode($sectionOptions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP); ?>;
    const courseSelect = document.getElementById('course_id');
    const yearSelect = document.getElementById('year_level_id');
    const sectionSelect = document.getElementById('section_id');

    function updateSections() {
        const courseId = courseSelect.value;
        const yearId = yearSelect.value;
        const key = `${courseId}:${yearId}`;
        const options = sectionOptions[key] || [];

        sectionSelect.replaceChildren(new Option('Select', ''));
        options.forEach((section) => {
            const option = document.createElement('option');
            option.value = section.id;
            option.textContent = section.name;
            sectionSelect.appendChild(option);
        });

        sectionSelect.disabled = options.length === 0;
        if (options.length === 0) {
            sectionSelect.value = '';
        }
    }

    courseSelect.addEventListener('change', updateSections);
    yearSelect.addEventListener('change', updateSections);
    document.addEventListener('DOMContentLoaded', updateSections);
</script>
</body>
</html>
