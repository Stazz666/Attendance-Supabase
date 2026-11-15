<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole(['admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $respond = static function (array $payload, int $status = 200): void {
        http_response_code($status);
        echo json_encode($payload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
        exit;
    };

    if (!csrfTokenIsValid()) {
        $respond(['result' => 'error', 'message' => 'Your request expired. Reload the scanner and try again.'], 400);
    }

    $action = requestString($_POST, 'action');
    if ($action === 'search_students') {
        $search = requestString($_POST, 'search');
        if (mb_strlen($search) < 2) {
            $respond(['students' => []]);
        }
        $like = '%' . $search . '%';
        $stmt = mysqli_prepare($conn, "SELECT student.id, student.student_id, student.full_name, course.name AS course_name, year.name AS year_name, section.name AS section_name FROM students student JOIN users account ON account.id = student.user_id AND account.role = 'student' LEFT JOIN courses course ON course.id = student.course_id LEFT JOIN year_levels year ON year.id = student.year_level_id LEFT JOIN sections section ON section.id = student.section_id WHERE student.student_id LIKE ? OR student.full_name LIKE ? OR course.name LIKE ? OR year.name LIKE ? OR section.name LIKE ? ORDER BY student.full_name LIMIT 20");
        mysqli_stmt_bind_param($stmt, 'sssss', $like, $like, $like, $like, $like);
        mysqli_stmt_execute($stmt);
        $students = [];
        $result = mysqli_stmt_get_result($stmt);
        while ($student = mysqli_fetch_assoc($result)) {
            $students[] = $student;
        }
        $respond(['students' => $students]);
    }

    if (!in_array($action, ['scan', 'manual_checkin'], true)) {
        $respond(['result' => 'error', 'message' => 'Invalid scanner action.'], 400);
    }
    $eventId = filter_var($_POST['event_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$eventId || $eventId <= 0) {
        $respond(['result' => 'error', 'message' => 'Select an event first.'], 400);
    }
    $eventStmt = mysqli_prepare($conn, 'SELECT * FROM events WHERE id = ? LIMIT 1');
    mysqli_stmt_bind_param($eventStmt, 'i', $eventId);
    mysqli_stmt_execute($eventStmt);
    $event = mysqli_fetch_assoc(mysqli_stmt_get_result($eventStmt));
    if (!$event) {
        $respond(['result' => 'error', 'message' => 'Event not found.'], 404);
    }

    $student = null;
    if ($action === 'scan') {
        $token = requestString($_POST, 'token');
        if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
            $respond(['result' => 'invalid_qr', 'message' => 'INVALID QR CODE']);
        }
        $qrStmt = mysqli_prepare($conn, 'SELECT event_id, student_id FROM event_student_qrs WHERE qr_token = ? LIMIT 1');
        mysqli_stmt_bind_param($qrStmt, 's', $token);
        mysqli_stmt_execute($qrStmt);
        $qr = mysqli_fetch_assoc(mysqli_stmt_get_result($qrStmt));
        if (!$qr) {
            $respond(['result' => 'invalid_qr', 'message' => 'INVALID QR CODE']);
        }
        if ((int) $qr['event_id'] !== (int) $eventId) {
            $respond(['result' => 'wrong_event', 'message' => 'WRONG EVENT']);
        }
        $studentPk = (int) $qr['student_id'];
        $studentStmt = mysqli_prepare($conn, "SELECT student.id AS student_pk, student.student_id, student.full_name, course.name AS course_name, year.name AS year_name, section.name AS section_name FROM students student JOIN users account ON account.id = student.user_id AND account.role = 'student' LEFT JOIN courses course ON course.id = student.course_id LEFT JOIN year_levels year ON year.id = student.year_level_id LEFT JOIN sections section ON section.id = student.section_id WHERE student.id = ? LIMIT 1");
        mysqli_stmt_bind_param($studentStmt, 'i', $studentPk);
        mysqli_stmt_execute($studentStmt);
        $student = mysqli_fetch_assoc(mysqli_stmt_get_result($studentStmt));
        if (!$student) {
            $respond(['result' => 'student_not_found', 'message' => 'STUDENT NOT FOUND']);
        }
    } else {
        $studentPk = filter_var($_POST['student_pk'] ?? null, FILTER_VALIDATE_INT);
        if (!$studentPk || $studentPk <= 0) {
            $respond(['result' => 'error', 'message' => 'Select a student for manual check-in.'], 400);
        }
        $studentStmt = mysqli_prepare($conn, "SELECT student.id AS student_pk, student.student_id, student.full_name, course.name AS course_name, year.name AS year_name, section.name AS section_name FROM event_student_qrs qr JOIN students student ON student.id = qr.student_id JOIN users account ON account.id = student.user_id AND account.role = 'student' LEFT JOIN courses course ON course.id = student.course_id LEFT JOIN year_levels year ON year.id = student.year_level_id LEFT JOIN sections section ON section.id = student.section_id WHERE qr.event_id = ? AND student.id = ? LIMIT 1");
        mysqli_stmt_bind_param($studentStmt, 'ii', $eventId, $studentPk);
        mysqli_stmt_execute($studentStmt);
        $student = mysqli_fetch_assoc(mysqli_stmt_get_result($studentStmt));
        if (!$student) {
            $respond(['result' => 'student_not_found', 'message' => 'STUDENT NOT FOUND OR NOT REGISTERED FOR THIS EVENT']);
        }
    }

    $timezone = new DateTimeZone('Asia/Manila');
    $now = new DateTimeImmutable('now', $timezone);
    $scheduleMessage = eventAttendanceScheduleMessage($event, $now);
    if ($scheduleMessage !== null) {
        $respond(['result' => 'event_unavailable', 'message' => $scheduleMessage]);
    }

    $studentPk = (int) $student['student_pk'];
    $timeIn = $now->format('Y-m-d H:i:s');
    $attendanceStatus = $now->format('H:i:s') > $event['late_time'] ? 'LATE' : 'PRESENT';
    try {
        $insert = mysqli_prepare($conn, 'INSERT INTO event_attendance (event_id, student_id, time_in, status) VALUES (?, ?, ?, ?)');
        mysqli_stmt_bind_param($insert, 'iiss', $eventId, $studentPk, $timeIn, $attendanceStatus);
        mysqli_stmt_execute($insert);
        $respond([
            'result' => 'success',
            'message' => 'SUCCESSFUL CHECK-IN',
            'student' => $student,
            'event_name' => $event['event_name'],
            'time_in' => $timeIn,
            'status' => $attendanceStatus,
        ]);
    } catch (mysqli_sql_exception $exception) {
        if ($exception->getCode() !== 1062) {
            error_log('Event attendance check-in failed: ' . $exception->getMessage());
            $respond(['result' => 'error', 'message' => 'Check-in could not be recorded. Please retry or contact the administrator.'], 500);
        }
        $existingStmt = mysqli_prepare($conn, 'SELECT time_in, status FROM event_attendance WHERE event_id = ? AND student_id = ? LIMIT 1');
        mysqli_stmt_bind_param($existingStmt, 'ii', $eventId, $studentPk);
        mysqli_stmt_execute($existingStmt);
        $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($existingStmt));
        $respond([
            'result' => 'already_recorded',
            'message' => 'ALREADY RECORDED',
            'student' => $student,
            'event_name' => $event['event_name'],
            'time_in' => $existing['time_in'] ?? null,
            'status' => $existing['status'] ?? null,
        ]);
    }
}

