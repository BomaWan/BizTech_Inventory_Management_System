<?php
require_once __DIR__ . '/../includes/functions.php';
$me = require_role('admin');

if (is_post()) {
    verify_csrf();
    $action = post_str('action');
    $id = post_int('id');
    try {
        $target = null;
        if ($id) {
            $st = db()->prepare('SELECT * FROM users WHERE id = ?');
            $st->execute([$id]);
            $target = $st->fetch();
            if (!$target) throw new RuntimeException('User not found.');
        }

        if ($action === 'save') {
            $role = post_str('role') === 'admin' ? 'admin' : 'student';
            if ($target) $role = $target['role'];
            $name = post_str('name', 120);
            $email = post_str('email', 190);
            $studentId = post_str('student_id', 50);
            $course = post_str('course', 150);
            $phone = post_str('phone', 40);
            if ($name === '') throw new RuntimeException('Name is required.');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('A valid email is required.');
            if ($role === 'student') {
                if ($studentId === '') throw new RuntimeException('Student ID is required for students.');
                if (!preg_match('/^[A-Za-z0-9\-\/]{2,50}$/', $studentId)) throw new RuntimeException('Student ID may only contain letters, numbers, "-" and "/".');
            } else {
                $studentId = '';
                $course = '';
            }
            $dup = db()->prepare('SELECT id FROM users WHERE (email = ? OR (student_id IS NOT NULL AND student_id = ?)) AND id <> ?');
            $dup->execute([$email, $studentId ?: '__none__', $id]);
            if ($dup->fetch()) throw new RuntimeException('Another account already uses that email or Student ID.');

            if ($target) {
                db()->prepare('UPDATE users SET name=?, email=?, student_id=?, course=?, phone=? WHERE id=?')
                    ->execute([$name, $email, $studentId ?: null, $course ?: null, $phone ?: null, $id]);
                flash('success', "Account for $name updated.");
            } else {
                $password = (string)($_POST['password'] ?? '');
                if ($password === '') $password = random_password();
                elseif ($p = password_problem($password)) throw new RuntimeException($p);
                db()->prepare('INSERT INTO users (role,name,email,student_id,course,phone,password_hash,status,created_at) VALUES (?,?,?,?,?,?,?,?,?)')
                    ->execute([$role, $name, $email, $studentId ?: null, $course ?: null, $phone ?: null, password_hash($password, PASSWORD_DEFAULT), 'active', now()]);
                flash('success', ucfirst($role) . " account created for $name. Login: $email — password: $password");
            }
        } elseif ($action === 'toggle_block') {
            if ((int)$target['id'] === (int)$me['id']) throw new RuntimeException('You cannot block your own account.');
            $new = $target['status'] === 'blocked' ? 'active' : 'blocked';
            db()->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([$new, $id]);
            flash('success', $target['name'] . ($new === 'blocked' ? ' has been blocked and can no longer log in.' : ' has been unblocked.'));
        } elseif ($action === 'reset_password') {
            $password = (string)($_POST['password'] ?? '');
            if ($password === '') $password = random_password();
            elseif ($p = password_problem($password)) throw new RuntimeException($p);
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
            if ($target['role'] === 'student') {
                notify((int)$target['id'], 'info', 'Your password was reset by the administrator. Please change it from your profile page.');
            }
            flash('success', "Password for {$target['name']} reset. New password: $password");
        } elseif ($action === 'delete') {
            if ((int)$target['id'] === (int)$me['id']) throw new RuntimeException('You cannot remove your own account.');
            $st = db()->prepare("SELECT COUNT(*) FROM requests WHERE user_id = ? AND status IN ('approved','collected','return_pending')");
            $st->execute([$id]);
            if ((int)$st->fetchColumn() > 0) {
                throw new RuntimeException("{$target['name']} still has devices borrowed or awaiting collection. Mark them returned/cancelled first, or block the account instead.");
            }
            db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
            flash('success', "{$target['name']} has been removed.");
        }
    } catch (RuntimeException $ex) {
        flash('error', $ex->getMessage());
    } catch (PDOException $ex) {
        flash('error', 'Could not save: that email or Student ID may already be in use.');
    }
    redirect('admin/users.php?role=' . (post_str('_role') === 'admin' ? 'admin' : 'student'));
}

