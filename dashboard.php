
<?php
require __DIR__ . '/app.php';
require_login();

$u = user();
$today = date('Y-m-d');
$now = date('Y-m-d H:i:s');

if ($u['role'] === 'admin') {

    $academicYear = '2026-2027';

    // Total active students
    $students = (int)$pdo->query("
        SELECT COUNT(*)
        FROM users
        WHERE role = 'student'
          AND is_active = 1
    ")->fetchColumn();

    // Open sessions
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM attendance_sessions
        WHERE status = 'open'
          AND starts_at <= ?
    ");
    $stmt->execute([$now]);
    $open = (int)$stmt->fetchColumn();

    // Present today
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT student_id)
        FROM attendance
        WHERE DATE(checked_in_at) = ?
          AND status IN ('present', 'late')
    ");
    $stmt->execute([$today]);
    $presentToday = (int)$stmt->fetchColumn();

    // Absent today
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT a.student_id)
        FROM attendance a
        JOIN attendance_sessions s ON s.id = a.session_id
        WHERE DATE(s.starts_at) = ?
          AND a.status = 'absent'
    ");
    $stmt->execute([$today]);
    $absentToday = (int)$stmt->fetchColumn();

    // Latest session
    $latestSession = $pdo->query("
        SELECT
            s.*,
            creator.full_name AS creator_name
        FROM attendance_sessions s
        LEFT JOIN users creator
            ON creator.id = s.created_by
        ORDER BY s.id DESC
        LIMIT 1
    ")->fetch();

    $present = 0;
    $late = 0;
    $absent = 0;
    $notChecked = 0;
    $checkedIn = 0;
    $attendanceRate = 0;

    if ($latestSession) {

        $stmt = $pdo->prepare("
            SELECT
                COUNT(CASE WHEN status = 'present' THEN 1 END) AS present_count,
                COUNT(CASE WHEN status = 'late' THEN 1 END) AS late_count,
                COUNT(CASE WHEN status = 'absent' THEN 1 END) AS absent_count
            FROM attendance
            WHERE session_id = ?
        ");

        $stmt->execute([$latestSession['id']]);
        $stats = $stmt->fetch();

        $present = (int)$stats['present_count'];
        $late = (int)$stats['late_count'];
        $absent = (int)$stats['absent_count'];

        $checkedIn = $present + $late;

        $notChecked = max(
            0,
            $students - $present - $late - $absent
        );

        $attendanceRate = $students > 0
            ? round(($checkedIn / $students) * 100, 1)
            : 0;
    }

    // Recent check-ins
    $recentAttendance = $pdo->query("
        SELECT
            a.id,
            a.status,
            a.checked_in_at,
            a.source,
            u.full_name,
            u.student_code,
            s.title,
            s.class_name,
            creator.full_name AS creator_name
        FROM attendance a
        JOIN users u ON u.id = a.student_id
        JOIN attendance_sessions s ON s.id = a.session_id
        LEFT JOIN users creator ON creator.id = s.created_by
        WHERE a.checked_in_at IS NOT NULL
        ORDER BY a.checked_in_at DESC, a.id DESC
        LIMIT 10
    ")->fetchAll();

    // Recent sessions
    $recentSessions = $pdo->query("
        SELECT
            s.*,
            creator.full_name AS creator
        FROM attendance_sessions s
        LEFT JOIN users creator ON creator.id = s.created_by
        ORDER BY s.id DESC
        LIMIT 6
    ")->fetchAll();

    // Notifications
    $unreadNotifications = (int)$pdo->query("
        SELECT COUNT(*)
        FROM attendance_notifications
        WHERE is_read = 0
    ")->fetchColumn();

    $notifications = $pdo->query("
        SELECT
            n.id,
            n.message,
            n.is_read,
            n.created_at,
            u.full_name,
            u.student_code,
            s.title AS session_title
        FROM attendance_notifications n
        JOIN users u ON u.id = n.student_id
        JOIN attendance_sessions s ON s.id = n.session_id
        ORDER BY n.id DESC
        LIMIT 8
    ")->fetchAll();

} else {

    // Student statistics
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM attendance
        WHERE student_id = ?
          AND status IN ('present', 'late')
    ");
    $stmt->execute([$u['id']]);
    $attended = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM attendance
        WHERE student_id = ?
          AND status = 'late'
    ");
    $stmt->execute([$u['id']]);
    $late = (int)$stmt->fetchColumn();

    // Student recent attendance
    $recent = $pdo->prepare("
        SELECT
            a.*,
            s.title,
            s.starts_at
        FROM attendance a
        JOIN attendance_sessions s ON s.id = a.session_id
        WHERE a.student_id = ?
        ORDER BY s.starts_at DESC
        LIMIT 6
    ");

    $recent->execute([$u['id']]);
    $recent = $recent->fetchAll();
}

page_header('Dashboard');
?>

<div class="welcome-row">

    <div>
        <h2>សូមស្មាគមន៍ <?= e($u['full_name']) ?> </h2>

        <p class="text-muted mb-0">
            <?= $u['role'] === 'admin'
                ? 'នេះគឺជាកាគ្រប់គ្រងទិដ្ឋន័យនៅក្នុងថ្នាក់ITD010A.'
                : 'នេះគឺជាកាគ្រប់គ្រងទិដ្ឋន័យនៅក្នុងថ្នាក់ITD010A.'
            ?>
        </p>
    </div>

    <?php if ($u['role'] === 'admin'): ?>
        <a class="btn btn-primary" href="sessions.php">
            <i class="bi bi-plus-lg"></i>
            New QR Session
        </a>
    <?php endif; ?>

</div>

<?php if ($u['role'] === 'admin'): ?>

<!-- Academic Year -->

<div class="panel mt-3 p-3">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <small class="text-muted">Academic Year</small>
            <h4 class="mb-0"><?= e($academicYear) ?></h4>
        </div>

        <span class="badge bg-primary">
            <i class="bi bi-calendar3"></i>
            <?= e($today) ?>
        </span>
    </div>
</div>

<!-- Statistics -->

<div class="row g-3 mt-2">

    <div class="col-md-3 col-sm-6">
        <div class="stat-card">
            <span class="stat-icon blue">
                <i class="bi bi-people"></i>
            </span>
            <div>
                <small>Total Students</small>
                <h3><?= $students ?></h3>
            </div>
        </div>
    </div>

    <div class="col-md-3 col-sm-6">
        <div class="stat-card">
            <span class="stat-icon green">
                <i class="bi bi-check-circle"></i>
            </span>
            <div>
                <small>Present Today</small>
                <h3><?= $presentToday ?></h3>
            </div>
        </div>
    </div>

    <div class="col-md-3 col-sm-6">
        <div class="stat-card">
            <span class="stat-icon red">
                <i class="bi bi-person-x"></i>
            </span>
            <div>
                <small>Absent Today</small>
                <h3><?= $absentToday ?></h3>
            </div>
        </div>
    </div>

    <div class="col-md-3 col-sm-6">
        <div class="stat-card">
            <span class="stat-icon orange">
                <i class="bi bi-qr-code"></i>
            </span>
            <div>
                <small>Open Sessions</small>
                <h3><?= $open ?></h3>
            </div>
        </div>
    </div>

</div>

<!-- Latest Session -->

<div class="panel mt-4 p-3">

    <div class="panel-head">
        <h5>
            <i class="bi bi-bar-chart-line me-2"></i>
            Latest Session Overview
        </h5>

        <span class="badge bg-success">
            <?= $attendanceRate ?>%
        </span>
    </div>

    <?php if ($latestSession): ?>

        <p class="text-muted small mb-1">
            Session:
            <strong><?= e($latestSession['title']) ?></strong>
            (<?= e($latestSession['class_name']) ?>)
        </p>

        <p class="text-muted small">
            Created by:
            <?= e($latestSession['creator_name'] ?? 'Unknown') ?>
        </p>

        <div class="progress mb-3" style="height:12px;">
            <div
                class="progress-bar bg-success"
                style="width: <?= $attendanceRate ?>%"
            ></div>
        </div>

        <div class="row g-2 text-center">

            <div class="col-6 col-md-3">
                <div class="border rounded p-2">
                    <h5 class="text-success mb-0"><?= $checkedIn ?></h5>
                    <small>Present + Late</small>
                </div>
            </div>

            <div class="col-6 col-md-3">
                <div class="border rounded p-2">
                    <h5 class="text-danger mb-0"><?= $absent ?></h5>
                    <small>Absent</small>
                </div>
            </div>

            <div class="col-6 col-md-3">
                <div class="border rounded p-2">
                    <h5 class="text-warning mb-0"><?= $notChecked ?></h5>
                    <small>Not checked</small>
                </div>
            </div>

            <div class="col-6 col-md-3">
                <div class="border rounded p-2">
                    <h5 class="text-primary mb-0"><?= $students ?></h5>
                    <small>Total Students</small>
                </div>
            </div>

        </div>

    <?php else: ?>

        <p class="text-muted text-center py-3">
            No attendance sessions created yet.
        </p>

    <?php endif; ?>

</div>

<!-- Notifications -->

<div class="panel mt-4">

    <div class="panel-head">
        <h5>
            <i class="bi bi-bell me-2"></i>
            Notifications

            <?php if ($unreadNotifications > 0): ?>
                <span class="badge bg-danger">
                    <?= $unreadNotifications ?>
                </span>
            <?php endif; ?>
        </h5>

        <a href="notifications.php">View all</a>
    </div>

    <div class="list-group list-group-flush">

        <?php foreach ($notifications as $n): ?>

            <div class="list-group-item px-3 py-3
                <?= !$n['is_read'] ? 'bg-light' : '' ?>">

                <div class="d-flex justify-content-between gap-2">

                    <div>
                        <strong>
                            <i class="bi bi-person-check text-success me-1"></i>
                            <?= e($n['full_name']) ?>
                        </strong>

                        <div class="small text-muted">
                            Student ID:
                            <?= e($n['student_code'] ?? '') ?>
                        </div>

                        <div class="small mt-1">
                            <?= e($n['message']) ?>
                        </div>

                        <div class="small text-muted">
                            Session:
                            <?= e($n['session_title']) ?>
                        </div>
                    </div>

                    <div class="text-end">
                        <?php if (!$n['is_read']): ?>
                            <span class="badge bg-primary">New</span>
                        <?php endif; ?>

                        <div class="small text-muted mt-2">
                            <?= e($n['created_at']) ?>
                        </div>
                    </div>

                </div>

            </div>

        <?php endforeach; ?>

        <?php if (!$notifications): ?>
            <div class="text-center text-muted py-4">
                <i class="bi bi-bell-slash fs-3"></i>
                <p class="mb-0 mt-2">No notifications yet.</p>
            </div>
        <?php endif; ?>

    </div>

</div>

<!-- Recent Check-ins -->

<div class="panel mt-4">

    <div class="panel-head">
        <h5>
            <i class="bi bi-activity me-2"></i>
            Recent Check-ins
        </h5>

        <a href="records.php">View all records</a>
    </div>

    <div class="table-responsive">

        <table class="table align-middle">

            <thead>
                <tr>
                    <th>Student</th>
                    <th>Student ID</th>
                    <th>Session</th>
                    <th>Check-in Time</th>
                    <th>Created By</th>
                    <th>Status</th>
                </tr>
            </thead>

            <tbody>

                <?php foreach ($recentAttendance as $r): ?>

                    <tr>
                        <td>
                            <strong><?= e($r['full_name']) ?></strong>
                        </td>

                        <td><?= e($r['student_code'] ?? '') ?></td>

                        <td><?= e($r['title']) ?></td>

                        <td><?= e($r['checked_in_at']) ?></td>

                        <td><?= e($r['creator_name'] ?? 'Unknown') ?></td>

                        <td>
                            <span class="status <?= e($r['status']) ?>">
                                <?= e(ucfirst($r['status'])) ?>
                            </span>
                        </td>
                    </tr>

                <?php endforeach; ?>

                <?php if (!$recentAttendance): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">
                            No check-ins yet.
                        </td>
                    </tr>
                <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>

<!-- Recent Sessions -->

<div class="panel mt-4">

    <div class="panel-head">
        <h5>
            <i class="bi bi-qr-code me-2"></i>
            Recent QR Sessions
        </h5>

        <a href="sessions.php">Manage Sessions</a>
    </div>

    <div class="table-responsive">

        <table class="table align-middle">

            <thead>
                <tr>
                    <th>Session</th>
                    <th>Class</th>
                    <th>Starts</th>
                    <th>Created By</th>
                    <th>Status</th>
                </tr>
            </thead>

            <tbody>

                <?php foreach ($recentSessions as $r): ?>

                    <tr>
                        <td><?= e($r['title']) ?></td>
                        <td><?= e($r['class_name']) ?></td>
                        <td><?= e($r['starts_at']) ?></td>
                        <td><?= e($r['creator'] ?? 'Unknown') ?></td>

                        <td>
                            <span class="status <?= e($r['status']) ?>">
                                <?= e($r['status']) ?>
                            </span>
                        </td>
                    </tr>

                <?php endforeach; ?>

                <?php if (!$recentSessions): ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted py-4">
                            No sessions yet.
                        </td>
                    </tr>
                <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>

<?php else: ?>

<!-- Student Dashboard -->

<div class="row g-3 mt-2">

    <div class="col-md-6">
        <div class="stat-card">
            <span class="stat-icon green">
                <i class="bi bi-check-circle"></i>
            </span>
            <div>
                <small>Present / Late Records</small>
                <h3><?= $attended ?></h3>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="stat-card">
            <span class="stat-icon orange">
                <i class="bi bi-clock"></i>
            </span>
            <div>
                <small>Late Records</small>
                <h3><?= $late ?></h3>
            </div>
        </div>
    </div>

</div>

<div class="panel mt-4">

    <div class="panel-head">
        <h5>Recent Attendance</h5>
        <a href="my_attendance.php">View All</a>
    </div>

    <div class="table-responsive">

        <table class="table">

            <thead>
                <tr>
                    <th>Session</th>
                    <th>Date</th>
                    <th>Status</th>
                </tr>
            </thead>

            <tbody>

                <?php foreach ($recent as $r): ?>

                    <tr>
                        <td><?= e($r['title']) ?></td>
                        <td><?= e($r['starts_at']) ?></td>

                        <td>
                            <span class="status <?= e($r['status']) ?>">
                                <?= e($r['status']) ?>
                            </span>
                        </td>
                    </tr>

                <?php endforeach; ?>

                <?php if (!$recent): ?>
                    <tr>
                        <td colspan="3" class="text-center text-muted py-4">
                            No attendance records yet.
                        </td>
                    </tr>
                <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>

<?php endif; ?>

<?php page_footer(); ?>

<?php if ($u['role'] === 'admin'): ?>
<script>
setTimeout(function () {
    window.location.reload();
}, 15000);
</script>
<?php endif; ?>