$events = mysqli_query($conn, 'SELECT id, event_name, event_date, start_time, end_time, late_time, status FROM events ORDER BY event_date DESC, start_time DESC, id DESC');
$selectedEventId = filter_var($_GET['event_id'] ?? null, FILTER_VALIDATE_INT);
$selectedEvent = null;
if ($selectedEventId && $selectedEventId > 0) {
    $selectedStmt = mysqli_prepare($conn, 'SELECT * FROM events WHERE id = ? LIMIT 1');
    mysqli_stmt_bind_param($selectedStmt, 'i', $selectedEventId);
    mysqli_stmt_execute($selectedStmt);
    $selectedEvent = mysqli_fetch_assoc(mysqli_stmt_get_result($selectedStmt));
}
if (!$selectedEvent && mysqli_num_rows($events) > 0) {
    mysqli_data_seek($events, 0);
    $selectedEvent = mysqli_fetch_assoc($events);
    $selectedEventId = (int) $selectedEvent['id'];
    mysqli_data_seek($events, 0);
}

$recent = null;
if ($selectedEvent) {
    $recentStmt = mysqli_prepare($conn, 'SELECT student.full_name, student.student_id, attendance.time_in, attendance.status FROM event_attendance attendance JOIN students student ON student.id = attendance.student_id WHERE attendance.event_id = ? ORDER BY attendance.time_in DESC LIMIT 15');
    mysqli_stmt_bind_param($recentStmt, 'i', $selectedEventId);
    mysqli_stmt_execute($recentStmt);
    $recent = mysqli_stmt_get_result($recentStmt);
}

