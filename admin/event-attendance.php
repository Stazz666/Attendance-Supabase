<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole(['admin']);

$eventId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$currentTime = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
$currentDateTime = $currentTime->format('Y-m-d H:i:s');

if ($eventId && $eventId > 0) {
    $eventStmt = mysqli_prepare($conn, 'SELECT * FROM events WHERE id = ? LIMIT 1');
    mysqli_stmt_bind_param($eventStmt, 'i', $eventId);
    mysqli_stmt_execute($eventStmt);
    $event = mysqli_fetch_assoc(mysqli_stmt_get_result($eventStmt));
    if (!$event) {
        http_response_code(404);
        exit('Event not found.');
    }

    $eventEnd = new DateTimeImmutable($event['event_date'] . ' ' . $event['end_time'], new DateTimeZone('Asia/Manila'));
    $isClosed = $eventEnd < $currentTime;
    $records = mysqli_prepare($conn, "SELECT student.student_id, student.full_name, course.name AS course_name,
            year.name AS year_name, section.name AS section_name, attendance.time_in, attendance.status
        FROM event_student_qrs qr
        JOIN students student ON student.id = qr.student_id
        JOIN users account ON account.id = student.user_id AND account.role = 'student'
        LEFT JOIN courses course ON course.id = student.course_id
        LEFT JOIN year_levels year ON year.id = student.year_level_id
        LEFT JOIN sections section ON section.id = student.section_id
        LEFT JOIN event_attendance attendance ON attendance.event_id = qr.event_id AND attendance.student_id = qr.student_id
        WHERE qr.event_id = ?
        ORDER BY student.full_name");
    mysqli_stmt_bind_param($records, 'i', $eventId);
    mysqli_stmt_execute($records);
    $result = mysqli_stmt_get_result($records);

    include __DIR__ . '/../includes/header.php';
    ?>
    <div class="card p-4 mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h4 class="fw-bold mb-1"><?= htmlspecialchars($event['event_name'], ENT_QUOTES, 'UTF-8'); ?></h4>
                <div class="text-muted"><?= htmlspecialchars($event['event_date'] . ' · ' . substr($event['start_time'], 0, 5) . '–' . substr($event['end_time'], 0, 5) . ' · Late after ' . substr($event['late_time'], 0, 5), ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
            <div class="d-flex gap-2">
                <a class="btn btn-outline-secondary" href="/Attendance/admin/event-attendance.php">All Event Attendance</a>
                <a class="btn btn-primary" href="/Attendance/admin/scanner.php?event_id=<?= (int) $eventId; ?>">Open Scanner</a>
            </div>
        </div>
    </div>
    <div class="card p-4" id="attendance">
        <h5 class="fw-bold mb-3">Event Attendance</h5>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead><tr><th>Student ID</th><th>Student Name</th><th>Course</th><th>Year Level</th><th>Section</th><th>Time In</th><th>Time Out</th><th>Attendance Status</th></tr></thead>
                <tbody>
                    <?php while ($row = mysqli_fetch_assoc($result)): ?>
                        <?php $status = $row['status'] ?? ($isClosed ? 'ABSENT' : 'NOT CHECKED IN'); ?>
                        <tr>
                            <td><?= htmlspecialchars($row['student_id'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?= htmlspecialchars($row['full_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?= htmlspecialchars($row['course_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?= htmlspecialchars($row['year_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?= htmlspecialchars($row['section_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?= htmlspecialchars($row['time_in'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>N/A</td>
                            <td><span class="badge bg-<?= $status === 'PRESENT' ? 'success' : ($status === 'LATE' ? 'warning' : 'secondary'); ?>"><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?></span></td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
    include __DIR__ . '/../includes/footer.php';
    exit;
}

$selectedEventId = requestString($_GET, 'event_id');
$selectedDate = requestString($_GET, 'date');
$selectedCourse = requestString($_GET, 'course_id');
$selectedYear = requestString($_GET, 'year_level_id');
$selectedSection = requestString($_GET, 'section_id');
$selectedStatus = strtoupper(requestString($_GET, 'status'));
$search = requestString($_GET, 'search');
$allowedStatuses = ['PRESENT', 'LATE', 'ABSENT', 'NOT CHECKED IN'];
if (!in_array($selectedStatus, $allowedStatuses, true)) {
    $selectedStatus = '';
}
if ($selectedDate !== '') {
    $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $selectedDate, new DateTimeZone('Asia/Manila'));
    if (!$parsedDate || $parsedDate->format('Y-m-d') !== $selectedDate) {
        $selectedDate = '';
    }
}

$eventOptions = mysqli_query($conn, 'SELECT id, event_name FROM events ORDER BY event_date DESC, start_time DESC, id DESC');
$courseOptions = mysqli_query($conn, 'SELECT id, name FROM courses ORDER BY name');
$yearOptions = mysqli_query($conn, 'SELECT id, name FROM year_levels ORDER BY id');
$sectionOptions = mysqli_query($conn, 'SELECT id, name FROM sections ORDER BY name');

$statusExpression = "CASE WHEN attendance.status IS NOT NULL THEN attendance.status
                          WHEN CONCAT(event.event_date, ' ', event.end_time) < ? THEN 'ABSENT'
                          ELSE 'NOT CHECKED IN' END";
$sql = "SELECT event.id AS event_id, event.event_name, event.event_date,
               student.student_id, student.full_name, course.name AS course_name,
               year.name AS year_name, section.name AS section_name,
               attendance.time_in, attendance.status AS saved_status,
               $statusExpression AS attendance_status
        FROM event_student_qrs qr
        JOIN events event ON event.id = qr.event_id
        JOIN students student ON student.id = qr.student_id
        JOIN users account ON account.id = student.user_id AND account.role = 'student'
        LEFT JOIN courses course ON course.id = student.course_id
        LEFT JOIN year_levels year ON year.id = student.year_level_id
        LEFT JOIN sections section ON section.id = student.section_id
        LEFT JOIN event_attendance attendance ON attendance.event_id = qr.event_id AND attendance.student_id = qr.student_id
        WHERE 1 = 1";
$params = [$currentDateTime];
$types = 's';
foreach ([
    ['event_id', $selectedEventId, 'event.id'],
    ['course_id', $selectedCourse, 'student.course_id'],
    ['year_level_id', $selectedYear, 'student.year_level_id'],
    ['section_id', $selectedSection, 'student.section_id'],
] as [$key, $value, $column]) {
    if ($value !== '' && ctype_digit($value) && (int) $value > 0) {
        $sql .= ' AND ' . $column . ' = ?';
        $params[] = (int) $value;
        $types .= 'i';
    }
}
if ($selectedDate !== '') {
    $sql .= ' AND event.event_date = ?';
    $params[] = $selectedDate;
    $types .= 's';
}
if ($selectedStatus !== '') {
    $sql .= ' AND ' . $statusExpression . ' = ?';
    $params[] = $currentDateTime;
    $params[] = $selectedStatus;
    $types .= 'ss';
}
if ($search !== '') {
    $sql .= ' AND (student.full_name LIKE ? OR student.student_id LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $types .= 'ss';
}
$sql .= ' ORDER BY event.event_date DESC, event.start_time DESC, student.full_name';
$stmt = mysqli_prepare($conn, $sql);
bindStatementParams($stmt, $types, $params);
mysqli_stmt_execute($stmt);
$records = mysqli_stmt_get_result($stmt);

if (requestString($_GET, 'export') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="event-attendance.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Event Name', 'Event Date', 'Student Name', 'Student ID', 'Course', 'Year Level', 'Section', 'Time In', 'Time Out', 'Attendance Status']);
    while ($row = mysqli_fetch_assoc($records)) {
        fputcsv($output, [
            $row['event_name'],
            $row['event_date'],
            $row['full_name'],
            $row['student_id'],
            $row['course_name'],
            $row['year_name'],
            $row['section_name'],
            $row['time_in'],
            'N/A',
            $row['attendance_status'],
        ]);
    }
    fclose($output);
    exit;
}

include __DIR__ . '/../includes/header.php';
?>
<style>
    @media print {
        .event-attendance-controls, .sidebar, .topbar, .btn { display: none !important; }
        main { width: 100% !important; }
        .card { box-shadow: none !important; }
    }
</style>
<div class="card p-4 mb-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 event-attendance-controls">
        <h4 class="fw-bold mb-0">Event Attendance</h4>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-primary" href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['export' => 'csv'])), ENT_QUOTES, 'UTF-8'); ?>">Export CSV</a>
            <button class="btn btn-outline-secondary" type="button" onclick="window.print()">Print</button>
        </div>
    </div>
    <form method="get" class="row g-3 event-attendance-controls">
        <div class="col-md-3">
            <label class="form-label" for="event_id">Event</label>
            <select class="form-select" id="event_id" name="event_id">
                <option value="">All events</option>
                <?php while ($option = mysqli_fetch_assoc($eventOptions)): ?>
                    <option value="<?= (int) $option['id']; ?>" <?= $selectedEventId == $option['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($option['event_name'], ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label" for="date">Event Date</label>
            <input class="form-control" id="date" type="date" name="date" value="<?= htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8'); ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label" for="search">Student Name or ID</label>
            <input class="form-control" id="search" type="search" name="search" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label" for="course_id">Course</label>
            <select class="form-select" id="course_id" name="course_id">
                <option value="">All</option>
                <?php while ($option = mysqli_fetch_assoc($courseOptions)): ?>
                    <option value="<?= (int) $option['id']; ?>" <?= $selectedCourse == $option['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($option['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label" for="year_level_id">Year Level</label>
            <select class="form-select" id="year_level_id" name="year_level_id">
                <option value="">All</option>
                <?php while ($option = mysqli_fetch_assoc($yearOptions)): ?>
                    <option value="<?= (int) $option['id']; ?>" <?= $selectedYear == $option['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($option['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label" for="section_id">Section</label>
            <select class="form-select" id="section_id" name="section_id">
                <option value="">All</option>
                <?php while ($option = mysqli_fetch_assoc($sectionOptions)): ?>
                    <option value="<?= (int) $option['id']; ?>" <?= $selectedSection == $option['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($option['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label" for="status">Attendance Status</label>
            <select class="form-select" id="status" name="status">
                <option value="">All</option>
                <?php foreach ($allowedStatuses as $status): ?>
                    <option value="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>" <?= $selectedStatus === $status ? 'selected' : ''; ?>><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100" type="submit">Filter</button></div>
        <div class="col-md-2 d-flex align-items-end"><a class="btn btn-outline-secondary w-100" href="/Attendance/admin/event-attendance.php">Clear</a></div>
    </form>
</div>
<div class="card p-4">
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead><tr><th>Event Name</th><th>Event Date</th><th>Student Name</th><th>Student ID</th><th>Course</th><th>Year Level</th><th>Section</th><th>Time In</th><th>Time Out</th><th>Attendance Status</th></tr></thead>
            <tbody>
                <?php while ($row = mysqli_fetch_assoc($records)): ?>
                    <?php $status = $row['attendance_status']; ?>
                    <tr>
                        <td><a href="/Attendance/admin/event-attendance.php?id=<?= (int) $row['event_id']; ?>"><?= htmlspecialchars($row['event_name'], ENT_QUOTES, 'UTF-8'); ?></a></td>
                        <td><?= htmlspecialchars($row['event_date'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?= htmlspecialchars($row['full_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?= htmlspecialchars($row['student_id'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?= htmlspecialchars($row['course_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?= htmlspecialchars($row['year_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?= htmlspecialchars($row['section_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?= htmlspecialchars($row['time_in'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td>N/A</td>
                        <td><span class="badge bg-<?= $status === 'PRESENT' ? 'success' : ($status === 'LATE' ? 'warning' : 'secondary'); ?>"><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?></span></td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
