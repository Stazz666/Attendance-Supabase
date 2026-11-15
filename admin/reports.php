<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole(['admin']);

$days = ['Day 1', 'Day 2', 'Day 3', 'Day 4', 'Day 5', 'Day 6'];
$mode = requestString($_GET, 'mode', 'daily');
if (!in_array($mode, ['daily', 'six_day'], true)) {
    $mode = 'daily';
}
$selectedCourse = requestString($_GET, 'course_id');
$selectedYear = requestString($_GET, 'year_level_id');
$selectedSection = requestString($_GET, 'section_id');
$selectedDay = requestString($_GET, 'day');
$selectedStatus = strtoupper(requestString($_GET, 'status'));
$search = requestString($_GET, 'search');
$sessionDate = requestString($_GET, 'session_date');
$allowedStatuses = ['PRESENT', 'LATE', 'ABSENT', 'INCOMPLETE', 'PENDING'];
if (!in_array($selectedDay, $days, true)) {
    $selectedDay = '';
}
if (!in_array($selectedStatus, $allowedStatuses, true)) {
    $selectedStatus = '';
}
if ($sessionDate !== '') {
    $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $sessionDate, new DateTimeZone('Asia/Manila'));
    if (!$parsedDate || $parsedDate->format('Y-m-d') !== $sessionDate) {
        $sessionDate = '';
    }
}

$courseQuery = mysqli_query($conn, 'SELECT id, name FROM courses ORDER BY name');
$yearQuery = mysqli_query($conn, 'SELECT id, name FROM year_levels ORDER BY id');
$sectionQuery = mysqli_query($conn, 'SELECT id, name FROM sections ORDER BY name');
$rows = [];
$daySessions = [];

if ($mode === 'daily') {
    $params = [];
    $types = '';
    $sql = "SELECT student.student_id, student.full_name, course.name AS course_name, year.name AS year_name,
                   section.name AS section_name, session.session_name, session.session_date, session.end_time, session.status AS session_status, record.time_in,
                   record.time_out, record.status AS saved_status,
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

    foreach ([['course_id', $selectedCourse], ['year_level_id', $selectedYear], ['section_id', $selectedSection]] as [$column, $value]) {
        if ($value !== '' && ctype_digit($value) && (int) $value > 0) {
            $sql .= ' AND student.' . $column . ' = ?';
            $params[] = (int) $value;
            $types .= 'i';
        }
    }
    if ($selectedDay !== '') {
        $sql .= ' AND session.session_name = ?';
        $params[] = $selectedDay;
        $types .= 's';
    }
    if ($sessionDate !== '') {
        $sql .= ' AND session.session_date = ?';
        $params[] = $sessionDate;
        $types .= 's';
    }
    if ($selectedStatus !== '') {
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
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $row['attendance_status'] = attendanceStatus(
            ['time_in' => $row['time_in'], 'time_out' => $row['time_out'], 'status' => $row['saved_status'], 'session_status' => $row['session_status']],
            $row['session_date'] . ' ' . ($row['end_time'] ?? '00:00:00')
        );
        $rows[] = $row;
    }
} else {
    foreach ($days as $day) {
        $sessionStmt = mysqli_prepare($conn, "SELECT id, session_name, session_date, start_time, end_time, status FROM attendance_sessions WHERE session_name = ? AND course_id IS NULL AND year_level_id IS NULL AND section_id IS NULL ORDER BY session_date DESC, created_at DESC LIMIT 1");
        mysqli_stmt_bind_param($sessionStmt, 's', $day);
        mysqli_stmt_execute($sessionStmt);
        $daySessions[$day] = mysqli_fetch_assoc(mysqli_stmt_get_result($sessionStmt)) ?: null;
    }

    $params = [];
    $types = '';
    $studentSql = "SELECT student.id, student.student_id, student.full_name, course.name AS course_name,
                          year.name AS year_name, section.name AS section_name
                   FROM students student
                   LEFT JOIN courses course ON course.id = student.course_id
                   LEFT JOIN year_levels year ON year.id = student.year_level_id
                   LEFT JOIN sections section ON section.id = student.section_id
                   WHERE 1 = 1";
    foreach ([['course_id', $selectedCourse], ['year_level_id', $selectedYear], ['section_id', $selectedSection]] as [$column, $value]) {
        if ($value !== '' && ctype_digit($value) && (int) $value > 0) {
            $studentSql .= ' AND student.' . $column . ' = ?';
            $params[] = (int) $value;
            $types .= 'i';
        }
    }
    if ($search !== '') {
        $studentSql .= ' AND (student.full_name LIKE ? OR student.student_id LIKE ?)';
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
        $types .= 'ss';
    }
    $studentSql .= ' ORDER BY student.full_name';
    $studentStmt = mysqli_prepare($conn, $studentSql);
    bindStatementParams($studentStmt, $types, $params);
    mysqli_stmt_execute($studentStmt);
    $studentResult = mysqli_stmt_get_result($studentStmt);
    $students = [];
    while ($student = mysqli_fetch_assoc($studentResult)) {
        $student['days'] = [];
        foreach ($days as $day) {
            $session = $daySessions[$day];
            $student['days'][$day] = [
                'session_date' => $session['session_date'] ?? '',
                'time_in' => '',
                'time_out' => '',
                'status' => $session ? attendanceStatus(['session_status' => $session['status']], $session['session_date'] . ' ' . $session['end_time']) : 'NO SESSION',
            ];
        }
        $students[(int) $student['id']] = $student;
    }

    $ids = array_values(array_filter(array_map(static function ($session) {
        return $session ? (int) $session['id'] : null;
    }, $daySessions)));
    if ($ids && $students) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $recordSql = "SELECT record.student_id, session.session_name, session.session_date, session.end_time, session.status AS session_status,
                             record.time_in, record.time_out, record.status
                      FROM attendance_records record
                      JOIN attendance_sessions session ON session.id = record.session_id
                      WHERE session.id IN ($placeholders)";
        $recordParams = $ids;
        $recordTypes = str_repeat('i', count($ids));
        $recordStmt = mysqli_prepare($conn, $recordSql);
        bindStatementParams($recordStmt, $recordTypes, $recordParams);
        mysqli_stmt_execute($recordStmt);
        $recordResult = mysqli_stmt_get_result($recordStmt);
        while ($record = mysqli_fetch_assoc($recordResult)) {
            $studentKey = (int) $record['student_id'];
            if (isset($students[$studentKey])) {
                $students[$studentKey]['days'][$record['session_name']] = [
                    'session_date' => $record['session_date'],
                    'time_in' => $record['time_in'] ?? '',
                    'time_out' => $record['time_out'] ?? '',
                    'status' => attendanceStatus($record, $record['session_date'] . ' ' . $record['end_time']),
                ];
            }
        }
    }

    foreach ($students as $student) {
        $statusesToCheck = $selectedDay !== '' ? [$student['days'][$selectedDay]['status']] : array_column($student['days'], 'status');
        if ($selectedStatus !== '' && !in_array($selectedStatus, $statusesToCheck, true)) {
            continue;
        }
        $rows[] = $student;
    }
}

