<?php
require __DIR__.'/app.php';
if (user()) redirect('dashboard.php');
$error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_check();
    $email=trim(strtolower($_POST['email'] ?? '')); $pass=$_POST['password'] ?? '';
    $q=$pdo->prepare('SELECT id,full_name,email,password_hash,role,is_active FROM users WHERE email=? LIMIT 1');
    $q->execute([$email]); $u=$q->fetch();
    if ($u && $u['is_active'] && password_verify($pass,$u['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user']=['id'=>(int)$u['id'],'full_name'=>$u['full_name'],'email'=>$u['email'],'role'=>$u['role']];
        redirect('dashboard.php');
    }
    $error='Invalid email or password.';
}
page_header('Sign in');
?>
<div class="login-card">
  <div class="login-logo"><i class="bi bi-qr-code-scan"></i></div>
  <h1>Welcome back</h1><p class="text-muted">Sign in to Classmate Attendance</p>
  <?php if($error): ?><div class="alert alert-danger"><?=e($error)?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
    <label class="form-label">Email address</label><input class="form-control mb-3" type="email" name="email" required autocomplete="username">
    <label class="form-label">Password</label><input class="form-control mb-4" type="password" name="password" required autocomplete="current-password">
    <button class="btn btn-primary w-100 py-2">Sign in <i class="bi bi-arrow-right"></i></button>
  </form>
  <div class="small text-muted mt-4">Ask your class administrator for an account.</div>
</div>
<?php page_footer(); ?>