$role = get_str('role') === 'admin' ? 'admin' : 'student';
$q = get_str('q');
$params = [$role];
$sql = "SELECT u.*,
        (SELECT COUNT(*) FROM requests r WHERE r.user_id = u.id AND r.status IN ('collected','return_pending')) AS on_loan,
        (SELECT COUNT(*) FROM requests r WHERE r.user_id = u.id) AS total_requests
        FROM users u WHERE u.role = ?";
if ($q !== '') {
    $sql .= ' AND (u.name LIKE ? OR u.email LIKE ? OR u.student_id LIKE ? OR u.course LIKE ?)';
    array_push($params, "%$q%", "%$q%", "%$q%", "%$q%");
}
$sql .= ' ORDER BY u.name';
$st = db()->prepare($sql);
$st->execute($params);
$users = $st->fetchAll();
$counts = db()->query("SELECT role, COUNT(*) n FROM users GROUP BY role")->fetchAll(PDO::FETCH_KEY_PAIR);

$pageTitle = 'Users / Students';
$pageSubtitle = 'View, add, block or remove accounts and reset passwords';
$active = 'users';
require __DIR__ . '/../includes/header.php';
$J = JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP;
?>
<div class="card">
    <div class="tabs">
        <a class="<?= $role === 'student' ? 'active' : '' ?>" href="<?= e(url('admin/users.php?role=student')) ?>">Students<span class="count"><?= (int)($counts['student'] ?? 0) ?></span></a>
        <a class="<?= $role === 'admin' ? 'active' : '' ?>" href="<?= e(url('admin/users.php?role=admin')) ?>">Administrators<span class="count"><?= (int)($counts['admin'] ?? 0) ?></span></a>
    </div>
    <div class="card-head">
        <form class="toolbar" method="get" style="flex:1">
            <input type="hidden" name="role" value="<?= e($role) ?>">
            <div class="input-icon grow"><?= icon('search') ?><input type="search" name="q" value="<?= e($q) ?>" placeholder="Search by name, email, Student ID or course"></div>
            <button class="btn btn-light" type="submit">Search</button>
        </form>
        <div class="actions">
            <button class="btn btn-sky" type="button" data-modal="user-modal" data-reset data-title="Add <?= $role === 'admin' ? 'administrator' : 'student' ?>" data-fill='<?= json_encode(['id' => 0, 'role' => $role], $J) ?>'><?= icon('plus') ?> Add <?= $role === 'admin' ? 'admin' : 'student' ?></button>
        </div>
    </div>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Name</th><?php if ($role === 'student'): ?><th>Student ID</th><th>Course</th><?php endif; ?><th>Email</th><th class="num">On loan</th><th class="num">Requests</th><th>Status</th><th>Last login</th><th class="num">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($users as $u):
                $fill = json_encode(['id' => (int)$u['id'], 'role' => $u['role'], 'name' => $u['name'], 'email' => $u['email'], 'student_id' => $u['student_id'], 'course' => $u['course'], 'phone' => $u['phone']], $J);
                $self = (int)$u['id'] === (int)$me['id']; ?>
                <tr>
                    <td><div class="cell-device"><span class="avatar"><?= e(initials($u['name'])) ?></span><div><strong><?= e($u['name']) ?><?= $self ? ' (you)' : '' ?></strong><small><?= e($u['phone'] ?: '') ?></small></div></div></td>
                    <?php if ($role === 'student'): ?><td><?= e($u['student_id'] ?? '—') ?></td><td><?= e($u['course'] ?? '—') ?></td><?php endif; ?>
                    <td><?= e($u['email']) ?></td>
                    <td class="num"><?= (int)$u['on_loan'] ?></td>
                    <td class="num"><?= (int)$u['total_requests'] ?></td>
                    <td><?= status_badge($u['status']) ?></td>
                    <td class="nowrap small"><?= e($u['last_login'] ? fmt_date($u['last_login'], true) : 'Never') ?></td>
                    <td class="num">
                        <div class="btn-group" style="justify-content:flex-end">
                            <button class="btn btn-sm btn-light" type="button" title="Edit" data-modal="user-modal" data-reset data-title="Edit account" data-fill='<?= $fill ?>'><?= icon('edit') ?></button>
                            <button class="btn btn-sm btn-light" type="button" title="Reset password" data-modal="reset-modal" data-reset data-fill='<?= json_encode(['id' => (int)$u['id'], 'who' => $u['name']], $J) ?>'><?= icon('key') ?></button>
                            <?php if (!$self): ?>
                                <form class="inline" method="post" data-confirm="<?= $u['status'] === 'blocked' ? 'Unblock' : 'Block' ?> <?= e($u['name']) ?>?">
                                    <?= csrf_field() ?><input type="hidden" name="action" value="toggle_block"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="_role" value="<?= e($role) ?>">
                                    <button class="btn btn-sm btn-light" type="submit" title="<?= $u['status'] === 'blocked' ? 'Unblock' : 'Block' ?>"><?= icon($u['status'] === 'blocked' ? 'unlock' : 'ban') ?></button>
                                </form>
                                <form class="inline" method="post" data-confirm="Remove <?= e($u['name']) ?> permanently? Their request history and notifications will also be deleted.">
                                    <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="_role" value="<?= e($role) ?>">
                                    <button class="btn btn-sm btn-outline-red" type="submit" title="Remove"><?= icon('trash') ?></button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$users): ?>
                <tr><td colspan="10"><div class="empty"><?= icon('users') ?><h3>No accounts found</h3></div></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<dialog class="modal" id="user-modal">
    <form method="post">
        <div class="modal-head"><h3 data-modal-title>Add student</h3><button class="icon-btn" type="button" data-close aria-label="Close"><?= icon('x') ?></button></div>
        <div class="modal-body">
            <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="0"><input type="hidden" name="role" value="<?= e($role) ?>"><input type="hidden" name="_role" value="<?= e($role) ?>">
            <div class="form-grid">
                <div class="field full"><label for="u-name">Full name</label><input type="text" id="u-name" name="name" required maxlength="120"></div>
                <div class="field"><label for="u-email">Email</label><input type="email" id="u-email" name="email" required maxlength="190"></div>
                <div class="field"><label for="u-phone">Phone <span class="hint">(optional)</span></label><input type="text" id="u-phone" name="phone" maxlength="40"></div>
                <?php if ($role === 'student'): ?>
                    <div class="field"><label for="u-sid">Student ID</label><input type="text" id="u-sid" name="student_id" maxlength="50" required></div>
                    <div class="field"><label for="u-course">Course</label><input type="text" id="u-course" name="course" maxlength="150"></div>
                <?php endif; ?>
                <div class="field full" id="u-pass-row"><label for="u-pass">Initial password <span class="hint">(leave blank to generate one)</span></label><input type="text" id="u-pass" name="password" autocomplete="off"></div>
            </div>
        </div>
        <div class="modal-foot"><button class="btn btn-light" type="button" data-close>Cancel</button><button class="btn btn-sky" type="submit"><?= icon('check') ?> Save</button></div>
    </form>