if (requestString($_GET, 'export') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . ($mode === 'daily' ? 'daily-attendance.csv' : 'six-day-attendance.csv') . '"');
    $output = fopen('php://output', 'w');
    if ($mode === 'daily') {
        fputcsv($output, ['Student ID', 'Student Name', 'Course', 'Year Level', 'Section', 'Attendance Day', 'Date', 'Time In', 'Time Out', 'Status']);
        foreach ($rows as $row) {
            fputcsv($output, [$row['student_id'], $row['full_name'], $row['course_name'], $row['year_name'], $row['section_name'], $row['session_name'], $row['session_date'], $row['time_in'], $row['time_out'], $row['attendance_status']]);
        }
    } else {
        $headers = ['Student ID', 'Full Name', 'Course', 'Year Level', 'Section'];
        foreach ($days as $day) {
            $headers = array_merge($headers, [$day . ' Date', $day . ' Time In', $day . ' Time Out', $day . ' Status']);
        }
        fputcsv($output, $headers);
        foreach ($rows as $row) {
            $line = [$row['student_id'], $row['full_name'], $row['course_name'], $row['year_name'], $row['section_name']];
            foreach ($days as $day) {
                $line = array_merge($line, array_values($row['days'][$day]));
            }
            fputcsv($output, $line);
        }
    }
    fclose($output);
    exit;
}

include __DIR__ . '/../includes/header.php';
?>

<style>
    @media print {
        .report-controls, .sidebar, .topbar, .btn { display: none !important; }
        main { width: 100% !important; }
        .card { box-shadow: none !important; }
    }
