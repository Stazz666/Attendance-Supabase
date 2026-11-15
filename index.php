<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$token = requestString($_GET, 'token');

if (isset($_SESSION['user'])) {
    $redirectTarget = $_SESSION['redirect_after_login'] ?? getDashboardUrl($_SESSION['user']['role']);
    if ($token !== '' && $_SESSION['user']['role'] === 'student') {
        $redirectTarget = '/Attendance/attendance/index.php?token=' . urlencode($token);
    }
    unset($_SESSION['redirect_after_login']);
    redirect($redirectTarget);
}

$message = '';
$messageType = 'danger';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfTokenIsValid()) {
        $message = 'Your form session expired. Reload the page and try again.';
    } else {
        $identifier = requestString($_POST, 'identifier');
        $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
        $token = requestString($_POST, 'token', $token);

        if ($identifier !== '' && $password !== '') {
            $stmt = mysqli_prepare($conn, 'SELECT u.*, s.student_id FROM users u LEFT JOIN students s ON s.user_id = u.id WHERE u.email = ? OR s.student_id = ? LIMIT 1');
            mysqli_stmt_bind_param($stmt, 'ss', $identifier, $identifier);
            mysqli_stmt_execute($stmt);
            $user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

            if ($user && password_verify($password, $user['password'])) {
                session_regenerate_id(true);
                $_SESSION['user'] = [
                    'id' => (int) $user['id'],
                    'name' => $user['name'],
                    'email' => $user['email'],
                    'role' => $user['role'],
                    'student_id' => $user['student_id'] ?? null,
                ];

                $next = $_SESSION['redirect_after_login'] ?? getDashboardUrl($user['role']);
                if (!is_string($next) || !str_starts_with($next, '/Attendance/') || str_starts_with($next, '//')) {
                    $next = getDashboardUrl($user['role']);
                }
                if ($token !== '' && $user['role'] === 'student') {
                    $next = '/Attendance/attendance/index.php?token=' . urlencode($token);
                }
                unset($_SESSION['redirect_after_login']);
                unset($_SESSION['csrf_token']);
                redirect($next);
            }

            $message = 'Invalid Student ID/Email or password.';
        } else {
            $message = 'Please enter your Student ID or email and password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | Student Attendance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: linear-gradient(135deg, #102a48 0%, #2f6ce5 100%);
        }
        .login-wrap {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }
        .login-card {
            width: min(100%, 500px);
            overflow: hidden;
            border-radius: 22px;
        }
        .login-header {
            padding: 30px 25px;
            text-align: center;
            color: white;
            background: linear-gradient(135deg, #0f1e39 0%, #21488b 100%);
        }
        .login-body {
            padding: 30px 25px;
            background: white;
        }
    </style>
</head>
<body>
<div class="login-wrap">
    <div class="login-card shadow-lg">
        <div class="login-header">
            <h2 class="fw-bold mb-1">Attendance System</h2>
            <p class="mb-0">CCTE Attendance System</p>
        </div>
        <div class="login-body">
            <?php if ($message !== ''): ?>
                <div class="alert alert-<?= htmlspecialchars($messageType); ?>"><?= htmlspecialchars($message); ?></div>
            <?php endif; ?>

            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token); ?>">
                <div class="mb-3">
                    <label for="identifier" class="form-label">Student ID / Email</label>
                    <input type="text" class="form-control" id="identifier" name="identifier" placeholder="" required>
                </div>
                <div class="mb-4">
                    <label for="password" class="form-label">Password</label>
                    <input type="password" class="form-control" id="password" name="password" placeholder="" required>
                </div>
                <button type="submit" class="btn btn-primary w-100 mb-3">Login</button>
                <div class="text-center mb-2">
                    <a href="/Attendance/student/register.php?token=<?= urlencode($token); ?>" class="text-decoration-none">Create a student account</a>
                </div>
            </form>
            <footer class="mt-3 pt-3 border-top text-center text-muted small">
                <div><strong>Web Developer:</strong> Ryan Vidal</div>
                <div><strong>QA Tester:</strong> John Benedict Javier</div>
                <div class="mt-2">© 2026 CCTE Attendance System</div>
            </footer>
        </div>
    </div>
</div>
</body>
</html>
