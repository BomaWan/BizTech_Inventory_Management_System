<?php
require_once __DIR__ . '/includes/functions.php';

if (current_user()) {
    redirect(current_user()['role'] === 'admin' ? 'admin/index.php' : 'student/index.php');
}

$old = ['name' => '', 'email' => '', 'student_id' => '', 'course' => '', 'phone' => ''];
$errors = [];

if (is_post()) {
    verify_csrf();
    foreach ($old as $k => $_) {
        $old[$k] = post_str($k, $k === 'email' ? 190 : 150);
    }
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['password_confirm'] ?? '');

    if ($old['name'] === '') $errors[] = 'Full name is required.';
    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email address is required.';
    if ($old['student_id'] === '') $errors[] = 'Student ID is required.';
    elseif (!preg_match('/^[A-Za-z0-9\-\/]{2,50}$/', $old['student_id'])) $errors[] = 'Student ID may only contain letters, numbers, "-" and "/".';
    if ($old['course'] === '') $errors[] = 'Course is required.';
    if ($p = password_problem($password)) $errors[] = $p;
    if ($password !== $confirm) $errors[] = 'Passwords do not match.';

    if (!$errors) {
        $st = db()->prepare('SELECT email, student_id FROM users WHERE email = ? OR student_id = ?');
        $st->execute([$old['email'], $old['student_id']]);
        foreach ($st->fetchAll() as $row) {
            if (strcasecmp($row['email'], $old['email']) === 0) $errors[] = 'That email address is already registered.';
            if ($row['student_id'] !== null && strcasecmp($row['student_id'], $old['student_id']) === 0) $errors[] = 'That Student ID is already registered.';
        }
    }

    if (!$errors) {
        db()->prepare('INSERT INTO users (role,name,email,student_id,course,phone,password_hash,status,created_at) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute(['student', $old['name'], $old['email'], $old['student_id'], $old['course'], $old['phone'] ?: null, password_hash($password, PASSWORD_DEFAULT), 'active', now()]);
        flash('success', 'Account created. You can now sign in.');
        redirect('login.php?role=student');
    }
}
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Register · <?= e(APP_NAME) ?></title>
    <link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
</head>
<body>
<div class="auth">
    <aside class="auth-side">
        <a class="brand" href="<?= e(url('index.php')) ?>"><img src="<?= e(asset('img/logo.svg')) ?>" alt="LearnX"></a>
        <div>
            <h2>Create your student account</h2>
            <p>Register to browse lab equipment, send borrowing requests and keep track of your devices.</p>
        </div>
        <p class="small">&copy; <?= date('Y') ?> LearnX — The Future Learning</p>
    </aside>
    <main class="auth-main">
        <div class="auth-card" style="max-width:560px">
            <a href="<?= e(url('login.php')) ?>" class="small"><?= icon('back') ?> Back to login</a>
            <h1 class="mt-2">Student registration</h1>
            <p class="muted">All fields except phone are required.</p>
            <?php if ($errors): ?>
                <div class="alert alert-error"><?= icon('alert') ?><div><?= implode('<br>', array_map('e', $errors)) ?></div></div>
            <?php endif; ?>
            <form method="post" novalidate>
                <?= csrf_field() ?>
                <div class="form-grid">
                    <div class="field full"><label for="name">Full name</label><input type="text" id="name" name="name" value="<?= e($old['name']) ?>" required></div>
                    <div class="field"><label for="student_id">Student ID</label><input type="text" id="student_id" name="student_id" value="<?= e($old['student_id']) ?>" required></div>
                    <div class="field"><label for="email">Email</label><input type="email" id="email" name="email" value="<?= e($old['email']) ?>" required></div>
                    <div class="field"><label for="course">Course</label><input type="text" id="course" name="course" value="<?= e($old['course']) ?>" required></div>
                    <div class="field"><label for="phone">Phone <span class="hint">(optional)</span></label><input type="text" id="phone" name="phone" value="<?= e($old['phone']) ?>"></div>
                    <div class="field"><label for="password">Password</label><input type="password" id="password" name="password" autocomplete="new-password" required><span class="hint">At least 8 characters, letters and numbers.</span></div>
                    <div class="field"><label for="password_confirm">Confirm password</label><input type="password" id="password_confirm" name="password_confirm" autocomplete="new-password" required></div>
                </div>
                <button class="btn btn-sky btn-lg btn-block mt-2" type="submit"><?= icon('user') ?> Create account</button>
            </form>
        </div>
    </main>
</div>
</body>
</html>
