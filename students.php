
<?php
require __DIR__ . '/app.php';
require_admin();

// ======================================
// ADD NEW STUDENT
// ======================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $code = trim($_POST['student_code'] ?? '');
    $name = trim($_POST['full_name'] ?? '');
    $latin = trim($_POST['latin_name'] ?? '');
    $gender = $_POST['gender'] ?? '';
    $phone = trim($_POST['phone'] ?? '');
    $dob = trim($_POST['date_of_birth'] ?? '');
    $email = trim(strtolower($_POST['email'] ?? ''));
    $pass = $_POST['password'] ?? '';

    if ($code === '' || $name === '') {
        flash('Student ID and Khmer name are required.', 'danger');
        redirect('students.php');
    }

    if (!in_array($gender, ['ប្រុស', 'ស្រី'], true)) {
        flash('Please select student gender.', 'danger');
        redirect('students.php');
    }

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('Invalid email address.', 'danger');
        redirect('students.php');
    }

    if ($pass !== '' && strlen($pass) < 10) {
        flash('Password must be at least 10 characters.', 'danger');
        redirect('students.php');
    }

    if ($dob !== '') {
        $date = DateTime::createFromFormat('!Y-m-d', $dob);
        if (!$date || $date->format('Y-m-d') !== $dob) {
            flash('Invalid date of birth.', 'danger');
            redirect('students.php');
        }
    } else {
        $dob = null;
    }

    try {
        // Automatically generate internal email if not provided.
        if ($email === '') {
            $email = 'student.' . bin2hex(random_bytes(8)) . '@classmate.local';
        }

        // Automatically generate random password if not provided.
        if ($pass === '') {
            $pass = bin2hex(random_bytes(32));
        }

        $passwordHash = password_hash($pass, PASSWORD_DEFAULT);

        $q = $pdo->prepare("
            INSERT INTO users
            (
                student_code,
                full_name,
                latin_name,
                gender,
                phone,
                date_of_birth,
                email,
                password_hash,
                role,
                is_active
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'student', 1)
        ");

        $q->execute([
            $code,
            $name,
            $latin ?: null,
            $gender,
            $phone ?: null,
            $dob,
            $email,
            $passwordHash
        ]);

        flash('Student created successfully.');

    } catch (PDOException $e) {
        error_log($e->getMessage());

        if ($e->getCode() === '23000') {
            flash('Student ID or Email already exists.', 'danger');
        } else {
            flash('Could not create student. Please check the information.', 'danger');
        }
    }

    redirect('students.php');
}

// ======================================
// SEARCH STUDENTS
// ======================================
$search = trim($_GET['search'] ?? '');

