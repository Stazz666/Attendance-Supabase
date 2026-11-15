<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole(['admin']);

$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = requestString($_POST, 'name');
    $code = requestString($_POST, 'code');

    if (!csrfTokenIsValid()) {
        $message = 'Your form session expired. Reload the page and try again.';
        $messageType = 'danger';
    } elseif ($name === '' || $code === '') {
        $message = 'Course name and code are required.';
        $messageType = 'danger';
    } else {
        try {
            $stmt = mysqli_prepare($conn, 'INSERT INTO courses (name, code) VALUES (?, ?)');
            mysqli_stmt_bind_param($stmt, 'ss', $name, $code);
            mysqli_stmt_execute($stmt);
            $message = 'Course created successfully.';
        } catch (mysqli_sql_exception $exception) {
            if ($exception->getCode() === 1062) {
                $message = 'That course code already exists.';
            } else {
                error_log('Course creation failed: ' . $exception->getMessage());
                $message = 'Course could not be saved. Please try again.';
            }
            $messageType = 'danger';
        }
    }
}

$courses = mysqli_query($conn, 'SELECT * FROM courses ORDER BY name');

include __DIR__ . '/../includes/header.php';
?>

<div class="card p-4 mb-4">
    <h4 class="fw-bold mb-3">Course Management</h4>
    <?php if ($message !== ''): ?>
        <div class="alert alert-<?= htmlspecialchars($messageType); ?>"><?= htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <form method="post" class="row g-3">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
        <div class="col-md-5">
            <label class="form-label">Course Name</label>
            <input type="text" class="form-control" name="name" placeholder="BS Computer Science" required>
        </div>
        <div class="col-md-5">
            <label class="form-label">Course Code</label>
            <input type="text" class="form-control" name="code" placeholder="BSCS" required>
        </div>
        <div class="col-md-2 d-flex align-items-end">
            <button class="btn btn-primary w-100" type="submit">Add</button>
        </div>
    </form>
</div>

<div class="card p-4">
    <h5 class="fw-bold mb-3">Courses</h5>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Code</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($course = mysqli_fetch_assoc($courses)): ?>
                    <tr>
                        <td><?= htmlspecialchars($course['name']); ?></td>
                        <td><?= htmlspecialchars($course['code']); ?></td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
