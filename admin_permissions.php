
<?php

require __DIR__ . '/app.php';
require_admin();

/*
|--------------------------------------------------------------------------
| CONFIGURATION
|--------------------------------------------------------------------------
*/

$perPage = 50;

$page = max(1, (int)($_GET['page'] ?? 1));

$offset = ($page - 1) * $perPage;

$message = '';

/*
|--------------------------------------------------------------------------
| HANDLE APPROVE / REJECT
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrf_check();

    $requestId = filter_input(
        INPUT_POST,
        'request_id',
        FILTER_VALIDATE_INT
    );

    $action = $_POST['action'] ?? '';

    if (!$requestId || !in_array($action, ['approve', 'reject'], true)) {

        flash('Invalid permission request.', 'danger');

        redirect('admin_permissions.php');
    }

    try {

        $pdo->beginTransaction();

        /*
        |--------------------------------------------------------------------------
        | LOCK PERMISSION REQUEST
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare("
            SELECT
                id,
                session_id,
                student_id,
                reason,
                status,
                request_type
            FROM permission_requests
            WHERE id = ?
            FOR UPDATE
        ");

        $stmt->execute([$requestId]);

        $request = $stmt->fetch();

        if (!$request) {

            throw new RuntimeException(
                'Permission request not found.'
            );
        }

        if ($request['status'] !== 'pending') {

            throw new RuntimeException(
                'This request has already been reviewed.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | APPROVE REQUEST
        |--------------------------------------------------------------------------
        */

        if ($action === 'approve') {

            /*
             * Check existing attendance record.
             */

            $attendanceStmt = $pdo->prepare("
                SELECT id
                FROM attendance
                WHERE session_id = ?
                AND student_id = ?
                LIMIT 1
                FOR UPDATE
            ");

            $attendanceStmt->execute([
                $request['session_id'],
                $request['student_id']
            ]);

            $attendance = $attendanceStmt->fetch();


            /*
             * If attendance exists, update it.
             */

            if ($attendance) {

                $updateAttendance = $pdo->prepare("
                    UPDATE attendance
                    SET
                        status = 'permission',
                        note = ?,
                        source = 'admin',
                        updated_at = NOW()
                    WHERE id = ?
                ");

                $updateAttendance->execute([
                    $request['reason'],
                    $attendance['id']
                ]);

            } else {

                /*
                 * If no attendance record exists, create one.
                 */

                $insertAttendance = $pdo->prepare("
                    INSERT INTO attendance (
                        session_id,
                        student_id,
                        status,
                        checked_in_at,
                        note,
                        source,
                        created_at,
                        updated_at
                    )
                    VALUES (
                        ?,
                        ?,
                        'permission',
                        NULL,
                        ?,
                        'admin',
                        NOW(),
                        NOW()
                    )
                ");

                $insertAttendance->execute([
                    $request['session_id'],
                    $request['student_id'],
                    $request['reason']
                ]);
            }


            /*
             * Mark request as approved.
             */

            $reviewStmt = $pdo->prepare("
                UPDATE permission_requests
                SET
                    status = 'approved',
                    reviewed_by = ?,
                    reviewed_at = NOW()
                WHERE id = ?
            ");

            $reviewStmt->execute([
                user()['id'],
                $requestId
            ]);

            $pdo->commit();

            flash(
                'Permission request approved successfully.',
                'success'
            );

        }


        /*
        |--------------------------------------------------------------------------
        | REJECT REQUEST
        |--------------------------------------------------------------------------
        */

        elseif ($action === 'reject') {

            $reviewStmt = $pdo->prepare("
                UPDATE permission_requests
                SET
                    status = 'rejected',
                    reviewed_by = ?,
                    reviewed_at = NOW()
                WHERE id = ?
            ");

            $reviewStmt->execute([
                user()['id'],
                $requestId
            ]);

            $pdo->commit();

            flash(
                'Permission request rejected.',
                'warning'
            );
        }

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log(
            'Permission review error: ' . $e->getMessage()
        );

        flash(
            'Unable to process this request. Please check the database and try again.',
            'danger'
        );
    }

    redirect('admin_permissions.php?page=' . $page);
}


/*
|--------------------------------------------------------------------------
| GET SUMMARY COUNTS
|--------------------------------------------------------------------------
*/

$countStmt = $pdo->query("
    SELECT
        COUNT(*) AS total,
        COALESCE(SUM(status = 'pending'), 0) AS pending,
        COALESCE(SUM(status = 'approved'), 0) AS approved,
        COALESCE(SUM(status = 'rejected'), 0) AS rejected
    FROM permission_requests
");

$counts = $countStmt->fetch();


/*
|--------------------------------------------------------------------------
| GET TOTAL PAGES
|--------------------------------------------------------------------------
*/

$totalRecords = (int)($counts['total'] ?? 0);

$totalPages = max(
    1,
    (int)ceil($totalRecords / $perPage)
);

$page = min($page, $totalPages);

$offset = ($page - 1) * $perPage;


/*
|--------------------------------------------------------------------------
| GET PERMISSION REQUESTS
|--------------------------------------------------------------------------
|
| IMPORTANT:
| Use created_at, not requested_at.
|
*/

$listStmt = $pdo->prepare("
    SELECT
        pr.id,
        pr.session_id,
        pr.student_id,
        pr.reason,
        pr.status,
        pr.request_type,
        pr.reviewed_by,
        pr.reviewed_at,
        pr.created_at,

        u.full_name,
        u.student_code,

        s.title AS session_title,
        s.class_name

    FROM permission_requests pr

    LEFT JOIN users u
        ON u.id = pr.student_id

    LEFT JOIN attendance_sessions s
        ON s.id = pr.session_id

    ORDER BY
        CASE
            WHEN pr.status = 'pending' THEN 0
            ELSE 1
        END,
        pr.created_at DESC

    LIMIT :limit OFFSET :offset
");

$listStmt->bindValue(
    ':limit',
    $perPage,
    PDO::PARAM_INT
);

$listStmt->bindValue(
    ':offset',
    $offset,
    PDO::PARAM_INT
);

$listStmt->execute();

$requests = $listStmt->fetchAll();


/*
|--------------------------------------------------------------------------
| PAGE HEADER
|--------------------------------------------------------------------------
*/

page_header('Permission Requests');

?>

<style>
.permission-stat {
    border: 1px solid #e8edf3;
    border-radius: 16px;
    padding: 20px;
    background: #fff;
    height: 100%;
}

.permission-stat .stat-icon {
    width: 42px;
    height: 42px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 19px;
    margin-bottom: 15px;
}

.permission-stat h3 {
    font-size: 27px;
    font-weight: 700;
    margin-bottom: 4px;
}

.permission-stat p {
    color: #718096;
    font-size: 13px;
    margin: 0;
}

.permission-card {
    border: 1px solid #e8edf3;
    border-radius: 16px;
    background: #fff;
    overflow: hidden;
}

.permission-card-header {
    padding: 20px;
    border-bottom: 1px solid #edf0f4;
}

.permission-table th {
    background: #f8fafc;
    color: #64748b;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    white-space: nowrap;
    padding: 14px;
}

.permission-table td {
    padding: 15px 14px;
    vertical-align: middle;
    font-size: 13px;
}

.permission-reason {
    min-width: 180px;
    max-width: 280px;
    white-space: normal;
    line-height: 1.6;
}

.permission-actions {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
}

.permission-actions form {
    margin: 0;
}

.permission-user {
    font-weight: 600;
    color: #1e293b;
}

.permission-sub {
    font-size: 11px;
    color: #94a3b8;
    margin-top: 3px;
}

.permission-empty {
    padding: 55px 20px;
    text-align: center;
    color: #94a3b8;
}

.permission-empty i {
    font-size: 40px;
    display: block;
    margin-bottom: 12px;
}

@media (max-width: 768px) {
    .permission-stat {
        padding: 15px;
    }

    .permission-stat h3 {
        font-size: 23px;
    }
}
</style>


<div class="container-fluid px-0">

    <!-- PAGE TITLE -->

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">

        <div>
            <h4 class="fw-bold mb-1">
                Permission Requests
            </h4>

            <p class="text-muted small mb-0">
                Review and manage student permission requests.
            </p>
        </div>

        <div class="text-muted small">
            <i class="bi bi-calendar3 me-1"></i>
            <?= e(date('d M Y')) ?>
        </div>

    </div>


    <!-- STATISTICS -->

    <div class="row g-3 mb-4">

        <div class="col-6 col-xl-3">
            <div class="permission-stat">

                <div class="stat-icon bg-primary-subtle text-primary">
                    <i class="bi bi-collection"></i>
                </div>

                <h3>
                    <?= number_format((int)$counts['total']) ?>
                </h3>

                <p>Total Requests</p>

            </div>
        </div>


        <div class="col-6 col-xl-3">
            <div class="permission-stat">

                <div class="stat-icon bg-warning-subtle text-warning">
                    <i class="bi bi-hourglass-split"></i>
                </div>

                <h3>
                    <?= number_format((int)$counts['pending']) ?>
                </h3>

                <p>Pending</p>

            </div>
        </div>


        <div class="col-6 col-xl-3">
            <div class="permission-stat">

                <div class="stat-icon bg-success-subtle text-success">
                    <i class="bi bi-check-circle"></i>
                </div>

                <h3>
                    <?= number_format((int)$counts['approved']) ?>
                </h3>

                <p>Approved</p>

            </div>
        </div>


        <div class="col-6 col-xl-3">
            <div class="permission-stat">

                <div class="stat-icon bg-danger-subtle text-danger">
                    <i class="bi bi-x-circle"></i>
                </div>

                <h3>
                    <?= number_format((int)$counts['rejected']) ?>
                </h3>

                <p>Rejected</p>

            </div>
        </div>

    </div>


    <!-- REQUEST TABLE -->

    <div class="permission-card">

        <div class="permission-card-header d-flex justify-content-between align-items-center flex-wrap gap-2">

            <div>
                <h6 class="fw-bold mb-1">
                    All Permission Requests
                </h6>

                <div class="text-muted small">
                    Showing
                    <?= count($requests) ?>
                    of
                    <?= number_format($totalRecords) ?>
                    requests
                </div>
            </div>

            <span class="badge bg-warning-subtle text-warning px-3 py-2">
                <?= number_format((int)$counts['pending']) ?>
                Pending
            </span>

        </div>


        <div class="table-responsive">

            <table class="table permission-table table-hover mb-0">

                <thead>
                    <tr>
                        <th>#</th>
                        <th>Student</th>
                        <th>Session</th>
                        <th>Request Type</th>
                        <th>Reason</th>
                        <th>Submitted</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>


                <tbody>

                <?php if (!$requests): ?>

                    <tr>
                        <td colspan="8">

                            <div class="permission-empty">

                                <i class="bi bi-envelope-open"></i>

                                <h6 class="fw-semibold text-dark">
                                    No permission requests found
                                </h6>

                                <p class="small mb-0">
                                    Student permission requests will appear here.
                                </p>

                            </div>

                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($requests as $index => $r): ?>

                        <?php

                        $status = $r['status'] ?? 'pending';

                        $statusClasses = [
                            'pending' => 'bg-warning-subtle text-warning',
                            'approved' => 'bg-success-subtle text-success',
                            'rejected' => 'bg-danger-subtle text-danger'
                        ];

                        $statusClass = $statusClasses[$status]
                            ?? 'bg-secondary-subtle text-secondary';

                        $requestType = $r['request_type'] ?? 'student';

                        $requestTypeLabel = $requestType === 'admin'
                            ? 'Admin'
                            : 'Student';

                        $studentName = $r['full_name']
                            ?? 'Unknown Student';

                        $studentCode = $r['student_code']
                            ?? 'N/A';

                        $sessionTitle = $r['session_title']
                            ?? 'Unknown Session';

                        $className = $r['class_name']
                            ?? '';

                        $createdAt = !empty($r['created_at'])
                            ? date(
                                'd M Y, h:i A',
                                strtotime($r['created_at'])
                            )
                            : 'N/A';

                        ?>

                        <tr>

                            <td>
                                <?= $offset + $index + 1 ?>
                            </td>


                            <!-- STUDENT -->

                            <td>

                                <div class="permission-user">
                                    <?= e($studentName) ?>
                                </div>

                                <div class="permission-sub">
                                    ID: <?= e($studentCode) ?>
                                </div>

                            </td>


                            <!-- SESSION -->

                            <td>

                                <div class="fw-semibold">
                                    <?= e($sessionTitle) ?>
                                </div>

                                <div class="permission-sub">
                                    <?= e($className) ?>
                                </div>

                            </td>


                            <!-- REQUEST TYPE -->

                            <td>
                                <span class="badge bg-info-subtle text-info">
                                    <?= e($requestTypeLabel) ?>
                                </span>
                            </td>


                            <!-- REASON -->

                            <td>
                                <div class="permission-reason">
                                    <?= nl2br(e($r['reason'] ?? '')) ?>
                                </div>
                            </td>


                            <!-- CREATED AT -->

                            <td class="text-nowrap">
                                <?= e($createdAt) ?>
                            </td>


                            <!-- STATUS -->

                            <td>
                                <span class="badge rounded-pill px-3 py-2 <?= e($statusClass) ?>">
                                    <?= e(ucfirst($status)) ?>
                                </span>
                            </td>


                            <!-- ACTIONS -->

                            <td>

                                <?php if ($status === 'pending'): ?>

                                    <div class="permission-actions">

                                        <!-- APPROVE -->

                                        <form method="POST">

                                            <input
                                                type="hidden"
                                                name="csrf"
                                                value="<?= e(csrf_token()) ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="request_id"
                                                value="<?= (int)$r['id'] ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="approve"
                                            >

                                            <button
                                                type="submit"
                                                class="btn btn-sm btn-success"
                                                onclick="return confirm('Are you sure you want to approve this permission request?')"
                                            >
                                                <i class="bi bi-check-lg"></i>
                                                Approve
                                            </button>

                                        </form>


                                        <!-- REJECT -->

                                        <form method="POST">

                                            <input
                                                type="hidden"
                                                name="csrf"
                                                value="<?= e(csrf_token()) ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="request_id"
                                                value="<?= (int)$r['id'] ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="reject"
                                            >

                                            <button
                                                type="submit"
                                                class="btn btn-sm btn-outline-danger"
                                                onclick="return confirm('Are you sure you want to reject this permission request?')"
                                            >
                                                <i class="bi bi-x-lg"></i>
                                                Reject
                                            </button>

                                        </form>

                                    </div>

                                <?php else: ?>

                                    <span class="text-muted small">
                                        <i class="bi bi-check-circle"></i>
                                        Reviewed
                                    </span>

                                    <?php if (!empty($r['reviewed_at'])): ?>

                                        <div class="permission-sub">
                                            <?= e(date(
                                                'd M Y, h:i A',
                                                strtotime($r['reviewed_at'])
                                            )) ?>
                                        </div>

                                    <?php endif; ?>

                                <?php endif; ?>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>


        <!-- PAGINATION -->

        <?php if ($totalPages > 1): ?>

            <div class="p-3 border-top d-flex justify-content-between align-items-center flex-wrap gap-2">

                <div class="text-muted small">
                    Page <?= $page ?>
                    of <?= $totalPages ?>
                </div>

                <nav aria-label="Permission pagination">

                    <ul class="pagination pagination-sm mb-0">

                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">

                            <a
                                class="page-link"
                                href="?page=<?= max(1, $page - 1) ?>"
                            >
                                Previous
                            </a>

                        </li>


                        <?php

                        $startPage = max(1, $page - 2);

                        $endPage = min(
                            $totalPages,
                            $page + 2
                        );

                        for (
                            $i = $startPage;
                            $i <= $endPage;
                            $i++
                        ):

                        ?>

                            <li class="page-item <?= $i === $page ? 'active' : '' ?>">

                                <a
                                    class="page-link"
                                    href="?page=<?= $i ?>"
                                >
                                    <?= $i ?>
                                </a>

                            </li>

                        <?php endfor; ?>


                        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">

                            <a
                                class="page-link"
                                href="?page=<?= min($totalPages, $page + 1) ?>"
                            >
                                Next
                            </a>

                        </li>

                    </ul>

                </nav>

            </div>

        <?php endif; ?>

    </div>

</div>


<?php
page_footer();
?>
