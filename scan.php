<?php
require __DIR__ . '/app.php';

$token = trim($_GET['t'] ?? $_POST['t'] ?? '');

$message = '';
$type = 'danger';

$session = null;
$studentName = '';
$studentCode = '';
$attendanceStatus = '';
$canCheckIn = false;

/*
|--------------------------------------------------------------------------
| 1. VALIDATE QR TOKEN
|--------------------------------------------------------------------------
*/

if (!preg_match('/^[a-f0-9]{64}$/', $token)) {

    http_response_code(400);
    $message = 'Invalid QR code.';

} else {

    $stmt = $pdo->prepare("
        SELECT *
        FROM attendance_sessions
        WHERE token_hash = ?
        LIMIT 1
    ");

    $stmt->execute([hash('sha256', $token)]);
    $session = $stmt->fetch();

    if (!$session) {
        $message = 'This QR code is invalid or does not exist.';

    } elseif ($session['status'] !== 'open') {
        $message = 'This attendance session is closed.';

    } elseif (strtotime($session['starts_at']) > time()) {
        $message = 'Attendance has not started yet. Please wait until the start time.';
    }
}

/*
|--------------------------------------------------------------------------
| 2. STUDENT CHECK-IN
|--------------------------------------------------------------------------
*/

if (
    $session &&
    $session['status'] === 'open' &&
    !$message &&
    $_SERVER['REQUEST_METHOD'] === 'POST'
) {

    csrf_check();

    $studentCode = trim($_POST['student_code'] ?? '');

    if ($studentCode === '') {

        $message = 'Please enter your Student ID.';

    } else {

        $stmt = $pdo->prepare("
            SELECT id, full_name, student_code
            FROM users
            WHERE student_code = ?
              AND role = 'student'
              AND is_active = 1
            LIMIT 1
        ");

        $stmt->execute([$studentCode]);
        $student = $stmt->fetch();

        if (!$student) {

            $message = 'Student ID not found or account is disabled.';

        } else {

            $studentName = $student['full_name'];

            try {

                $pdo->beginTransaction();

                /*
                |--------------------------------------------------------------------------
                | Lock Session
                |--------------------------------------------------------------------------
                */

                $stmt = $pdo->prepare("
                    SELECT id, status, starts_at
                    FROM attendance_sessions
                    WHERE id = ?
                    FOR UPDATE
                ");

                $stmt->execute([(int)$session['id']]);
                $lockedSession = $stmt->fetch();

                $currentTime = time();

                if (
                    !$lockedSession ||
                    $lockedSession['status'] !== 'open'
                ) {

                    $pdo->commit();
                    $message = 'This attendance session is closed.';

                } elseif (
                    strtotime($lockedSession['starts_at']) > $currentTime
                ) {

                    $pdo->commit();
                    $message = 'Attendance has not started yet.';

                } else {

                    /*
                    |--------------------------------------------------------------------------
                    | Check Existing Attendance
                    |--------------------------------------------------------------------------
                    */

                    $stmt = $pdo->prepare("
                        SELECT id, status
                        FROM attendance
                        WHERE session_id = ?
                          AND student_id = ?
                        LIMIT 1
                    ");

                    $stmt->execute([
                        $session['id'],
                        $student['id']
                    ]);

                    $existing = $stmt->fetch();

                    if ($existing) {

                        $pdo->commit();

                        $message = 'You have already submitted attendance for this session.';
                        $type = 'warning';
                        $attendanceStatus = $existing['status'];

                    } else {

                        /*
                        |--------------------------------------------------------------------------
                        | Insert Attendance
                        |--------------------------------------------------------------------------
                        */

                        $checkInTime = date(
                            'Y-m-d H:i:s',
                            $currentTime
                        );

                        $stmt = $pdo->prepare("
                            INSERT INTO attendance
                            (
                                session_id,
                                student_id,
                                status,
                                checked_in_at,
                                source
                            )
                            VALUES (?, ?, 'present', ?, 'qr')
                        ");

                        $stmt->execute([
                            (int)$session['id'],
                            (int)$student['id'],
                            $checkInTime
                        ]);

                        $attendanceId = (int)$pdo->lastInsertId();

                        /*
                        |--------------------------------------------------------------------------
                        | Insert Notification
                        |--------------------------------------------------------------------------
                        */

                        $notificationMessage =
                            $student['full_name'] .
                            ' (' . $student['student_code'] .
                            ') checked in successfully.';

                        $notificationStmt = $pdo->prepare("
                            INSERT INTO attendance_notifications
                            (
                                attendance_id,
                                session_id,
                                student_id,
                                message,
                                is_read
                            )
                            VALUES (?, ?, ?, ?, 0)
                        ");

                        $notificationStmt->execute([
                            $attendanceId,
                            (int)$session['id'],
                            (int)$student['id'],
                            $notificationMessage
                        ]);

                        /*
                        |--------------------------------------------------------------------------
                        | Commit Both Records
                        |--------------------------------------------------------------------------
                        */

                        $pdo->commit();

                        $message = 'Attendance recorded successfully!';
                        $type = 'success';
                        $attendanceStatus = 'present';
                        $studentCode = $student['student_code'];
                    }
                }

            } catch (Throwable $e) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log(
                    'QR check-in error: ' . $e->getMessage()
                );

                if (
                    $e instanceof PDOException &&
                    $e->getCode() === '23000'
                ) {

                    $message = 'You have already checked in for this session.';
                    $type = 'warning';

                } else {

                    $message = 'Could not record attendance. Please contact your administrator.';
                }
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| 3. CHECK-IN AVAILABILITY
|--------------------------------------------------------------------------
*/

if ($session) {

    $startTime = strtotime($session['starts_at']);

    $canCheckIn = (
        $session['status'] === 'open' &&
        $startTime !== false &&
        time() >= $startTime
    );
}

/*
|--------------------------------------------------------------------------
| 4. DISPLAY PAGE
|--------------------------------------------------------------------------
*/

page_header('QR Attendance');
?>

<div class="checkin-card">

    <div class="login-logo">
        <i class="bi bi-qr-code-scan"></i>
    </div>

    <h2>QR Attendance</h2>

    <?php if ($session): ?>

        <p class="text-muted">
            <?= e($session['title']) ?>
        </p>

        <p>
            <strong>Class:</strong>
            <?= e($session['class_name']) ?>
        </p>

    <?php endif; ?>

    <?php if ($message): ?>

        <div class="alert alert-<?= e($type) ?>">
            <?= e($message) ?>
        </div>

    <?php endif; ?>

    <?php if ($type === 'success'): ?>

        <div class="text-center mt-3">

            <i
                class="bi bi-check-circle-fill text-success"
                style="font-size:55px"
            ></i>

            <h4 class="mt-2">
                Attendance Successful!
            </h4>

            <p>
                Student:
                <strong><?= e($studentName) ?></strong>
            </p>

            <p>
                Student ID:
                <strong><?= e($studentCode) ?></strong>
            </p>

            <p>
                Status:
                <strong class="text-success">Present</strong>
            </p>

            <p class="text-muted">
                Your attendance has been recorded successfully.
            </p>

        </div>

    <?php elseif ($type === 'warning'): ?>

        <p class="text-center">
            <?= e($studentName) ?>
        </p>

        <?php if ($attendanceStatus): ?>

            <p class="text-center">
                Attendance status:
                <strong><?= e(ucfirst($attendanceStatus)) ?></strong>
            </p>

        <?php endif; ?>

    <?php elseif ($canCheckIn): ?>

        <form method="post">

            <input
                type="hidden"
                name="csrf"
                value="<?= e(csrf_token()) ?>"
            >

            <input
                type="hidden"
                name="t"
                value="<?= e($token) ?>"
            >

            <div class="mb-3 text-start">

                <label class="form-label">
                    Student ID
                </label>

                <input
                    type="text"
                    name="student_code"
                    class="form-control form-control-lg"
                    placeholder="Enter your Student ID"
                    value="<?= e($studentCode) ?>"
                    required
                    autocomplete="off"
                >

            </div>

            <button
                type="submit"
                class="btn btn-primary btn-lg w-100"
            >
                <i class="bi bi-check-circle me-2"></i>
                Confirm Attendance
            </button>

        </form>

        <p class="text-muted small mt-3">
            Enter your Student ID to record attendance.
            No student login is required.
        </p>

    <?php endif; ?>

    <hr>

    <div class="text-start small bg-light rounded p-3 mt-3">

        <h6>
            <i class="bi bi-clock-history me-2"></i>
            Session Information
        </h6>

        <p class="mb-1">
            <strong>Current Time:</strong>
            <?= e(date('Y-m-d H:i:s')) ?>
        </p>

        <?php if ($session): ?>

            <p class="mb-1">
                <strong>Session Status:</strong>
                <?= e(ucfirst($session['status'])) ?>
            </p>

            <p class="mb-1">
                <strong>Start Time:</strong>
                <?= e($session['starts_at']) ?>
            </p>

            <p class="mb-0">
                <strong>Attendance Rule:</strong>
                Present Only
            </p>

        <?php endif; ?>

    </div>

</div>

<?php page_footer(); ?>
