<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole(['admin']);

$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = requestString($_POST, 'full_name');
    $email = requestString($_POST, 'email');
    $password = requestString($_POST, 'password');
    $facultyCode = requestString($_POST, 'faculty_code');

    if (!csrfTokenIsValid()) {
        $message = 'Your form session expired. Reload the page and try again.';
        $messageType = 'danger';
    } elseif ($fullName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '' || $facultyCode === '') {
        $message = 'All fields are required.';
        $messageType = 'danger';
    } else {
        $check = mysqli_prepare($conn, 'SELECT id FROM users WHERE email = ? UNION SELECT id FROM faculty WHERE faculty_code = ? LIMIT 1');
        mysqli_stmt_bind_param($check, 'ss', $email, $facultyCode);
        mysqli_stmt_execute($check);
        if (mysqli_fetch_assoc(mysqli_stmt_get_result($check))) {
            $message = 'A user with this email or faculty code already exists.';
            $messageType = 'danger';
        } else {
            try {
                mysqli_begin_transaction($conn);
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $userRole = 'faculty';
                $stmt = mysqli_prepare($conn, 'INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)');
                mysqli_stmt_bind_param($stmt, 'ssss', $fullName, $email, $hashedPassword, $userRole);
                mysqli_stmt_execute($stmt);
                $userId = mysqli_insert_id($conn);
                $facultyStmt = mysqli_prepare($conn, 'INSERT INTO faculty (user_id, faculty_code, full_name, email) VALUES (?, ?, ?, ?)');
                mysqli_stmt_bind_param($facultyStmt, 'isss', $userId, $facultyCode, $fullName, $email);
                mysqli_stmt_execute($facultyStmt);
                mysqli_commit($conn);
                $message = 'Faculty profile created successfully.';
            } catch (mysqli_sql_exception $exception) {
                mysqli_rollback($conn);
                if ($exception->getCode() === 1062) {
                    $message = 'A user with this email or faculty code already exists.';
                } else {
                    error_log('Faculty creation failed: ' . $exception->getMessage());
                    $message = 'Faculty profile could not be saved. Please try again.';
                }
                $messageType = 'danger';
            }
        }
    }
}

$facultyRows = mysqli_query($conn, 'SELECT f.*, u.email, u.name FROM faculty f LEFT JOIN users u ON u.id = f.user_id ORDER BY f.full_name');

include __DIR__ . '/../includes/header.php';
?>

<div class="card p-4 mb-4">
    <h4 class="fw-bold mb-3">Faculty Management</h4>
    <?php if ($message !== ''): ?>
        <div class="alert alert-<?= htmlspecialchars($messageType); ?>"><?= htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <form method="post" class="row g-3">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
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
            <label class="form-label">Faculty Code</label>
            <input type="text" class="form-control" name="faculty_code" required>
        </div>
        <div class="col-12">
            <button class="btn btn-primary" type="submit">Add Faculty</button>
        </div>
    </form>
</div>

<div class="card p-4">
    <h5 class="fw-bold mb-3">Faculty List</h5>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>Faculty Code</th>
                    <th>Name</th>
                    <th>Email</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = mysqli_fetch_assoc($facultyRows)): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['faculty_code']); ?></td>
                        <td><?= htmlspecialchars($row['full_name']); ?></td>
                        <td><?= htmlspecialchars($row['email']); ?></td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
