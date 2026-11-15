<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole(['admin']);

$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = requestString($_POST, 'name');

    if (!csrfTokenIsValid()) {
        $message = 'Your form session expired. Reload the page and try again.';
        $messageType = 'danger';
    } elseif ($name === '') {
        $message = 'Year level is required.';
        $messageType = 'danger';
    } else {
        try {
            $stmt = mysqli_prepare($conn, 'INSERT INTO year_levels (name) VALUES (?)');
            mysqli_stmt_bind_param($stmt, 's', $name);
            mysqli_stmt_execute($stmt);
            $message = 'Year level added successfully.';
        } catch (mysqli_sql_exception $exception) {
            if ($exception->getCode() === 1062) {
                $message = 'That year level already exists.';
            } else {
                error_log('Year-level creation failed: ' . $exception->getMessage());
                $message = 'Year level could not be saved. Please try again.';
            }
            $messageType = 'danger';
        }
    }
}

$yearLevels = mysqli_query($conn, 'SELECT * FROM year_levels ORDER BY id');

include __DIR__ . '/../includes/header.php';
?>

<div class="card p-4 mb-4">
    <h4 class="fw-bold mb-3">Year Level Management</h4>
    <?php if ($message !== ''): ?>
        <div class="alert alert-<?= htmlspecialchars($messageType); ?>"><?= htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <form method="post" class="row g-3">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
        <div class="col-md-8">
            <label class="form-label">Year Level</label>
            <input type="text" class="form-control" name="name" placeholder="1st Year" required>
        </div>
        <div class="col-md-4 d-flex align-items-end">
            <button class="btn btn-primary w-100" type="submit">Add</button>
        </div>
    </form>
</div>

<div class="card p-4">
    <h5 class="fw-bold mb-3">Year Levels</h5>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>Name</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($year = mysqli_fetch_assoc($yearLevels)): ?>
                    <tr>
                        <td><?= htmlspecialchars($year['name']); ?></td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
