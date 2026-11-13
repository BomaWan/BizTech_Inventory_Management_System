<?php
require_once __DIR__ . '/../includes/functions.php';
$me = require_role('student');
$uid = (int)$me['id'];

if (is_post()) {
    verify_csrf();
    if (post_str('action') === 'cancel') {
        $st = db()->prepare("UPDATE requests SET status = 'cancelled' WHERE id = ? AND user_id = ? AND status IN ('pending','approved')");
        $st->execute([post_int('id'), $uid]);
        if ($st->rowCount() > 0) {
            flash('success', 'Request cancelled.');
        } else {
            flash('error', 'This request can no longer be cancelled.');
        }
    }
    redirect('student/requests.php?status=' . rawurlencode(post_str('_status') ?: 'all'));
}
generate_return_reminders($uid);

$tabs = [
    'all'       => ['All', null],
    'pending'   => ['Pending', ['pending']],
    'approved'  => ['Approved', ['approved', 'collected', 'return_pending']],
    'rejected'  => ['Rejected', ['rejected']],
    'returned'  => ['Returned', ['returned']],
    'cancelled' => ['Cancelled', ['cancelled']],
];
$status = get_str('status') ?: 'all';
if (!isset($tabs[$status])) $status = 'all';

$st = db()->prepare('SELECT status, COUNT(*) n FROM requests WHERE user_id = ? GROUP BY status');
$st->execute([$uid]);
$raw = $st->fetchAll(PDO::FETCH_KEY_PAIR);
$counts = [];
foreach ($tabs as $k => [, $set]) {
    $counts[$k] = $set === null ? array_sum($raw) : array_sum(array_intersect_key($raw, array_flip($set)));
}

$sql = 'SELECT r.*, d.name AS device_name, d.code, d.photo FROM requests r JOIN devices d ON d.id = r.device_id WHERE r.user_id = ?';
$params = [$uid];
if ($tabs[$status][1] !== null) {
    $sql .= ' AND r.status IN (' . implode(',', array_fill(0, count($tabs[$status][1]), '?')) . ')';
    $params = array_merge($params, $tabs[$status][1]);
}
$sql .= ' ORDER BY r.requested_at DESC';
$st = db()->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

$pageTitle = 'My Requests';
$pageSubtitle = 'Track your requests: pending, approved, rejected and returned';
$active = 'requests';
require __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <div class="tabs">
        <?php foreach ($tabs as $k => [$label]): ?>
            <a class="<?= $status === $k ? 'active' : '' ?>" href="<?= e(url('student/requests.php?status=' . $k)) ?>"><?= e($label) ?><span class="count"><?= (int)$counts[$k] ?></span></a>
        <?php endforeach; ?>
        <a class="btn btn-sm btn-sky" style="margin-left:auto;border-radius:999px;background:var(--sky);color:#fff" href="<?= e(url('student/devices.php')) ?>"><?= icon('plus') ?> New request</a>
    </div>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Device</th><th class="num">Qty</th><th class="num">Days</th><th>Reason</th><th>Requested</th><th>Status</th><th class="num">Action</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): $over = is_overdue($r); ?>
                <tr class="<?= $over ? 'row-overdue' : '' ?>">
                    <td><a class="cell-device" href="<?= e(url('student/device.php?id=' . (int)$r['device_id'])) ?>"><img src="<?= e(device_photo_url($r)) ?>" alt=""><div><strong><?= e($r['device_name']) ?></strong><small><?= e($r['code']) ?></small></div></a></td>
                    <td class="num"><?= (int)$r['quantity'] ?></td>
                    <td class="num"><?= (int)$r['days'] ?></td>
                    <td style="max-width:280px"><?= e($r['reason']) ?>
                        <?php if ($r['status'] === 'rejected' && $r['reject_reason']): ?><div class="small text-danger"><strong>Reason for rejection:</strong> <?= e($r['reject_reason']) ?></div><?php endif; ?>
                        <?php if ($r['status'] === 'approved'): ?><div class="small text-success"><strong>Approved</strong> — collect it from the lab.</div><?php endif; ?>
                    </td>
                    <td class="nowrap"><?= e(fmt_date($r['requested_at'], true)) ?></td>
                    <td><?= status_badge($r['status'], $over) ?></td>
                    <td class="num">
                        <?php if (in_array($r['status'], ['pending', 'approved'], true)): ?>
                            <form class="inline" method="post" data-confirm="Cancel this request for <?= e($r['device_name']) ?>?">
                                <?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="_status" value="<?= e($status) ?>">
                                <button class="btn btn-sm btn-outline-red" type="submit"><?= icon('x') ?> Cancel</button>
                            </form>
                        <?php elseif (in_array($r['status'], ['collected', 'return_pending'], true)): ?>
                            <a class="btn btn-sm btn-light" href="<?= e(url('student/borrowed.php')) ?>">View loan</a>
                        <?php else: ?>
                            <span class="muted small">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
                <tr><td colspan="7"><div class="empty"><?= icon('inbox') ?><h3>No requests here</h3><p class="mb-0"><a href="<?= e(url('student/devices.php')) ?>">Find a device</a> to send a request.</p></div></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
