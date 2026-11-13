<?php
require_once __DIR__ . '/includes/functions.php';

/** Only allow redirecting to in-app pages after login. */
function safe_next(string $next): string
{
    return preg_match('#^((student|admin)/[a-z_]+\.php|scan\.php)(\?[A-Za-z0-9_=&%.\-]*)?$#', $next) ? $next : '';
}

/** Where to send a user after login: $next if their role may open it, otherwise their dashboard. */
function after_login(string $role, string $next): string
{
    $home = $role === 'admin' ? 'admin/index.php' : 'student/index.php';
    if ($next === '') return $home;
    return (str_starts_with($next, 'scan.php') || str_starts_with($next, $role . '/')) ? $next : $home;
}

$me = current_user();
$next = safe_next(get_str('next', 300) ?: post_str('next', 300));
if ($me) {
    redirect(after_login($me['role'], $next));
}

$role = get_str('role') === 'admin' ? 'admin' : 'student';
$identifier = '';
$error = '';

if (is_post()) {
    verify_csrf();
    $role = post_str('role') === 'admin' ? 'admin' : 'student';
    $identifier = post_str('identifier', 190);
    $password = $_POST['password'] ?? '';
    $password = is_string($password) ? $password : '';

    $lockUntil = $_SESSION['login_lock_until'] ?? 0;
    if ($lockUntil > time()) {
        $error = 'Too many failed attempts. Please wait ' . ($lockUntil - time()) . ' seconds and try again.';
    } elseif ($identifier === '' || $password === '') {
        $error = 'Please enter your login details and password.';
    } else {
        $st = db()->prepare('SELECT * FROM users WHERE email = ? OR (student_id IS NOT NULL AND student_id = ?) LIMIT 1');
        $st->execute([$identifier, $identifier]);
        $user = $st->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $_SESSION['login_fails'] = ($_SESSION['login_fails'] ?? 0) + 1;
            if ($_SESSION['login_fails'] >= 5) {
                $_SESSION['login_lock_until'] = time() + 60;
                $_SESSION['login_fails'] = 0;
            }
            $error = 'Incorrect login details or password.';
        } elseif ($user['role'] !== $role) {
            $error = $user['role'] === 'admin'
                ? 'This is an administrator account. Please choose "Admin" above.'
                : 'This is a student account. Please choose "Student" above.';
        } elseif ($user['status'] === 'blocked') {
            $error = 'Your account has been blocked. Please contact the administrator.';
        } else {
            unset($_SESSION['login_fails'], $_SESSION['login_lock_until']);
            login_user($user);
            flash('success', 'Welcome back, ' . $user['name'] . '!');
            redirect(after_login($user['role'], $next));
        }
    }
}
$flashes = take_flashes();
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login · <?= e(APP_NAME) ?></title>
    <link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
</head>
<body>
<div class="auth">
    <aside class="auth-side">
        <a class="brand" href="<?= e(url('index.php')) ?>"><img src="<?= e(asset('img/logo.svg')) ?>" alt="LearnX"></a>
        <div>
            <h2>Robotics &amp; IoT Devices Inventory</h2>
            <p>Sign in to request lab equipment or manage the inventory.</p>
            <ul>
                <li><?= icon('check') ?> Live device availability</li>
                <li><?= icon('check') ?> Online requests &amp; approvals</li>
                <li><?= icon('check') ?> QR codes on every device</li>
                <li><?= icon('check') ?> Due-date reminders &amp; overdue tracking</li>
            </ul>
        </div>
        <p class="small">&copy; <?= date('Y') ?> LearnX — The Future Learning</p>
    </aside>
    <main class="auth-main">
        <div class="auth-card">
            <a href="<?= e(url('index.php')) ?>" class="small"><?= icon('back') ?> Back to home</a>
            <h1 class="mt-2">Welcome back</h1>
            <p class="muted mb-0">Choose your role and sign in.</p>

            <?php foreach ($flashes as $f): ?>
                <div class="alert alert-<?= e($f['type']) ?> mt-2"><?= icon($f['type'] === 'success' ? 'check' : 'alert') ?><div><?= e($f['message']) ?></div></div>
            <?php endforeach; ?>
            <?php if ($error): ?>
                <div class="alert alert-error mt-2"><?= icon('alert') ?><div><?= e($error) ?></div></div>
            <?php endif; ?>

            <form method="post" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="next" value="<?= e($next) ?>">
                <div class="role-switch" role="radiogroup" aria-label="Login as">
                    <input type="radio" name="role" id="role-student" value="student" <?= $role === 'student' ? 'checked' : '' ?>>
                    <label for="role-student"><?= icon('user') ?> Student</label>
                    <input type="radio" name="role" id="role-admin" value="admin" <?= $role === 'admin' ? 'checked' : '' ?>>
                    <label for="role-admin"><?= icon('shield') ?> Admin</label>
                </div>
                <div class="field">
                    <label for="identifier" id="id-label"><?= $role === 'admin' ? 'Email address' : 'Email or Student ID' ?></label>
                    <div class="input-icon"><?= icon('user') ?><input type="text" id="identifier" name="identifier" value="<?= e($identifier) ?>" autocomplete="username" required autofocus></div>
                </div>
                <div class="field">
                    <label for="password">Password</label>
                    <div class="input-icon"><?= icon('lock') ?><input type="password" id="password" name="password" autocomplete="current-password" required></div>
                </div>
                <button class="btn btn-sky btn-lg btn-block" type="submit"><?= icon('arrow') ?> Sign in</button>
            </form>
            <p class="small muted mt-2 mb-0" id="register-hint">New student? <a href="<?= e(url('register.php')) ?>">Create an account</a></p>

            <div class="demo-creds">
                <strong>Demo accounts</strong><br>
                Admin: <code>admin@learnx.local</code> / <code>Admin@123</code><br>
                Student: <code>student@learnx.local</code> or <code>STU-0001</code> / <code>Student@123</code>
            </div>
        </div>
    </main>
</div>
<script>
document.querySelectorAll('input[name=role]').forEach(function (r) {
    r.addEventListener('change', function () {
        var admin = r.value === 'admin' && r.checked;
        document.getElementById('id-label').textContent = admin ? 'Email address' : 'Email or Student ID';
        document.getElementById('register-hint').style.display = admin ? 'none' : '';
    });
});
<?php if ($role === 'admin'): ?>document.getElementById('register-hint').style.display = 'none';<?php endif; ?>
</script>
</body>
</html>
