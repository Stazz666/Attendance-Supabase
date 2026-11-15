<?php
function redirect($path) {
    header('Location: ' . $path);
    exit;
}

function currentUser() {
    return $_SESSION['user'] ?? null;
}

function requireRole(array $roles) {
    $user = currentUser();

    if (!$user) {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/Attendance/index.php';
        $_SESSION['redirect_after_login'] = str_starts_with($requestUri, '/Attendance/') ? $requestUri : '/Attendance/index.php';
        redirect('/Attendance/index.php');
    }

    if (!in_array($user['role'], $roles, true)) {
        redirect(getDashboardUrl($user['role']));
    }

    return $user;
}

function formatDate($date) {
    if (!$date || $date === '0000-00-00') {
        return 'N/A';
    }

    return date('M d, Y', strtotime($date));
}

function formatDateTime($value) {
    if (!$value || $value === '0000-00-00 00:00:00') {
        return 'N/A';
    }

    return date('M d, Y h:i A', strtotime($value));
}

function roleLabel($role) {
    return ucfirst($role);
}

function getDashboardUrl($role) {
    if ($role === 'admin') {
        return '/Attendance/admin/index.php';
    }

    if ($role === 'faculty') {
        return '/Attendance/faculty/index.php';
    }

    return '/Attendance/student/index.php';
}

function getSessionStatusLabel($status) {
    $status = strtoupper($status);

    if ($status === 'ACTIVE') {
        return 'Active';
    }

    if ($status === 'EXPIRED') {
        return 'Expired';
    }

    if ($status === 'UPCOMING') {
        return 'Upcoming';
    }

    return ucfirst(strtolower($status));
}

function getStudentByUserId($conn, $userId) {
    $stmt = mysqli_prepare($conn, 'SELECT * FROM students WHERE user_id = ? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    return mysqli_fetch_assoc($result);
}

function getCourseName($conn, $courseId) {
    $stmt = mysqli_prepare($conn, 'SELECT name FROM courses WHERE id = ? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'i', $courseId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
    return $row['name'] ?? 'Unknown';
}

function getYearName($conn, $yearId) {
    $stmt = mysqli_prepare($conn, 'SELECT name FROM year_levels WHERE id = ? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'i', $yearId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
    return $row['name'] ?? 'Unknown';
}

function getSectionName($conn, $sectionId) {
    $stmt = mysqli_prepare($conn, 'SELECT name FROM sections WHERE id = ? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'i', $sectionId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
    return $row['name'] ?? 'Unknown';
}

function sessionIsActive($session) {
    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
    $start = new DateTimeImmutable($session['session_date'] . ' ' . $session['start_time'], new DateTimeZone('Asia/Manila'));
    $end = new DateTimeImmutable($session['session_date'] . ' ' . $session['end_time'], new DateTimeZone('Asia/Manila'));
    return ($session['status'] ?? 'active') === 'active' && $now >= $start && $now <= $end;
}

function eventAttendanceScheduleMessage(array $event, ?DateTimeImmutable $now = null): ?string {
    $timezone = new DateTimeZone('Asia/Manila');
    $now = ($now ?? new DateTimeImmutable('now', $timezone))->setTimezone($timezone);

    if (($event['status'] ?? '') !== 'active') {
        return 'This event is inactive and cannot accept attendance.';
    }

    $start = new DateTimeImmutable($event['event_date'] . ' ' . $event['start_time'], $timezone);
    $end = new DateTimeImmutable($event['event_date'] . ' ' . $event['end_time'], $timezone);
    if ($now < $start) {
        return 'Attendance has not opened yet. It opens on ' . $start->format('Y-m-d') . ' at ' . $start->format('H:i') . ' Asia/Manila.';
    }
    if ($now > $end) {
        return 'Attendance closed on ' . $end->format('Y-m-d') . ' at ' . $end->format('H:i') . ' Asia/Manila.';
    }

    return null;
}

function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrfTokenIsValid(): bool {
    $submittedToken = $_POST['csrf_token'] ?? '';
    return is_string($submittedToken)
        && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $submittedToken);
}

function bindStatementParams(mysqli_stmt $stmt, string $types, array &$params): void {
    if ($types === '') {
        return;
    }

    $arguments = [$types];
    foreach ($params as &$parameter) {
        $arguments[] = &$parameter;
    }
    mysqli_stmt_bind_param($stmt, ...$arguments);
}

function generateEventQrTokens(mysqli $conn, int $eventId, ?int $studentId = null): int {
    if ($studentId === null) {
        $studentResult = mysqli_query($conn, 'SELECT id FROM students ORDER BY id');
    } else {
        $studentStmt = mysqli_prepare($conn, 'SELECT id FROM students WHERE id = ?');
        mysqli_stmt_bind_param($studentStmt, 'i', $studentId);
        mysqli_stmt_execute($studentStmt);
        $studentResult = mysqli_stmt_get_result($studentStmt);
    }

    $insert = mysqli_prepare($conn, 'INSERT INTO event_student_qrs (event_id, student_id, qr_token) VALUES (?, ?, ?)');
    $generated = 0;
    while ($student = mysqli_fetch_assoc($studentResult)) {
        $currentStudentId = (int) $student['id'];
        $token = bin2hex(random_bytes(32));
        mysqli_stmt_bind_param($insert, 'iis', $eventId, $currentStudentId, $token);
        try {
            mysqli_stmt_execute($insert);
            $generated += mysqli_stmt_affected_rows($insert);
        } catch (mysqli_sql_exception $exception) {
            if ($exception->getCode() !== 1062) {
                throw $exception;
            }
        }
    }

    return $generated;
}

function generateStudentEventQrTokens(mysqli $conn, int $studentId): int {
    $eventResult = mysqli_query($conn, 'SELECT id FROM events ORDER BY id');
    $generated = 0;
    while ($event = mysqli_fetch_assoc($eventResult)) {
        $generated += generateEventQrTokens($conn, (int) $event['id'], $studentId);
    }

    return $generated;
}

function requestString(array $source, string $key, string $default = ''): string {
    return isset($source[$key]) && is_string($source[$key]) ? trim($source[$key]) : $default;
}

function globalAppUrl(string $path): string {
    return rtrim(APP_BASE_URL, '/') . '/' . ltrim($path, '/');
}

function studentMayUseSession(array $session, array $student): bool {
    return ($session['course_id'] === null || (int) $session['course_id'] === (int) $student['course_id'])
        && ($session['year_level_id'] === null || (int) $session['year_level_id'] === (int) $student['year_level_id'])
        && ($session['section_id'] === null || (int) $session['section_id'] === (int) $student['section_id']);
}

function attendanceStatus(array $record, ?string $sessionEnd = null): string {
    if (!empty($record['time_in']) && empty($record['time_out'])) {
        return 'INCOMPLETE';
    }

    if (!empty($record['status'])) {
        return strtoupper($record['status']);
    }
    if (($record['session_status'] ?? null) === 'inactive') {
        return 'PENDING';
    }

    if ($sessionEnd !== null) {
        $endValue = strlen($sessionEnd) > 8 ? $sessionEnd : date('Y-m-d') . ' ' . $sessionEnd;
        $end = new DateTimeImmutable($endValue, new DateTimeZone('Asia/Manila'));
        if ($end < new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'))) {
            return 'ABSENT';
        }
    }

    return 'PENDING';
}

function approvalMessage($message, $type = 'success') {
    return '<div class="alert alert-' . $type . ' alert-dismissible fade show" role="alert">'
        . htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
        . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>'
        . '</div>';
}