</style>
<div class="card p-4 mb-4 report-controls">
    <h4 class="fw-bold mb-3">Attendance Reports</h4>
    <form method="get" class="row g-3">
        <input type="hidden" name="mode" value="<?= htmlspecialchars($mode); ?>">
        <div class="col-md-3">
            <label class="form-label">Student Name or ID</label>
            <input type="search" class="form-control" name="search" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label">Course</label>
            <select class="form-select" name="course_id"><option value="">All</option>
                <?php while ($course = mysqli_fetch_assoc($courseQuery)): ?>
                    <option value="<?= (int) $course['id']; ?>" <?= $selectedCourse == $course['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($course['name']); ?></option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Year Level</label>
            <select class="form-select" name="year_level_id"><option value="">All</option>
                <?php while ($year = mysqli_fetch_assoc($yearQuery)): ?>
                    <option value="<?= (int) $year['id']; ?>" <?= $selectedYear == $year['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($year['name']); ?></option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Section</label>
            <select class="form-select" name="section_id"><option value="">All</option>
                <?php while ($section = mysqli_fetch_assoc($sectionQuery)): ?>
                    <option value="<?= (int) $section['id']; ?>" <?= $selectedSection == $section['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($section['name']); ?></option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Attendance Day</label>
            <select class="form-select" name="day"><option value="">All</option>
                <?php foreach ($days as $day): ?>
                    <option value="<?= htmlspecialchars($day); ?>" <?= $selectedDay === $day ? 'selected' : ''; ?>><?= htmlspecialchars($day); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Status</label>
            <select class="form-select" name="status"><option value="">All</option>
                <?php foreach ($allowedStatuses as $status): ?>
                    <option value="<?= $status; ?>" <?= $selectedStatus === $status ? 'selected' : ''; ?>><?= htmlspecialchars($status); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($mode === 'daily'): ?>
            <div class="col-md-2">
                <label class="form-label">Attendance Date</label>
                <input type="date" class="form-control" name="session_date" value="<?= htmlspecialchars($sessionDate); ?>">
            </div>
        <?php endif; ?>
        <div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100" type="submit">Filter</button></div>
    </form>
    <div class="mt-3 d-flex gap-2 flex-wrap">
        <a class="btn btn-outline-primary" href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['mode' => 'daily'])), ENT_QUOTES, 'UTF-8'); ?>">Daily Report</a>
        <a class="btn btn-outline-primary" href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['mode' => 'six_day'])), ENT_QUOTES, 'UTF-8'); ?>">Six-Day Report</a>
        <a class="btn btn-outline-primary" href="/Attendance/admin/event-attendance.php">Event Attendance</a>
        <a class="btn btn-outline-primary" href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['export' => 'csv'])), ENT_QUOTES, 'UTF-8'); ?>">Export CSV</a>
        <button class="btn btn-outline-secondary" type="button" onclick="window.print()">Print</button>
    </div>
</div>

<div class="card p-4">
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <?php if ($mode === 'daily'): ?>
                    <tr><th>Student ID</th><th>Student</th><th>Course</th><th>Year</th><th>Section</th><th>Day</th><th>Date</th><th>Time In</th><th>Time Out</th><th>Status</th></tr>
                <?php else: ?>
                    <tr><th>Student ID</th><th>Full Name</th><th>Course</th><th>Year</th><th>Section</th>
                        <?php foreach ($days as $day): ?><th colspan="4"><?= htmlspecialchars($day); ?> (<?= htmlspecialchars($daySessions[$day]['session_date'] ?? 'No session'); ?>)</th><?php endforeach; ?>
                    </tr>
                    <tr><th colspan="5"></th><?php foreach ($days as $day): ?><th>Date</th><th>Time In</th><th>Time Out</th><th>Status</th><?php endforeach; ?></tr>
                <?php endif; ?>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php if ($mode === 'daily'): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['student_id']); ?></td><td><?= htmlspecialchars($row['full_name']); ?></td>
                            <td><?= htmlspecialchars($row['course_name'] ?? 'N/A'); ?></td><td><?= htmlspecialchars($row['year_name'] ?? 'N/A'); ?></td>
                            <td><?= htmlspecialchars($row['section_name'] ?? 'N/A'); ?></td><td><?= htmlspecialchars($row['session_name']); ?></td>
                            <td><?= htmlspecialchars($row['session_date']); ?></td><td><?= htmlspecialchars($row['time_in'] ?? 'N/A'); ?></td>
                            <td><?= htmlspecialchars($row['time_out'] ?? 'N/A'); ?></td><td><?= htmlspecialchars($row['attendance_status']); ?></td>
                        </tr>
                    <?php else: ?>
                        <tr>
                            <td><?= htmlspecialchars($row['student_id']); ?></td><td><?= htmlspecialchars($row['full_name']); ?></td>
                            <td><?= htmlspecialchars($row['course_name'] ?? 'N/A'); ?></td><td><?= htmlspecialchars($row['year_name'] ?? 'N/A'); ?></td>
                            <td><?= htmlspecialchars($row['section_name'] ?? 'N/A'); ?></td>
                            <?php foreach ($days as $day): $dayRecord = $row['days'][$day]; ?>
                                <td><?= htmlspecialchars($dayRecord['session_date'] ?: 'N/A'); ?></td>
                                <td><?= htmlspecialchars($dayRecord['time_in'] ?: 'N/A'); ?></td>
                                <td><?= htmlspecialchars($dayRecord['time_out'] ?: 'N/A'); ?></td>
                                <td><?= htmlspecialchars($dayRecord['status']); ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