include __DIR__ . '/../includes/header.php';
?>
<div class="row g-4">
    <div class="col-lg-7">
        <div class="card p-4 h-100">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <h4 class="fw-bold mb-0">Admin QR Scanner</h4>
                <div class="d-flex gap-2">
                    <button class="btn btn-primary" type="button" id="start-camera" <?= $selectedEvent ? '' : 'disabled'; ?>>Start Camera</button>
                    <button class="btn btn-outline-secondary" type="button" id="stop-camera" disabled>Stop Camera</button>
                </div>
            </div>
            <label class="form-label" for="event-select">Select Event</label>
            <select class="form-select mb-3" id="event-select">
                <?php if (!$selectedEvent): ?><option value="">No events available</option><?php endif; ?>
                <?php while ($eventOption = mysqli_fetch_assoc($events)): ?>
                    <option value="<?= (int) $eventOption['id']; ?>" <?= (int) $eventOption['id'] === (int) $selectedEventId ? 'selected' : ''; ?>><?= htmlspecialchars($eventOption['event_name'] . ' · ' . $eventOption['event_date'], ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endwhile; ?>
            </select>
            <div class="alert alert-light border" id="event-information">
                <?php if ($selectedEvent): ?>
                    <strong><?= htmlspecialchars($selectedEvent['event_name'], ENT_QUOTES, 'UTF-8'); ?></strong><br>
                    <?= htmlspecialchars($selectedEvent['event_date'] . ' · ' . substr($selectedEvent['start_time'], 0, 5) . '–' . substr($selectedEvent['end_time'], 0, 5) . ' · Late after ' . substr($selectedEvent['late_time'], 0, 5), ENT_QUOTES, 'UTF-8'); ?>
                    <span class="badge bg-<?= $selectedEvent['status'] === 'active' ? 'success' : 'secondary'; ?> ms-2"><?= htmlspecialchars(ucfirst($selectedEvent['status']), ENT_QUOTES, 'UTF-8'); ?></span>
                <?php else: ?>
                    Create an event before starting the scanner.
                <?php endif; ?>
            </div>
            <div id="qr-reader" class="border rounded-3 overflow-hidden" style="min-height: 340px;"></div>
            <div class="small text-muted mt-2">Camera access requires browser permission and HTTPS, or localhost. On a phone over LAN, use HTTPS.</div>
            <div class="mt-4">
                <h5 class="fw-bold">Scan Result</h5>
                <div id="scan-result" class="alert alert-secondary mb-0" aria-live="polite">Start the camera and scan a student's event QR code.</div>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card p-4 mb-4">
            <h5 class="fw-bold mb-3">Manual Check-in</h5>
            <form id="student-search-form" class="input-group">
                <input class="form-control" id="student-search" placeholder="Student ID, name, course, year, or section" minlength="2" required>
                <button class="btn btn-outline-primary" type="submit">Search</button>
            </form>
            <div id="manual-search-message" class="small text-muted mt-2" aria-live="polite"></div>
            <div id="manual-results" class="list-group mt-2"></div>
            <button type="button" class="btn btn-primary w-100 mt-3" id="manual-checkin" disabled>Manual Check-in</button>
        </div>
        <div class="card p-4">
            <h5 class="fw-bold mb-3">Recent Scans</h5>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Student</th><th>Time</th><th>Status</th></tr></thead>
                    <tbody id="recent-scans">
                        <?php if ($recent): while ($scan = mysqli_fetch_assoc($recent)): ?>
                            <tr><td><?= htmlspecialchars($scan['full_name'] . ' (' . $scan['student_id'] . ')', ENT_QUOTES, 'UTF-8'); ?></td><td><?= htmlspecialchars($scan['time_in'], ENT_QUOTES, 'UTF-8'); ?></td><td><?= htmlspecialchars($scan['status'], ENT_QUOTES, 'UTF-8'); ?></td></tr>
                        <?php endwhile; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
(() => {
    const eventSelect = document.getElementById('event-select');
    const startButton = document.getElementById('start-camera');
    const stopButton = document.getElementById('stop-camera');
    const resultPanel = document.getElementById('scan-result');
    const recentScans = document.getElementById('recent-scans');
    const csrfToken = <?= json_encode(csrfToken(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
    let reader = null;
    let cameraRunning = false;
    let requestInProgress = false;
    let scanCooldownUntil = 0;
    let selectedStudent = null;
    const recordedTokens = new Set();

    const escapeText = (value) => String(value ?? '');
    async function postAction(values) {
        const body = new URLSearchParams({csrf_token: csrfToken, event_id: eventSelect.value, ...values});
        const response = await fetch(window.location.pathname, {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body});
        const data = await response.json();
        if (!response.ok && data.result !== 'already_recorded') throw new Error(data.message || 'The request failed.');
        return data;
    }

    function showResult(data) {
        const success = data.result === 'success';
        const duplicate = data.result === 'already_recorded';
        resultPanel.className = 'alert ' + (success ? 'alert-success' : (duplicate ? 'alert-warning' : 'alert-danger'));
        resultPanel.replaceChildren();
        const heading = document.createElement('strong');
        heading.textContent = data.message;
        resultPanel.appendChild(heading);
        if (data.student) {
            const details = document.createElement('div');
            details.className = 'mt-2';
            for (const [label, value] of [
                ['Student Name', data.student.full_name],
                ['Student ID', data.student.student_id],
                ['Course', data.student.course_name],
                ['Year Level', data.student.year_name],
                ['Section', data.student.section_name],
                ['Event', data.event_name],
                ['Time In', data.time_in],
                ['Attendance Status', data.status],
            ]) {
                const line = document.createElement('div');
                const field = document.createElement('strong');
                field.textContent = `${label}: `;
                line.append(field, document.createTextNode(value || 'N/A'));
                details.appendChild(line);
            }
            resultPanel.appendChild(details);
            if (success && data.time_in) {
                const row = document.createElement('tr');
                for (const value of [`${data.student.full_name} (${data.student.student_id})`, data.time_in, data.status ?? '']) {
                    const cell = document.createElement('td');
                    cell.textContent = escapeText(value);
                    row.appendChild(cell);
                }
                recentScans.prepend(row);
                while (recentScans.children.length > 15) recentScans.lastElementChild.remove();
            }
        }
    }

    async function submitCheckin(values) {
        if (requestInProgress) return;
        requestInProgress = true;
        try {
            const data = await postAction(values);
            showResult(data);
            if (data.result === 'success') {
                scanCooldownUntil = Date.now() + 1500;
            }
            if (values.token && ['success', 'already_recorded'].includes(data.result)) {
                recordedTokens.add(values.token);
            }
        } catch (error) {
            resultPanel.className = 'alert alert-danger';
            resultPanel.textContent = error.message;
        } finally {
            requestInProgress = false;
        }
    }

    startButton.addEventListener('click', async () => {
        if (!eventSelect.value) return;
        if (typeof Html5Qrcode === 'undefined') {
            resultPanel.className = 'alert alert-danger';
            resultPanel.textContent = 'QR scanner library could not be loaded. Check the network connection and reload.';
            return;
        }
        try {
            reader = new Html5Qrcode('qr-reader');
            await reader.start({facingMode: 'environment'}, {fps: 10, qrbox: {width: 260, height: 260}}, (decodedText) => {
                const token = decodedText.trim();
                if (!requestInProgress && Date.now() >= scanCooldownUntil && !recordedTokens.has(token)) submitCheckin({action: 'scan', token});
            }, () => {});
            cameraRunning = true;
            startButton.disabled = true;
            stopButton.disabled = false;
        } catch (error) {
            resultPanel.className = 'alert alert-danger';
            resultPanel.textContent = 'Camera could not start. Allow camera access and use HTTPS or localhost.';
        }
    });

    stopButton.addEventListener('click', async () => {
        if (!cameraRunning || !reader) return;
        try {
            await reader.stop();
            cameraRunning = false;
            startButton.disabled = false;
            stopButton.disabled = true;
        } catch (error) {
            resultPanel.className = 'alert alert-danger';
            resultPanel.textContent = 'Camera could not be stopped cleanly. Reload the page if it remains active.';
        }
    });

    eventSelect.addEventListener('change', () => {
        const url = new URL(window.location.href);
        url.searchParams.set('event_id', eventSelect.value);
        window.location.assign(url);
    });

    document.getElementById('student-search-form').addEventListener('submit', async (event) => {
        event.preventDefault();
        const searchMessage = document.getElementById('manual-search-message');
        const results = document.getElementById('manual-results');
        const body = new URLSearchParams({csrf_token: csrfToken, action: 'search_students', search: document.getElementById('student-search').value});
        try {
            const response = await fetch(window.location.pathname, {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body});
            const data = await response.json();
            results.replaceChildren();
            selectedStudent = null;
            document.getElementById('manual-checkin').disabled = true;
            for (const student of data.students) {
                const option = document.createElement('button');
                option.type = 'button';
                option.className = 'list-group-item list-group-item-action';
                option.textContent = `${student.full_name} · ${student.student_id} · ${student.course_name ?? ''} · ${student.year_name ?? ''} · ${student.section_name ?? ''}`;
                option.addEventListener('click', () => {
                    selectedStudent = student;
                    results.querySelectorAll('.active').forEach((item) => item.classList.remove('active'));
                    option.classList.add('active');
                    document.getElementById('manual-checkin').disabled = false;
                });
                results.appendChild(option);
            }
            searchMessage.textContent = data.students.length ? `${data.students.length} student(s) found. Select one to check in.` : 'No matching students found.';
        } catch (error) {
            searchMessage.textContent = error.message;
        }
    });

    document.getElementById('manual-checkin').addEventListener('click', () => {
        if (selectedStudent) submitCheckin({action: 'manual_checkin', student_pk: selectedStudent.id});
    });
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
