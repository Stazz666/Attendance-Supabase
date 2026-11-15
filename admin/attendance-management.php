<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole(['admin']);

$message = $_SESSION['attendance_admin_message'] ?? '';
unset($_SESSION['attendance_admin_message']);
$messageType = 'success';
$validStatuses = ['PRESENT', 'LATE', 'ABSENT', 'INCOMPLETE'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfTokenIsValid()) {
        $message = 'Your form session expired. Reload the page and try again.';
        $messageType = 'danger';
    } else {
        $sessionId = filter_var($_POST['session_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
        $studentId = filter_var($_POST['student_pk'] ?? null, FILTER_VALIDATE_INT) ?: 0;
        $status = strtoupper(requestString($_POST, 'status'));
        $rawTimeIn = requestString($_POST, 'time_in');
        $rawTimeOut = requestString($_POST, 'time_out');
        $timeIn = $rawTimeIn === '' ? null : $rawTimeIn;
        $timeOut = $rawTimeOut === '' ? null : $rawTimeOut;
        $validTime = static function (?string $value): bool {
            if ($value === null) {
                return true;
            }
            $parsed = DateTimeImmutable::createFromFormat('!H:i', $value);
            return $parsed !== false && $parsed->format('H:i') === $value;
        };

        if ($sessionId <= 0 || $studentId <= 0 || !in_array($status, $validStatuses, true) || !$validTime($timeIn) || !$validTime($timeOut)) {
            $message = 'Invalid attendance update. Check the selected status and time values.';
            $messageType = 'danger';
        } else {
            $sourceStmt = mysqli_prepare($conn, 'SELECT session.*, student.student_id AS student_number, student.full_name, student.course_id AS student_course_id, student.year_level_id AS student_year_id, student.section_id AS student_section_id FROM attendance_sessions session JOIN students student ON student.id = ? AND (session.course_id IS NULL OR session.course_id = student.course_id) AND (session.year_level_id IS NULL OR session.year_level_id = student.year_level_id) AND (session.section_id IS NULL OR session.section_id = student.section_id) WHERE session.id = ? LIMIT 1');
            mysqli_stmt_bind_param($sourceStmt, 'ii', $studentId, $sessionId);
            mysqli_stmt_execute($sourceStmt);
            $source = mysqli_fetch_assoc(mysqli_stmt_get_result($sourceStmt));

            if (!$source) {
                $message = 'The selected student does not belong to this attendance session.';
                $messageType = 'danger';
            } elseif (($timeOut !== null && $timeIn === null)
                || ($status === 'ABSENT' && ($timeIn !== null || $timeOut !== null))
                || ($status === 'PRESENT' && $timeIn === null)
                || ($status === 'LATE' && $timeIn === null)
                || ($status === 'INCOMPLETE' && ($timeIn === null || $timeOut !== null))) {
                $message = 'Attendance status and timestamps are inconsistent.';
                $messageType = 'danger';
            } else {
                $start = $source['start_time'];
                $end = $source['end_time'];
                if (($timeIn !== null && ($timeIn < substr($start, 0, 5) || $timeIn > substr($end, 0, 5)))
                    || ($timeOut !== null && ($timeOut < substr($start, 0, 5) || $timeOut > substr($end, 0, 5)))
                    || ($timeIn !== null && $timeOut !== null && $timeOut < $timeIn)) {
                    $message = 'Attendance times must be within the session schedule, and Time Out cannot precede Time In.';
                    $messageType = 'danger';
                } else {
                    $existingStmt = mysqli_prepare($conn, 'SELECT * FROM attendance_records WHERE session_id = ? AND student_id = ? LIMIT 1');
                    mysqli_stmt_bind_param($existingStmt, 'ii', $sessionId, $studentId);
                    mysqli_stmt_execute($existingStmt);
                    $oldRecord = mysqli_fetch_assoc(mysqli_stmt_get_result($existingStmt));
                    $oldStatus = $oldRecord ? attendanceStatus($oldRecord) : 'PENDING';
                    $oldTimeIn = $oldRecord['time_in'] ?? '';
                    $oldTimeOut = $oldRecord['time_out'] ?? '';
                    $newTimeIn = $timeIn === null ? null : $timeIn . ':00';
                    $newTimeOut = $timeOut === null ? null : $timeOut . ':00';
                    $adminId = (int) $_SESSION['user']['id'];
                    $details = 'Time In ' . ($oldTimeIn ?: 'N/A') . ' -> ' . ($newTimeIn ?: 'N/A')
                        . '; Time Out ' . ($oldTimeOut ?: 'N/A') . ' -> ' . ($newTimeOut ?: 'N/A');

                    try {
                        mysqli_begin_transaction($conn);
                        if ($oldRecord) {
                            $recordId = (int) $oldRecord['id'];
                            $update = mysqli_prepare($conn, 'UPDATE attendance_records SET time_in = ?, time_out = ?, status = ? WHERE id = ?');
                            mysqli_stmt_bind_param($update, 'sssi', $newTimeIn, $newTimeOut, $status, $recordId);
                            mysqli_stmt_execute($update);
                        } else {
                            $studentNumber = $source['student_number'];
                            $fullName = $source['full_name'];
                            $courseId = (int) $source['student_course_id'];
                            $yearId = (int) $source['student_year_id'];
                            $sectionId = (int) $source['student_section_id'];
                            $attendanceDate = $source['session_date'];
                            $insert = mysqli_prepare($conn, 'INSERT INTO attendance_records (session_id, student_id, student_name, course_id, year_level_id, section_id, attendance_date, time_in, time_out, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                            mysqli_stmt_bind_param($insert, 'iisiiissss', $sessionId, $studentId, $fullName, $courseId, $yearId, $sectionId, $attendanceDate, $newTimeIn, $newTimeOut, $status);
                            mysqli_stmt_execute($insert);
                        }

                        $action = $oldRecord ? 'attendance_update' : 'attendance_create';
                        $audit = mysqli_prepare($conn, 'INSERT INTO audit_logs (admin_id, action, student_id, session_id, old_status, new_status, details) VALUES (?, ?, ?, ?, ?, ?, ?)');
                        mysqli_stmt_bind_param($audit, 'isiisss', $adminId, $action, $studentId, $sessionId, $oldStatus, $status, $details);
                        mysqli_stmt_execute($audit);
                        mysqli_commit($conn);
                        $_SESSION['attendance_admin_message'] = 'Attendance record and audit log updated.';
                        redirect($_SERVER['REQUEST_URI'] ?? '/Attendance/admin/attendance-management.php');
                    } catch (mysqli_sql_exception $exception) {
                        mysqli_rollback($conn);
                        error_log('Admin attendance update failed: ' . $exception->getMessage());
                        $message = 'Attendance could not be updated. Please check the record and try again.';
                        $messageType = 'danger';
                    }
                }
            }
        }
    }
}

$selectedCourse = requestString($_GET, 'course_id');
$selectedYear = requestString($_GET, 'year_level_id');
$selectedSection = requestString($_GET, 'section_id');
$selectedDay = requestString($_GET, 'day');
$selectedStatus = strtoupper(requestString($_GET, 'status'));
$search = requestString($_GET, 'search');

$courseQuery = mysqli_query($conn, 'SELECT id, name FROM courses ORDER BY name');
$yearQuery = mysqli_query($conn, 'SELECT id, name FROM year_levels ORDER BY id');
$sectionQuery = mysqli_query($conn, 'SELECT id, name, course_id, year_level_id FROM sections ORDER BY name');
$params = [];
$types = '';
$sql = "SELECT record.id AS record_id, record.time_in, record.time_out, record.status AS saved_status,
               session.id AS session_id, session.session_name, session.session_date, session.start_time, session.end_time,
               student.id AS student_pk, student.student_id, student.full_name, course.name AS course_name,
               year.name AS year_name, section.name AS section_name,
               CASE WHEN record.time_in IS NOT NULL AND record.time_out IS NULL THEN 'INCOMPLETE'
                    WHEN record.id IS NOT NULL THEN record.status
                    WHEN session.status <> 'inactive' AND CONCAT(session.session_date, ' ', session.end_time) < NOW() THEN 'ABSENT'
                    ELSE 'PENDING' END AS attendance_status
        FROM attendance_sessions session
        JOIN students student ON (session.course_id IS NULL OR session.course_id = student.course_id)
                             AND (session.year_level_id IS NULL OR session.year_level_id = student.year_level_id)
                             AND (session.section_id IS NULL OR session.section_id = student.section_id)
        LEFT JOIN attendance_records record ON record.session_id = session.id AND record.student_id = student.id
        LEFT JOIN courses course ON course.id = student.course_id
        LEFT JOIN year_levels year ON year.id = student.year_level_id
        LEFT JOIN sections section ON section.id = student.section_id
        WHERE 1 = 1";

foreach ([
    ['course_id', $selectedCourse],
    ['year_level_id', $selectedYear],
    ['section_id', $selectedSection],
] as [$column, $value]) {
    if ($value !== '' && ctype_digit($value) && (int) $value > 0) {
        $sql .= ' AND student.' . $column . ' = ?';
        $params[] = (int) $value;
        $types .= 'i';
    }
}
if ($selectedDay !== '' && in_array($selectedDay, ['Day 1', 'Day 2', 'Day 3', 'Day 4', 'Day 5', 'Day 6'], true)) {
    $sql .= ' AND session.session_name = ?';
    $params[] = $selectedDay;
    $types .= 's';
}
if ($selectedStatus !== '' && in_array($selectedStatus, ['PRESENT', 'LATE', 'ABSENT', 'INCOMPLETE', 'PENDING'], true)) {
    $sql .= " AND CASE WHEN record.time_in IS NOT NULL AND record.time_out IS NULL THEN 'INCOMPLETE'
                       WHEN record.id IS NOT NULL THEN record.status
                       WHEN session.status <> 'inactive' AND CONCAT(session.session_date, ' ', session.end_time) < NOW() THEN 'ABSENT'
                       ELSE 'PENDING' END = ?";
    $params[] = $selectedStatus;
    $types .= 's';
}
if ($search !== '') {
    $sql .= ' AND (student.full_name LIKE ? OR student.student_id LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $types .= 'ss';
}
$sql .= " ORDER BY session.session_date DESC, FIELD(session.session_name, 'Day 1','Day 2','Day 3','Day 4','Day 5','Day 6'), student.full_name";
$stmt = mysqli_prepare($conn, $sql);
bindStatementParams($stmt, $types, $params);
mysqli_stmt_execute($stmt);
$records = mysqli_stmt_get_result($stmt);

include __DIR__ . '/../includes/header.php';
?>

<div class="card p-4 mb-4">
    <h4 class="fw-bold mb-3">Attendance Management</h4>
    <?php if ($message !== ''): ?>
        <div class="alert alert-<?= htmlspecialchars($messageType); ?>"><?= htmlspecialchars($message); ?></div>
    <?php endif; ?>
    <form method="get" class="row g-3">
        <div class="col-md-4">
            <label class="form-label">Student Name or ID</label>
            <input class="form-control" name="search" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label">Course</label>
            <select class="form-select" name="course_id">
                <option value="">All</option>
                <?php while ($course = mysqli_fetch_assoc($courseQuery)): ?>
                    <option value="<?= (int) $course['id']; ?>" <?= $selectedCourse == $course['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($course['name']); ?></option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Year Level</label>
            <select class="form-select" name="year_level_id">
                <option value="">All</option>
                <?php while ($year = mysqli_fetch_assoc($yearQuery)): ?>
                    <option value="<?= (int) $year['id']; ?>" <?= $selectedYear == $year['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($year['name']); ?></option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Section</label>
            <select class="form-select" name="section_id">
                <option value="">All</option>
                <?php while ($section = mysqli_fetch_assoc($sectionQuery)): ?>
                    <option value="<?= (int) $section['id']; ?>" <?= $selectedSection == $section['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($section['name']); ?></option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Day</label>
            <select class="form-select" name="day">
                <option value="">All</option>
                <?php foreach (['Day 1', 'Day 2', 'Day 3', 'Day 4', 'Day 5', 'Day 6'] as $day): ?>
                    <option value="<?= htmlspecialchars($day); ?>" <?= $selectedDay === $day ? 'selected' : ''; ?>><?= htmlspecialchars($day); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Status</label>
            <select class="form-select" name="status">
                <option value="">All</option>
                <?php foreach (['PRESENT', 'LATE', 'ABSENT', 'INCOMPLETE', 'PENDING'] as $status): ?>
                    <option value="<?= $status; ?>" <?= $selectedStatus === $status ? 'selected' : ''; ?>><?= htmlspecialchars($status); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2 d-flex align-items-end">
            <button type="submit" class="btn btn-primary w-100">Filter</button>
        </div>
        <div class="col-md-2 d-flex align-items-end">
            <a class="btn btn-outline-secondary w-100" href="/Attendance/admin/attendance-management.php">Clear</a>
        </div>
    </form>
</div>

<div class="card p-4">
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>Student ID</th><th>Student Name</th><th>Course</th><th>Year</th><th>Section</th>
                    <th>Day</th><th>Date</th><th>Time In</th><th>Time Out</th><th>Status</th><th>Save Correction</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($record = mysqli_fetch_assoc($records)): ?>
                    <?php $status = $record['attendance_status']; ?>
                    <tr>
                        <td><?= htmlspecialchars($record['student_id']); ?></td>
                        <td><?= htmlspecialchars($record['full_name']); ?></td>
                        <td><?= htmlspecialchars($record['course_name'] ?? 'N/A'); ?></td>
                        <td><?= htmlspecialchars($record['year_name'] ?? 'N/A'); ?></td>
                        <td><?= htmlspecialchars($record['section_name'] ?? 'N/A'); ?></td>
                        <td><?= htmlspecialchars($record['session_name']); ?></td>
                        <td><?= htmlspecialchars($record['session_date']); ?></td>
                        <td><?= htmlspecialchars($record['time_in'] ?? 'N/A'); ?></td>
                        <td><?= htmlspecialchars($record['time_out'] ?? 'N/A'); ?></td>
                        <td><span class="badge bg-secondary"><?= htmlspecialchars($status); ?></span></td>
                        <td>
                            <form method="post" class="d-flex gap-2 flex-wrap">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="session_id" value="<?= (int) $record['session_id']; ?>">
                                <input type="hidden" name="student_pk" value="<?= (int) $record['student_pk']; ?>">
                                <input type="time" class="form-control form-control-sm" name="time_in" value="<?= $record['time_in'] ? htmlspecialchars(substr($record['time_in'], 0, 5)) : ''; ?>" aria-label="Time In">
                                <input type="time" class="form-control form-control-sm" name="time_out" value="<?= $record['time_out'] ? htmlspecialchars(substr($record['time_out'], 0, 5)) : ''; ?>" aria-label="Time Out">
                                <select class="form-select form-select-sm" name="status" required>
                                    <option value="" <?= $status === 'PENDING' ? 'selected' : ''; ?>>Choose status</option>
                                    <?php foreach ($validStatuses as $option): ?>
                                        <option value="<?= $option; ?>" <?= $status === $option ? 'selected' : ''; ?>><?= $option; ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button class="btn btn-sm btn-primary" type="submit">Save</button>
                            </form>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
