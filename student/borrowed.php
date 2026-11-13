<?php
require_once __DIR__ . '/../includes/functions.php';
$me = require_role('student');
$uid = (int)$me['id'];

if (is_post()) {
    verify_csrf();
    if (post_str('action') === 'return') {
        $st = db()->prepare("UPDATE requests SET status = 'return_pending', return_requested_at = ? WHERE id = ? AND user_id = ? AND status = 'collected'");
        $st->execute([now(), post_int('id'), $uid]);
        if ($st->rowCount() > 0) {
            flash('success', 'Return submitted. Please hand the device to the lab administrator, who will confirm the return.');
        } else {
            flash('error', 'This device cannot be returned from here.');
        }
    }
    redirect('student/borrowed.php');
}
generate_return_reminders($uid);

$st = db()->prepare("SELECT r.*, d.name AS device_name, d.code, d.photo, d.location FROM requests r JOIN devices d ON d.id = r.device_id
                     WHERE r.user_id = ? AND r.status IN ('collected','return_pending') ORDER BY r.due_date");
$st->execute([$uid]);
$rows = $st->fetchAll();

$st = db()->prepare("SELECT r.*, d.name AS device_name, d.code, d.photo, d.location FROM requests r JOIN devices d ON d.id = r.device_id
                     WHERE r.user_id = ? AND r.status = 'approved' ORDER BY r.decided_at");
$st->execute([$uid]);
$ready = $st->fetchAll();

$overdue = count(array_filter($rows, 'is_overdue'));

$pageTitle = 'My Borrowed Devices';
$pageSubtitle = 'Date borrowed, due dates and returns';
$active = 'borrowed';
require __DIR__ . '/../includes/header.php';
?>
<?php if ($overdue): ?>
    <div class="alert alert-error"><?= icon('alert') ?><div><strong><?= $overdue ?> device<?= $overdue === 1 ? ' is' : 's are' ?> overdue.</strong> Overdue items are highlighted in red. Please return them as soon as possible.</div></div>
<?php endif; ?>

<?php if ($ready): ?>
<div class="card">
    <div class="card-head"><h2><?= icon('check') ?> Approved — ready to collect</h2></div>
    <ul class="list">
        <?php foreach ($ready as $r): ?>
            <li>
                <img src="<?= e(device_photo_url($r)) ?>" alt="" style="width:44px;height:44px;border-radius:9px;object-fit:cover">
                <div class="grow"><div class="title"><?= e($r['device_name']) ?> × <?= (int)$r['quantity'] ?></div><div class="sub">Collect from <?= e($r['location'] ?: 'the lab') ?> · borrowing period <?= (int)$r['days'] ?> day<?= $r['days'] == 1 ? '' : 's' ?> starts on collection</div></div>
                <?= status_badge('approved') ?>
            </li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-head"><h2><?= icon('package') ?> Devices I have borrowed</h2></div>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Device</th><th class="num">Qty</th><th>Date borrowed</th><th>Due date</th><th>Status</th><th class="num">Return</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): $over = is_overdue($r); ?>
                <tr class="<?= $over ? 'row-overdue' : '' ?>">
                    <td><div class="cell-device"><img src="<?= e(device_photo_url($r)) ?>" alt=""><div><strong><?= e($r['device_name']) ?></strong><small><?= e($r['code']) ?> · <?= e($r['location'] ?: '') ?></small></div></div></td>
                    <td class="num"><?= (int)$r['quantity'] ?></td>
                    <td class="nowrap"><?= e(fmt_date($r['collected_at'], true)) ?></td>
                    <td class="nowrap due"><?= e(fmt_date($r['due_date'], true)) ?><br><small><?= e(due_text($r)) ?></small></td>
                    <td><?= status_badge($r['status'], $over) ?><?php if ($r['status'] === 'return_pending'): ?><br><small class="muted">Waiting for admin to confirm</small><?php endif; ?></td>
                    <td class="num">
                        <?php if ($r['status'] === 'collected'): ?>
                            <form class="inline" method="post" data-confirm="Return <?= e($r['device_name']) ?>? Hand it to the lab administrator so they can confirm.">
                                <?= csrf_field() ?><input type="hidden" name="action" value="return"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <button class="btn btn-sm <?= $over ? 'btn-red' : 'btn-sky' ?>" type="submit"><?= icon('repeat') ?> Return</button>
                            </form>
                        <?php else: ?>
                            <span class="badge badge-warning">Return submitted</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
                <tr><td colspan="6"><div class="empty"><?= icon('package') ?><h3>You have no borrowed devices</h3><p class="mb-0">Devices appear here once you collect them from the lab.</p></div></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
