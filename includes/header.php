<?php
require_once __DIR__ . '/session.php';

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

$user = currentUser();
$basePath = '/Attendance';
$currentPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
$isActivePage = static function (array $pages) use ($currentPage): string {
    return in_array($currentPage, $pages, true) ? ' active' : '';
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body {
            background: #f3f6fb;
            color: #1d2a39;
        }

        .sidebar {
            min-height: 100vh;
            background: linear-gradient(180deg, #16213e 0%, #1f3b73 100%);
            color: white;
        }

        .sidebar .nav-link {
            color: rgba(255,255,255,0.9);
            border-radius: 10px;
            margin-bottom: 6px;
        }

        .sidebar .nav-link:hover,
        .sidebar .nav-link.active {
            background: rgba(255,255,255,0.12);
            color: white;
        }

        .topbar {
            background: white;
            border-bottom: 1px solid #e6ebf1;
        }

        .card {
            border: none;
            border-radius: 16px;
            box-shadow: 0 10px 20px rgba(22,33,62,0.05);
        }

        .stat-card {
            background: linear-gradient(135deg, #eef4ff 0%, #ffffff 100%);
            border-left: 5px solid #2b6eea;
        }

        .btn-primary {
            background: #2b6eea;
            border-color: #2b6eea;
        }

        .btn-primary:hover {
            background: #1f5ac8;
            border-color: #1f5ac8;
        }

        .btn-outline-primary {
            color: #2b6eea;
            border-color: #2b6eea;
        }

        .table thead th {
            background: #f4f7fb;
        }

        .login-wrap {
            min-height: 100vh;
            background: linear-gradient(135deg, #11284a 0%, #2b6eea 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 30px;
        }

        .login-card {
            width: min(100%, 500px);
            border-radius: 22px;
            overflow: hidden;
        }

        .login-header {
            background: linear-gradient(135deg, #0f1e39 0%, #21488b 100%);
            color: white;
            padding: 30px 25px;
            text-align: center;
        }

        .login-body {
            background: white;
            padding: 30px 25px;
        }

        @media (max-width: 767.98px) {
            .sidebar {
                min-height: auto;
            }

            .topbar {
                flex-wrap: wrap;
                gap: 12px;
            }

            main > .p-4 {
                padding: 1rem !important;
            }
        }
    </style>
</head>
<body>
<?php if ($user): ?>
<div class="container-fluid">
    <div class="row">
        <aside class="col-md-2 sidebar p-3">
            <div class="mb-4 text-center">
                <h4 class="fw-bold mb-0">Attendance</h4>
                <small class="text-white-50">University System</small>
            </div>

            <?php if ($user['role'] === 'admin'): ?>
                <nav class="nav flex-column">
                    <a class="nav-link<?= $isActivePage(['index.php']); ?>" href="/Attendance/admin/index.php"><i class="bi bi-speedometer2 me-2"></i>Dashboard</a>
                    <a class="nav-link<?= $isActivePage(['students.php']); ?>" href="/Attendance/admin/students.php"><i class="bi bi-people me-2"></i>Students</a>
                    <a class="nav-link<?= $isActivePage(['faculty.php']); ?>" href="/Attendance/admin/faculty.php"><i class="bi bi-person-badge me-2"></i>Faculty</a>
                    <a class="nav-link<?= $isActivePage(['courses.php']); ?>" href="/Attendance/admin/courses.php"><i class="bi bi-book me-2"></i>Courses</a>
                    <a class="nav-link<?= $isActivePage(['sections.php']); ?>" href="/Attendance/admin/sections.php"><i class="bi bi-grid me-2"></i>Sections</a>
                    <a class="nav-link<?= $isActivePage(['year-levels.php']); ?>" href="/Attendance/admin/year-levels.php"><i class="bi bi-bar-chart me-2"></i>Year Levels</a>
                    <a class="nav-link<?= $isActivePage(['events.php', 'event-edit.php']); ?>" href="/Attendance/admin/events.php"><i class="bi bi-calendar-event me-2"></i>Events</a>
                    <a class="nav-link<?= $isActivePage(['scanner.php']); ?>" href="/Attendance/admin/scanner.php"><i class="bi bi-qr-code-scan me-2"></i>QR Scanner</a>
                    <a class="nav-link<?= $isActivePage(['event-attendance.php']); ?>" href="/Attendance/admin/event-attendance.php"><i class="bi bi-calendar-check me-2"></i>Event Attendance</a>
                    <a class="nav-link<?= $isActivePage(['reports.php']); ?>" href="/Attendance/admin/reports.php"><i class="bi bi-file-earmark-text me-2"></i>Reports</a>
                </nav>
            <?php elseif ($user['role'] === 'faculty'): ?>
                <nav class="nav flex-column">
                    <a class="nav-link active" href="/Attendance/faculty/index.php"><i class="bi bi-speedometer2 me-2"></i>Dashboard</a>
                    <a class="nav-link" href="/Attendance/faculty/sessions.php"><i class="bi bi-qr-code me-2"></i>Attendance Sessions</a>
                    <a class="nav-link" href="/Attendance/faculty/reports.php"><i class="bi bi-file-earmark-text me-2"></i>Reports</a>
                </nav>
            <?php else: ?>
                <nav class="nav flex-column">
                    <a class="nav-link<?= $isActivePage(['index.php']); ?>" href="/Attendance/student/index.php"><i class="bi bi-speedometer2 me-2"></i>Dashboard</a>
                    <a class="nav-link<?= $isActivePage(['profile.php']); ?>" href="/Attendance/student/profile.php"><i class="bi bi-person-circle me-2"></i>My Profile</a>
                    <a class="nav-link<?= $isActivePage(['attendance.php']); ?>" href="/Attendance/student/attendance.php"><i class="bi bi-calendar-check me-2"></i>My Attendance</a>
                </nav>
            <?php endif; ?>

            <div class="mt-auto pt-4 border-top border-light border-opacity-25">
                <a class="nav-link" href="/Attendance/logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a>
            </div>
        </aside>

        <main class="col-md-10 p-0">
            <div class="topbar px-4 py-3 d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="mb-0 fw-bold">Welcome, <?= htmlspecialchars($user['name']); ?></h5>
                    <small class="text-muted text-uppercase"><?= htmlspecialchars($user['role']); ?></small>
                </div>
                <div class="d-flex align-items-center gap-3">
                    <span class="badge bg-primary-subtle text-primary rounded-pill px-3 py-2"><?= htmlspecialchars($user['role']); ?></span>
                    <a href="/Attendance/logout.php" class="btn btn-outline-secondary btn-sm">Logout</a>
                </div>
            </div>
            <div class="p-4">
<?php else: ?>
<?php endif; ?>
