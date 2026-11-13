<?php
/**
 * Shared profile page for admins and students. Expects $me (current user) and $profilePath.
 */
if (is_post()) {
    verify_csrf();
    $action = post_str('action');
    try {
        if ($action === 'details') {
            $name = post_str('name', 120);
            $email = post_str('email', 190);
            $phone = post_str('phone', 40);
            $course = post_str('course', 150);
            if ($name === '') throw new RuntimeException('Name is required.');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('A valid email is required.');
            if ($me['role'] === 'student' && $course === '') throw new RuntimeException('Course is required.');
            $dup = db()->prepare('SELECT id FROM users WHERE email = ? AND id <> ?');
            $dup->execute([$email, $me['id']]);
            if ($dup->fetch()) throw new RuntimeException('That email address is already used by another account.');
            db()->prepare('UPDATE users SET name = ?, email = ?, phone = ?, course = ? WHERE id = ?')
                ->execute([$name, $email, $phone ?: null, $me['role'] === 'student' ? $course : null, $me['id']]);
            flash('success', 'Profile updated.');
        } elseif ($action === 'password') {
            $current = (string)($_POST['current_password'] ?? '');
            $new = (string)($_POST['new_password'] ?? '');
            $confirm = (string)($_POST['confirm_password'] ?? '');
            if (!password_verify($current, $me['password_hash'])) throw new RuntimeException('Your current password is incorrect.');
            if ($p = password_problem($new)) throw new RuntimeException($p);
            if ($new !== $confirm) throw new RuntimeException('The new passwords do not match.');
            if ($current === $new) throw new RuntimeException('The new password must be different from the current one.');
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $me['id']]);
            session_regenerate_id(true);
            flash('success', 'Password changed successfully.');
        }
    } catch (RuntimeException $ex) {
        flash('error', $ex->getMessage());
    }
    redirect($profilePath);
}

$isStudent = $me['role'] === 'student';
if ($isStudent) {
    $st = db()->prepare("SELECT
        SUM(status = 'pending') AS pending,
        SUM(status IN ('collected','return_pending')) AS on_loan,
        SUM(status = 'returned') AS returned,
        COUNT(*) AS total FROM requests WHERE user_id = ?");
    $st->execute([$me['id']]);
    $mine = $st->fetch();
}

$pageTitle = 'My Profile';
$pageSubtitle = $isStudent ? 'Your student details and password' : 'Administrator account settings';
$active = 'profile';
require __DIR__ . '/header.php';
?>
<div class="card">
    <div class="card-body profile-head">
        <span class="avatar lg"><?= e(initials($me['name'])) ?></span>
        <div>
            <h2><?= e($me['name']) ?></h2>
            <div class="muted"><?= $isStudent ? 'Student ID: <strong>' . e($me['student_id'] ?? '—') . '</strong> · ' . e($me['course'] ?? '') : 'Administrator' ?></div>
            <div class="small muted">Member since <?= e(fmt_date($me['created_at'])) ?><?= $me['last_login'] ? ' · last login ' . e(fmt_date($me['last_login'], true)) : '' ?></div>
        </div>
        <?php if ($isStudent): ?>
            <div style="margin-left:auto;display:flex;gap:10px;flex-wrap:wrap">
                <span class="badge badge-warning"><?= (int)$mine['pending'] ?> pending</span>
                <span class="badge badge-primary"><?= (int)$mine['on_loan'] ?> on loan</span>
                <span class="badge badge-success"><?= (int)$mine['returned'] ?> returned</span>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="grid grid-2">
    <div class="card">
        <div class="card-head"><h2><?= icon('user') ?> Profile details</h2></div>
        <div class="card-body">
            <form method="post">
                <?= csrf_field() ?><input type="hidden" name="action" value="details">
                <div class="form-grid">
                    <div class="field full"><label for="p-name">Full name</label><input type="text" id="p-name" name="name" value="<?= e($me['name']) ?>" required maxlength="120"></div>
                    <?php if ($isStudent): ?>
                        <div class="field"><label for="p-sid">Student ID</label><input type="text" id="p-sid" value="<?= e($me['student_id']) ?>" disabled><span class="hint">Contact the administrator to change it.</span></div>
                        <div class="field"><label for="p-course">Course</label><input type="text" id="p-course" name="course" value="<?= e($me['course']) ?>" required maxlength="150"></div>
                    <?php endif; ?>
                    <div class="field"><label for="p-email">Email</label><input type="email" id="p-email" name="email" value="<?= e($me['email']) ?>" required maxlength="190"></div>
                    <div class="field"><label for="p-phone">Phone</label><input type="text" id="p-phone" name="phone" value="<?= e($me['phone']) ?>" maxlength="40"></div>
                </div>
                <button class="btn btn-sky mt-2" type="submit"><?= icon('check') ?> Save changes</button>
            </form>
        </div>
    </div>
    <div class="card">
        <div class="card-head"><h2><?= icon('lock') ?> Change password</h2></div>
        <div class="card-body">
            <form method="post" autocomplete="off">
                <?= csrf_field() ?><input type="hidden" name="action" value="password">
                <div class="field"><label for="cp">Current password</label><input type="password" id="cp" name="current_password" required autocomplete="current-password"></div>
                <div class="field"><label for="np">New password</label><input type="password" id="np" name="new_password" required autocomplete="new-password"><span class="hint">At least 8 characters with letters and numbers.</span></div>
                <div class="field"><label for="cfp">Confirm new password</label><input type="password" id="cfp" name="confirm_password" required autocomplete="new-password"></div>
                <button class="btn" type="submit"><?= icon('key') ?> Update password</button>
            </form>
        </div>
    </div>
</div>
<div class="card mt-2">
    <div class="card-body" style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">
        <div style="flex:1"><strong style="color:var(--navy)">Sign out</strong><div class="muted small">End your session on this device.</div></div>
        <form method="post" action="<?= e(url('logout.php')) ?>"><?= csrf_field() ?><button class="btn btn-red" type="submit"><?= icon('logout') ?> Logout</button></form>
    </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
