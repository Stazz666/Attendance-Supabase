<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole(['admin']);

$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = requestString($_POST, 'name');
    $courseId = filter_var($_POST['course_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
    $yearId = filter_var($_POST['year_level_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;

    if (!csrfTokenIsValid()) {
        $message = 'Your form session expired. Reload the page and try again.';
        $messageType = 'danger';
    } elseif ($name === '' || $courseId <= 0 || $yearId <= 0) {
        $message = 'Please choose a course, year level, and section name.';
        $messageType = 'danger';
    } else {
        try {
            $stmt = mysqli_prepare($conn, 'INSERT INTO sections (name, course_id, year_level_id) VALUES (?, ?, ?)');
            mysqli_stmt_bind_param($stmt, 'sii', $name, $courseId, $yearId);
            mysqli_stmt_execute($stmt);
            $message = 'Section added successfully.';
        } catch (mysqli_sql_exception $exception) {
            if ($exception->getCode() === 1062) {
                $message = 'That section already exists for the selected course and year.';
            } else {
                error_log('Section creation failed: ' . $exception->getMessage());
                $message = 'Section could not be saved. Please try again.';
            }
            $messageType = 'danger';
        }
    }
}

$courses = mysqli_query($conn, 'SELECT * FROM courses ORDER BY name');
$yearLevels = mysqli_query($conn, 'SELECT * FROM year_levels ORDER BY id');
$sections = mysqli_query($conn, "SELECT s.*, c.name AS course_name, y.name AS year_name FROM sections s LEFT JOIN courses c ON c.id = s.course_id LEFT JOIN year_levels y ON y.id = s.year_level_id ORDER BY c.name, y.id, s.name");

include __DIR__ . '/../includes/header.php';
?>

<div class="card p-4 mb-4">
    <h4 class="fw-bold mb-3">Section Management</h4>
    <?php if ($message !== ''): ?>
        <div class="alert alert-<?= htmlspecialchars($messageType); ?>"><?= htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <form method="post" class="row g-3">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
        <div class="col-md-4">
            <label class="form-label">Course</label>
            <select class="form-select" name="course_id" required>
                <option value="">Select</option>
                <?php while ($course = mysqli_fetch_assoc($courses)): ?>
                    <option value="<?= (int) $course['id']; ?>"><?= htmlspecialchars($course['name']); ?></option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label">Year Level</label>
            <select class="form-select" name="year_level_id" required>
                <option value="">Select</option>
                <?php while ($year = mysqli_fetch_assoc($yearLevels)): ?>
                    <option value="<?= (int) $year['id']; ?>"><?= htmlspecialchars($year['name']); ?></option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label">Section Name</label>
            <input type="text" class="form-control" name="name" placeholder="1A" required>
        </div>
        <div class="col-12">
            <button class="btn btn-primary" type="submit">Add Section</button>
        </div>
    </form>
</div>

<div class="card p-4">
    <h5 class="fw-bold mb-3">Sections</h5>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>Course</th>
                    <th>Year</th>
                    <th>Section</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($section = mysqli_fetch_assoc($sections)): ?>
                    <tr>
                        <td><?= htmlspecialchars($section['course_name'] ?? 'N/A'); ?></td>
                        <td><?= htmlspecialchars($section['year_name'] ?? 'N/A'); ?></td>
                        <td><?= htmlspecialchars($section['name']); ?></td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
