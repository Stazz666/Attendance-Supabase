<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$token = $_POST['token'] ?? '';
$returnToSession = static function (string $message) use ($token): void {
    $_SESSION['attendance_message'] = $message;
    redirect('/Attendance/attendance/index.php?token=' . urlencode(is_string($token) ? $token : ''));
};

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'student') {
    $_SESSION['redirect_after_login'] = '/Attendance/attendance/index.php?token=' . urlencode(is_string($token) ? $token : '');
    redirect('/Attendance/index.php?token=' . urlencode(is_string($token) ? $token : ''));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrfTokenIsValid()) {
    $returnToSession('This attendance request expired. Reload the attendance page and try again.');
}

if (!is_string($token) || !preg_match('/^[a-f0-9]{20,128}$/i', $token)) {
    $_SESSION['attendance_message'] = 'Invalid attendance token.';
    redirect('/Attendance/student/index.php');
}

$action = $_POST['action'] ?? '';
if (!in_array($action, ['time_in', 'time_out'], true)) {
    $returnToSession('Invalid attendance action.');
}

$sessionStmt = mysqli_prepare($conn, 'SELECT * FROM attendance_sessions WHERE qr_token = ? LIMIT 1');
mysqli_stmt_bind_param($sessionStmt, 's', $token);
mysqli_stmt_execute($sessionStmt);
$session = mysqli_fetch_assoc(mysqli_stmt_get_result($sessionStmt));
if (!$session) {
    $_SESSION['attendance_message'] = 'Attendance session not found.';
    redirect('/Attendance/student/index.php');
}

$student = getStudentByUserId($conn, (int) $_SESSION['user']['id']);
if (!$student) {
    redirect('/Attendance/logout.php');
}
if (!studentMayUseSession($session, $student)) {
    $_SESSION['attendance_message'] = 'This QR session is not assigned to your student group.';
    redirect('/Attendance/student/index.php');
}

if (!sessionIsActive($session)) {
    $returnToSession('This attendance session is not active or is outside its allowed time.');
}

$sessionId = (int) $session['id'];
$studentId = (int) $student['id'];
$recordStmt = mysqli_prepare($conn, 'SELECT * FROM attendance_records WHERE session_id = ? AND student_id = ? LIMIT 1');
mysqli_stmt_bind_param($recordStmt, 'ii', $sessionId, $studentId);
mysqli_stmt_execute($recordStmt);
$record = mysqli_fetch_assoc(mysqli_stmt_get_result($recordStmt));
if (!$record) {
    $dailyStmt = mysqli_prepare($conn, 'SELECT id FROM attendance_records WHERE student_id = ? AND attendance_date = ? LIMIT 1');
    $attendanceDate = $session['session_date'];
    mysqli_stmt_bind_param($dailyStmt, 'is', $studentId, $attendanceDate);
    mysqli_stmt_execute($dailyStmt);
    if (mysqli_fetch_assoc(mysqli_stmt_get_result($dailyStmt))) {
        $returnToSession('Attendance has already been recorded for this date.');
    }
}

try {
    if ($action === 'time_in') {
        if ($record && !empty($record['time_in'])) {
            $returnToSession('You already recorded TIME IN for this session.');
        }

        $now = date('H:i:s');
        $status = $now <= $session['late_threshold'] ? 'PRESENT' : 'LATE';
        if ($record) {
            $update = mysqli_prepare($conn, 'UPDATE attendance_records SET time_in = ?, status = ? WHERE id = ? AND time_in IS NULL AND time_out IS NULL');
            $recordId = (int) $record['id'];
            mysqli_stmt_bind_param($update, 'ssi', $now, $status, $recordId);
            mysqli_stmt_execute($update);
            if (mysqli_stmt_affected_rows($update) !== 1) {
                $returnToSession('TIME IN was already submitted for this session.');
            }
        } else {
            $studentName = $student['full_name'];
            $courseId = (int) $student['course_id'];
            $yearId = (int) $student['year_level_id'];
            $sectionId = (int) $student['section_id'];
            $attendanceDate = $session['session_date'];
            $insert = mysqli_prepare($conn, 'INSERT INTO attendance_records (session_id, student_id, student_name, course_id, year_level_id, section_id, attendance_date, time_in, time_out, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, ?)');
            mysqli_stmt_bind_param($insert, 'iisiiisss', $sessionId, $studentId, $studentName, $courseId, $yearId, $sectionId, $attendanceDate, $now, $status);
            mysqli_stmt_execute($insert);
        }

        $returnToSession('TIME IN recorded successfully.');
    }

    if (!$record || empty($record['time_in'])) {
        $returnToSession('TIME OUT cannot be submitted without TIME IN.');
    }
    if (!empty($record['time_out'])) {
        $returnToSession('You already recorded TIME OUT for this session.');
    }

    $timeOut = date('H:i:s');
    $recordId = (int) $record['id'];
    $update = mysqli_prepare($conn, 'UPDATE attendance_records SET time_out = ? WHERE id = ? AND time_in IS NOT NULL AND time_out IS NULL');
    mysqli_stmt_bind_param($update, 'si', $timeOut, $recordId);
    mysqli_stmt_execute($update);
    if (mysqli_stmt_affected_rows($update) !== 1) {
        $returnToSession('TIME OUT was already submitted for this session.');
    }

    $returnToSession('TIME OUT recorded successfully.');
} catch (mysqli_sql_exception $exception) {
    if ($exception->getCode() === 1062) {
        $returnToSession('Attendance has already been recorded for this date.');
    }

    error_log('Attendance submission failed: ' . $exception->getMessage());
    $returnToSession('Attendance could not be saved. Please try again or contact the administrator.');
}