</dialog>

<dialog class="modal" id="reset-modal">
    <form method="post">
        <div class="modal-head"><h3>Reset password</h3><button class="icon-btn" type="button" data-close aria-label="Close"><?= icon('x') ?></button></div>
        <div class="modal-body">
            <?= csrf_field() ?><input type="hidden" name="action" value="reset_password"><input type="hidden" name="id" value="0"><input type="hidden" name="_role" value="<?= e($role) ?>">
            <p>Reset the password for <strong data-text="who"></strong>.</p>
            <div class="field mb-0"><label for="r-pass">New password <span class="hint">(leave blank to generate a random one)</span></label><input type="text" id="r-pass" name="password" autocomplete="off"></div>
            <p class="hint mt-2 mb-0">The new password is shown once after saving. Share it with the user privately.</p>
        </div>
        <div class="modal-foot"><button class="btn btn-light" type="button" data-close>Cancel</button><button class="btn" type="submit"><?= icon('key') ?> Reset password</button></div>
    </form>
</dialog>
<?php
$inlineScript = <<<'JS'
document.getElementById('user-modal').addEventListener('modal:open', function () {
    var isEdit = this.querySelector('[name=id]').value !== '0';
    document.getElementById('u-pass-row').style.display = isEdit ? 'none' : '';
});
JS;
require __DIR__ . '/../includes/footer.php';
