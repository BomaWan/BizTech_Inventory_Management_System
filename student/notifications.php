<?php
require_once __DIR__ . '/../includes/functions.php';
$me = require_role('student');
$uid = (int)$me['id'];

if (is_post()) {
    verify_csrf();
    $action = post_str('action');
    if ($action === 'read_all') {
        db()->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?')->execute([$uid]);
        flash('success', 'All notifications marked as read.');
    } elseif ($action === 'delete') {
        db()->prepare('DELETE FROM notifications WHERE id = ? AND user_id = ?')->execute([post_int('id'), $uid]);
    } elseif ($action === 'clear_read') {
        db()->prepare('DELETE FROM notifications WHERE user_id = ? AND is_read = 1')->execute([$uid]);
        flash('success', 'Read notifications cleared.');
    }
    redirect('student/notifications.php');
}
generate_return_reminders($uid);

$filter = get_str('type');
$types = ['' => 'All', 'approved' => 'Approved', 'rejected' => 'Rejected', 'reminder' => 'Reminders'];
if (!isset($types[$filter])) $filter = '';

$sql = 'SELECT * FROM notifications WHERE user_id = ?';
$params = [$uid];
if ($filter === 'reminder') {
    $sql .= " AND type IN ('reminder','overdue')";
} elseif ($filter !== '') {
    $sql .= ' AND type = ?';
    $params[] = $filter;
}
$sql .= ' ORDER BY created_at DESC, id DESC LIMIT 200';
$st = db()->prepare($sql);
$st->execute($params);
$notes = $st->fetchAll();

// Mark the ones being viewed as read (after fetching, so they still render as new this time)
$unreadIds = array_map(fn($n) => (int)$n['id'], array_filter($notes, fn($n) => !$n['is_read']));
if ($unreadIds) {
    $in = implode(',', array_fill(0, count($unreadIds), '?'));
    db()->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND id IN ($in)")->execute(array_merge([$uid], $unreadIds));
}

$iconFor = ['approved' => 'check', 'rejected' => 'x', 'returned' => 'check', 'reminder' => 'clock', 'overdue' => 'alert', 'collected' => 'package', 'info' => 'bell'];

$pageTitle = 'Notifications';
$pageSubtitle = 'Approvals, rejections and return reminders';
$active = 'notifications';
require __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <div class="tabs">
        <?php foreach ($types as $k => $label): ?>
            <a class="<?= $filter === $k ? 'active' : '' ?>" href="<?= e(url('student/notifications.php' . ($k ? '?type=' . $k : ''))) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
        <div style="margin-left:auto;display:flex;gap:8px">
            <form class="inline" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="read_all"><button class="btn btn-sm btn-light" type="submit"><?= icon('check') ?> Mark all read</button></form>
            <form class="inline" method="post" data-confirm="Delete all read notifications?"><?= csrf_field() ?><input type="hidden" name="action" value="clear_read"><button class="btn btn-sm btn-light" type="submit"><?= icon('trash') ?> Clear read</button></form>
        </div>
    </div>
    <?php foreach ($notes as $n): ?>
        <div class="notif <?= $n['is_read'] ? '' : 'unread' ?>">
            <span class="n-icon <?= e($n['type']) ?>"><?= icon($iconFor[$n['type']] ?? 'bell') ?></span>
            <div class="msg">
                <div><?php if (!$n['is_read']): ?><span class="badge badge-info">New</span> <?php endif; ?><?= e($n['message']) ?></div>
                <div class="time"><?= e(fmt_date($n['created_at'], true)) ?> · <?= e(time_ago($n['created_at'])) ?></div>
            </div>
            <form class="inline" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
                <button class="btn btn-sm btn-ghost" type="submit" title="Delete"><?= icon('x') ?></button></form>
        </div>
    <?php endforeach; ?>
    <?php if (!$notes): ?>
        <div class="empty"><?= icon('bell') ?><h3>No notifications</h3><p class="mb-0">You will be notified when your requests are approved or rejected, and before devices are due.</p></div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
