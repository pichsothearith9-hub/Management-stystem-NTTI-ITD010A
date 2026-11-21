
<?php

/*
|--------------------------------------------------------------------------
| CONFIGURATION
|--------------------------------------------------------------------------
*/

$configFile = __DIR__ . '/config.local.php';

$config = file_exists($configFile)
    ? require $configFile
    : require __DIR__ . '/config.php';

date_default_timezone_set(
    $config['timezone'] ?? 'Asia/Phnom_Penh'
);


/*
|--------------------------------------------------------------------------
| SESSION
|--------------------------------------------------------------------------
*/

if (session_status() !== PHP_SESSION_ACTIVE) {

    session_name(
        $config['session_name'] ?? 'classmate_attendance'
    );

    $secure = (
        !empty($_SERVER['HTTPS']) &&
        $_SERVER['HTTPS'] !== 'off'
    );

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();
}


/*
|--------------------------------------------------------------------------
| DATABASE CONNECTION
|--------------------------------------------------------------------------
*/

$dsn = sprintf(
    'mysql:host=%s;dbname=%s;charset=utf8mb4',
    $config['db_host'],
    $config['db_name']
);

try {

    $pdo = new PDO(
        $dsn,
        $config['db_user'],
        $config['db_pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

} catch (PDOException $e) {

    error_log('Database connection error: ' . $e->getMessage());

    http_response_code(500);

    exit(
        'Database connection failed. Check config.local.php and database setup.'
    );
}


/*
|--------------------------------------------------------------------------
| HELPER FUNCTIONS
|--------------------------------------------------------------------------
*/

function e(?string $v): string
{
    return htmlspecialchars(
        $v ?? '',
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}


/*
|--------------------------------------------------------------------------
| CSRF TOKEN
|--------------------------------------------------------------------------
*/

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {

        $_SESSION['csrf'] = bin2hex(
            random_bytes(32)
        );
    }

    return $_SESSION['csrf'];
}


function csrf_check(): void
{
    $sessionToken = $_SESSION['csrf'] ?? '';
    $postToken = $_POST['csrf'] ?? '';

    if (
        !is_string($sessionToken) ||
        !is_string($postToken) ||
        $sessionToken === '' ||
        !hash_equals($sessionToken, $postToken)
    ) {

        http_response_code(419);

        exit(
            'សំណើមិនត្រឹមត្រូវ។ សូម Refresh ទំព័រ រួចសាកល្បងម្ដងទៀត។'
        );
    }
}


/*
|--------------------------------------------------------------------------
| USER AUTHENTICATION
|--------------------------------------------------------------------------
*/

function user(): ?array
{
    return $_SESSION['user'] ?? null;
}


function require_login(): void
{
    if (!user()) {

        header('Location: index.php');
        exit;
    }
}


function require_admin(): void
{
    require_login();

    if ((user()['role'] ?? '') !== 'admin') {

        http_response_code(403);

        exit('អ្នកមិនមានសិទ្ធិចូលប្រើផ្នែកនេះទេ។');
    }
}


/*
|--------------------------------------------------------------------------
| FLASH MESSAGE
|--------------------------------------------------------------------------
*/

function flash(
    string $msg,
    string $type = 'success'
): void {

    $_SESSION['flash'] = [$msg, $type];
}


/*
|--------------------------------------------------------------------------
| REDIRECT
|--------------------------------------------------------------------------
*/

function redirect(string $to): never
{
    header('Location: ' . $to);
    exit;
}


/*
|--------------------------------------------------------------------------
| BASE URL
|--------------------------------------------------------------------------
*/

function base_url(): string
{
    global $config;

    return rtrim(
        $config['base_url'] ?? '',
        '/'
    );
}


/*
|--------------------------------------------------------------------------
| SIDEBAR LINK HELPER
|--------------------------------------------------------------------------
*/

function sidebar_link(
    string $file,
    string $icon,
    string $label
): string {

    $currentPage = basename(
        $_SERVER['PHP_SELF'] ?? ''
    );

    $active = ($currentPage === $file)
        ? ' active'
        : '';

    return '
        <a class="nav-link' . $active . '" href="' . e($file) . '">
            <i class="bi ' . e($icon) . '"></i>
            ' . e($label) . '
        </a>
    ';
}


/*
|--------------------------------------------------------------------------
| PAGE HEADER
|--------------------------------------------------------------------------
*/

function page_header(string $title): void
{
    $u = user();

    echo '<!doctype html>
    <html lang="km">
    <head>

        <meta charset="utf-8">

        <meta name="viewport" content="width=device-width, initial-scale=1">

        <meta name="theme-color" content="#102d50">

        <title>' . e($title) . ' · ITD010A</title>

        <link
            href="https://fonts.googleapis.com/css2?family=Kantumruy+Pro:wght@300;400;500;600;700&display=swap"
            rel="stylesheet"
        >

        <link
            href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
            rel="stylesheet"
        >

        <link
            href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
            rel="stylesheet"
        >

        <link href="assets/style.css" rel="stylesheet">

        <style>
            body {
                font-family: "Kantumruy Pro", sans-serif;
            }
        </style>

    </head>

    <body>';


    /*
    |--------------------------------------------------------------------------
    | LOGGED-IN USER LAYOUT
    |--------------------------------------------------------------------------
    */

    if ($u) {
        echo '
        <div class="app-shell">
            <aside class="sidebar">    
                    </span>
                    <span>
                        <h1>ITD010A</h1><br>
                        <small>ប្រព័ន្ធគ្រប់គ្រងវត្តមាន</small>
                    </span>

                </a>

                <div class="nav-caption">
                    ម៉ឺនុយប្រព័ន្ធ
                </div>
        ';


        /*
        |--------------------------------------------------------------------------
        | DASHBOARD LINK
        |--------------------------------------------------------------------------
        */

        echo sidebar_link(
            'dashboard.php',
            'bi-grid',
            'ផ្ទាំងគ្រប់គ្រង'
        );


        /*
        |--------------------------------------------------------------------------
        | ADMIN SIDEBAR
        |--------------------------------------------------------------------------
        */

        if (($u['role'] ?? '') === 'admin') {

            echo sidebar_link(
                'students.php',
                'bi-people',
                'បញ្ជីនិស្សិត'
            );

            echo sidebar_link(
                'sessions.php',
                'bi-qr-code',
                'វគ្គស្កេនវត្តមាន'
            );

            echo sidebar_link(
                'records.php',
                'bi-calendar-check',
                'កំណត់ត្រាវត្តមាន'
            );

            echo sidebar_link(
                'absences.php',
                'bi-calendar-x',
                'គ្រប់គ្រងអវត្តមាន'
            );

            echo sidebar_link(
                'notifications.php',
                'bi-bell',
                'ការជូនដំណឹង'
            );

            echo sidebar_link(
                'admin_permissions.php',
                'bi-envelope-paper',
                'សំណើសុំច្បាប់'
            );

            echo sidebar_link(
                'admin_complaints.php',
                'bi-chat-square-text',
                'ពាក្យបណ្ដឹងនិស្សិត'
            );

        } else {

            /*
            |--------------------------------------------------------------------------
            | STUDENT SIDEBAR
            |--------------------------------------------------------------------------
            */

            echo sidebar_link(
                'my_attendance.php',
                'bi-calendar-check',
                'ប្រវត្តិវត្តមានរបស់ខ្ញុំ'
            );

            echo sidebar_link(
                'my_complaints.php',
                'bi-chat-square-text',
                'ពាក្យបណ្ដឹងរបស់ខ្ញុំ'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | SIDEBAR BOTTOM
        |--------------------------------------------------------------------------
        */

        $fullName = $u['full_name'] ?? 'User';

        $avatar = strtoupper(
            substr($fullName, 0, 1)
        );

        $roleLabel = (($u['role'] ?? '') === 'admin')
            ? 'អ្នកគ្រប់គ្រងប្រព័ន្ធ'
            : 'និស្សិត';

        $displayName = (($u['role'] ?? '') === 'admin')
            ? 'PICH SOTHEARITH'
            : $fullName;

        echo '
                <div class="sidebar-bottom">

                    <div class="user-chip">

                        <span class="avatar">
                            ' . e($avatar) . '
                        </span>

                        <span>

                            ' . e($displayName) . '

                            <small>
                                ' . e($roleLabel) . '
                            </small>

                        </span>

                    </div>

                    <a class="nav-link" href="logout.php">

                        <i class="bi bi-box-arrow-left"></i>

                        ចាកចេញ

                    </a>

                </div>

            </aside>


            <main class="main-area">

                <header class="topbar">

                    <button
                        class="btn btn-light d-lg-none"
                        id="menuToggle"
                        type="button"
                        aria-label="បើកម៉ឺនុយ"
                    >

                        <i class="bi bi-list"></i>

                    </button>

                    <span>' . e($title) . '</span>

                    <span class="ms-auto text-muted small">
                        ' . e(date('D, d M Y')) . '
                    </span>

                </header>

                <div class="content">
        ';


        /*
        |--------------------------------------------------------------------------
        | FLASH MESSAGE
        |--------------------------------------------------------------------------
        */

        if (
            !empty($_SESSION['flash']) &&
            is_array($_SESSION['flash'])
        ) {

            $m = (string) (
                $_SESSION['flash'][0] ?? ''
            );

            $t = (string) (
                $_SESSION['flash'][1] ?? 'success'
            );

            unset($_SESSION['flash']);

            $allowedTypes = [
                'success',
                'danger',
                'warning',
                'info',
                'primary',
                'secondary'
            ];

            if (!in_array($t, $allowedTypes, true)) {

                $t = 'success';
            }

            echo '
                <div
                    class="alert alert-' . e($t) . ' alert-dismissible fade show"
                    role="alert"
                >

                    ' . e($m) . '

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="alert"
                        aria-label="បិទ"
                    ></button>

                </div>
            ';
        }

    } else {

        /*
        |--------------------------------------------------------------------------
        | GUEST / AUTHENTICATION LAYOUT
        |--------------------------------------------------------------------------
        */

        echo '<main class="auth-wrap">';
    }
}


/*
|--------------------------------------------------------------------------
| PAGE FOOTER
|--------------------------------------------------------------------------
*/

function page_footer(): void
{
    if (user()) {

        echo '</div></main></div>';

    } else {

        echo '</main>';
    }

    echo '
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

        <script src="assets/app.js"></script>

    </body>
    </html>
    ';
}