if ($search !== '') {
    $keyword = '%' . $search . '%';

    $q = $pdo->prepare("
        SELECT
            id,
            student_code,
            full_name,
            latin_name,
            gender,
            phone,
            date_of_birth,
            email,
            is_active,
            created_at
        FROM users
        WHERE role = 'student'
          AND (
              student_code LIKE ?
              OR full_name LIKE ?
              OR latin_name LIKE ?
              OR phone LIKE ?
          )
        ORDER BY full_name
    ");

    $q->execute([
        $keyword,
        $keyword,
        $keyword,
        $keyword
    ]);

    $rows = $q->fetchAll();

} else {
    $rows = $pdo->query("
        SELECT
            id,
            student_code,
            full_name,
            latin_name,
            gender,
            phone,
            date_of_birth,
            email,
            is_active,
            created_at
        FROM users
        WHERE role = 'student'
        ORDER BY student_code
    ")->fetchAll();
}

$totalStudents = count($rows);

page_header('Students');
?>

<div class="welcome-row">
    <div>
        <h2>Student Management</h2>
        <p class="text-muted mb-0">
            Manage student information and search your classmates.
        </p>
    </div>
</div>

<!-- ADD STUDENT FORM -->
<div class="panel mb-4">
    <div class="panel-head">
        <h5><i class="bi bi-person-plus me-2"></i>Add New Student</h5>
    </div>

    <form method="post" class="p-3">
        <input
            type="hidden"
            name="csrf"
            value="<?= e(csrf_token()) ?>"
        >

        <div class="row g-3">

            <div class="col-md-4">
                <label class="form-label">Student ID *</label>
                <input
                    type="text"
                    name="student_code"
                    class="form-control"
                    placeholder="803565"
                    required
                >
            </div>

            <div class="col-md-4">
                <label class="form-label">ឈ្មោះខ្មែរ *</label>
                <input
                    type="text"
                    name="full_name"
                    class="form-control"
                    placeholder="ឈ្មោះសិស្ស"
                    required
                >
            </div>

            <div class="col-md-4">
                <label class="form-label">Latin Name</label>
                <input
                    type="text"
                    name="latin_name"
                    class="form-control"
                    placeholder="CHHIM NGOYLYHAE"
                >
            </div>

            <div class="col-md-4">
                <label class="form-label">Gender *</label>
                <select name="gender" class="form-select" required>
                    <option value="">Select gender</option>
                    <option value="ប្រុស">ប្រុស / Male</option>
                    <option value="ស្រី">ស្រី / Female</option>
                </select>
            </div>

            <div class="col-md-4">
                <label class="form-label">Phone Number</label>
                <input
                    type="tel"
                    name="phone"
                    class="form-control"
                    placeholder="012345678"
                >
            </div>

            <div class="col-md-4">
                <label class="form-label">Date of Birth</label>
                <input
                    type="date"
                    name="date_of_birth"
                    class="form-control"
                >
            </div>

            <div class="col-md-6">
                <label class="form-label">
                    Email <span class="text-muted">(Optional)</span>
                </label>
                <input
                    type="email"
                    name="email"
                    class="form-control"
                    placeholder="example@gmail.com"
                >
            </div>

            <div class="col-md-6">
                <label class="form-label">
                    Password <span class="text-muted">(Optional)</span>
                </label>
                <input
                    type="password"
                    name="password"
                    class="form-control"
                    minlength="10"
                    placeholder="Leave blank for automatic password"
                >
            </div>

        </div>

        <button type="submit" class="btn btn-primary mt-3">
            <i class="bi bi-plus-circle me-1"></i>
            Add Student
        </button>
    </form>
</div>

<!-- STUDENT LIST -->
<div class="panel">

    <div class="panel-head">
        <div>
            <h5 class="mb-1">Classmate List</h5>
            <span class="text-muted small">
                <?= $totalStudents ?> students found
            </span>
        </div>
    </div>

    <!-- SEARCH -->
    <form method="get" class="p-3">
        <div class="input-group">
            <span class="input-group-text">
                <i class="bi bi-search"></i>
            </span>

            <input
                type="search"
                name="search"
                class="form-control"
                placeholder="Search Student ID, Khmer name, Latin name or phone..."
                value="<?= e($search) ?>"
            >

            <button class="btn btn-primary" type="submit">
                Search
            </button>

            <?php if ($search !== ''): ?>
                <a href="students.php" class="btn btn-outline-secondary">
                    Clear
                </a>
            <?php endif; ?>
        </div>
    </form>

    <div class="table-responsive">
        <table class="table table-hover align-middle">

            <thead>
                <tr>
                    <th>#</th>
                    <th>Student ID</th>
                    <th>Khmer Name</th>
                    <th>Latin Name</th>
                    <th>Gender</th>
                    <th>Phone</th>
                    <th>Date of Birth</th>
                    <th>Account</th>
                </tr>
            </thead>

            <tbody>
                <?php if ($rows): ?>

                    <?php foreach ($rows as $index => $r): ?>
                        <tr>
                            <td><?= $index + 1 ?></td>

                            <td>
                                <strong>
                                    <?= e($r['student_code'] ?? '—') ?>
                                </strong>
                            </td>

                            <td>
                                <?= e($r['full_name']) ?>
                            </td>

                            <td>
                                <?= e($r['latin_name'] ?? '—') ?>
                            </td>

                            <td>
                                <?php if ($r['gender'] === 'ប្រុស'): ?>
                                    <span class="badge bg-primary">ប្រុស</span>
                                <?php elseif ($r['gender'] === 'ស្រី'): ?>
                                    <span class="badge bg-danger">ស្រី</span>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>

                            <td>
                                <?= e($r['phone'] ?? '—') ?>
                            </td>

                            <td>
                                <?= $r['date_of_birth']
                                    ? e(date('d-m-Y', strtotime($r['date_of_birth'])))
                                    : '—'
                                ?>
                            </td>

                            <td>
                                <?php if ($r['is_active']): ?>
                                    <span class="badge bg-success">Active</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Disabled</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">
                            <i class="bi bi-search fs-3 d-block mb-2"></i>
                            No students found.
                        </td>
                    </tr>

                <?php endif; ?>
            </tbody>

        </table>
    </div>
</div>

<?php page_footer(); ?>
