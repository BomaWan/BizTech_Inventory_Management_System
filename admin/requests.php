<?php
require_once __DIR__ . '/../includes/functions.php';
$me = require_role('admin');

$statuses = [
    'all'       => 'All',
    'pending'   => 'Pending',
    'approved'  => 'Approved',
    'collected' => 'Borrowed',
    'returned'  => 'Returned',
    'rejected'  => 'Rejected',
    'cancelled' => 'Cancelled',
];

if (is_post()) {
    verify_csrf();
    $action = post_str('action');
    $id = post_int('id');
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $st = $pdo->prepare('SELECT r.*, d.name AS device_name FROM requests r JOIN devices d ON d.id = r.device_id WHERE r.id = ? FOR UPDATE');
        $st->execute([$id]);
        $r = $st->fetch();
        if (!$r) throw new RuntimeException('Request not found.');
        if ($r['status'] !== 'pending') throw new RuntimeException('This request has already been processed.');

        if ($action === 'approve') {
            $pdo->prepare('SELECT id FROM devices WHERE id = ? FOR UPDATE')->execute([$r['device_id']]);
            $d = find_device((int)$r['device_id']);
            if ((int)$d['available'] < (int)$r['quantity']) {
                throw new RuntimeException("Cannot approve: only {$d['available']} unit(s) of \"{$d['name']}\" are available, but {$r['quantity']} were requested.");
            }
            $pdo->prepare("UPDATE requests SET status='approved', decided_at=?, decided_by=? WHERE id=?")->execute([now(), $me['id'], $id]);
            notify((int)$r['user_id'], 'approved', "Your request for \"{$r['device_name']}\" × {$r['quantity']} was approved. Please collect it from the lab.", $id);
            $msg = "Request #$id approved.";
        } elseif ($action === 'reject') {
            $reason = post_str('reject_reason', 1000);
            if ($reason === '') throw new RuntimeException('Please give a reason for rejecting the request.');
            $pdo->prepare("UPDATE requests SET status='rejected', reject_reason=?, decided_at=?, decided_by=? WHERE id=?")->execute([$reason, now(), $me['id'], $id]);
            notify((int)$r['user_id'], 'rejected', "Your request for \"{$r['device_name']}\" was rejected. Reason: $reason", $id);
            $msg = "Request #$id rejected.";
        } else {
            throw new RuntimeException('Unknown action.');
        }
        $pdo->commit();
        flash('success', $msg);
    } catch (RuntimeException $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', $ex->getMessage());
    }
    redirect('admin/requests.php?status=' . rawurlencode(post_str('_status') ?: 'pending'));
}

$status = get_str('status') ?: 'all';
if (!isset($statuses[$status])) $status = 'all';
$q = get_str('q');

$counts = ['all' => 0];
foreach (db()->query('SELECT status, COUNT(*) n FROM requests GROUP BY status') as $row) {
    $key = $row['status'] === 'return_pending' ? 'collected' : $row['status'];
    $counts[$key] = ($counts[$key] ?? 0) + (int)$row['n'];
    $counts['all'] += (int)$row['n'];
}

$where = [];
$params = [];
if ($status === 'collected') {
    $where[] = "r.status IN ('collected','return_pending')";
} elseif ($status !== 'all') {
    $where[] = 'r.status = ?';
    $params[] = $status;
}
if ($q !== '') {
    $where[] = '(u.name LIKE ? OR u.student_id LIKE ? OR d.name LIKE ?)';
    array_push($params, "%$q%", "%$q%", "%$q%");
}
$sql = "SELECT r.*, u.name AS user_name, u.student_id, u.course, d.name AS device_name, d.code, d.photo, a.name AS admin_name
        FROM requests r JOIN users u ON u.id = r.user_id JOIN devices d ON d.id = r.device_id
        LEFT JOIN users a ON a.id = r.decided_by"
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
    . " ORDER BY (r.status = 'pending') DESC, r.requested_at DESC LIMIT 500";
$st = db()->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

// availability lookup for pending rows
$availability = [];
foreach (db()->query(device_select_sql())->fetchAll() as $d) {
    $availability[(int)$d['id']] = (int)$d['available'];
}

$pageTitle = 'Manage Requests';
$pageSubtitle = 'Review, approve or reject device borrowing requests';
$active = 'requests';
require __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <div class="tabs">
        <?php foreach ($statuses as $k => $label): ?>
            <a class="<?= $status === $k ? 'active' : '' ?>" href="<?= e(url('admin/requests.php?status=' . $k)) ?>"><?= e($label) ?><span class="count"><?= (int)($counts[$k] ?? 0) ?></span></a>
        <?php endforeach; ?>
    </div>
    <div class="card-head">
        <form class="toolbar" method="get" style="flex:1">
            <input type="hidden" name="status" value="<?= e($status) ?>">
            <div class="input-icon grow"><?= icon('search') ?><input type="search" name="q" value="<?= e($q) ?>" placeholder="Search by student name, student ID or device"></div>
            <button class="btn btn-light" type="submit">Search</button>
        </form>
    </div>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>#</th><th>Student</th><th>Device</th><th class="num">Qty</th><th class="num">Days</th><th>Reason</th><th>Requested</th><th>Status</th><th class="num">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): $over = is_overdue($r); ?>
                <tr class="<?= $over ? 'row-overdue' : '' ?>">
                    <td class="muted">#<?= (int)$r['id'] ?></td>
                    <td><strong style="color:var(--navy)"><?= e($r['user_name']) ?></strong><br><small class="muted"><?= e($r['student_id'] ?? '') ?></small></td>
                    <td><div class="cell-device"><img src="<?= e(device_photo_url($r)) ?>" alt=""><div><strong><?= e($r['device_name']) ?></strong><small><?= e($r['code']) ?></small></div></div></td>
                    <td class="num"><?= (int)$r['quantity'] ?></td>
                    <td class="num"><?= (int)$r['days'] ?></td>
                    <td style="max-width:260px">
                        <?= e($r['reason']) ?>
                        <?php if ($r['status'] === 'rejected' && $r['reject_reason']): ?>
                            <div class="small text-danger mt-0"><strong>Rejected:</strong> <?= e($r['reject_reason']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="nowrap"><?= e(fmt_date($r['requested_at'], true)) ?><?php if ($r['admin_name']): ?><br><small class="muted">by <?= e($r['admin_name']) ?></small><?php endif; ?></td>
                    <td><?= status_badge($r['status'], $over) ?></td>
                    <td class="num">
                        <?php if ($r['status'] === 'pending'):
                            $av = $availability[(int)$r['device_id']] ?? 0; ?>
                            <div class="btn-group" style="justify-content:flex-end">
                                <form class="inline" method="post">
                                    <?= csrf_field() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="_status" value="<?= e($status) ?>">
                                    <button class="btn btn-sm btn-success" type="submit" <?= $av < (int)$r['quantity'] ? 'disabled title="Not enough units available"' : '' ?>><?= icon('check') ?> Approve</button>
                                </form>
                                <button class="btn btn-sm btn-outline-red" type="button" data-modal="reject-modal" data-fill='<?= json_encode(['id' => (int)$r['id'], 'who' => $r['user_name'] . ' — ' . $r['device_name']], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>'><?= icon('x') ?> Reject</button>
                            </div>
                            <div class="small <?= $av < (int)$r['quantity'] ? 'text-danger' : 'muted' ?>" style="margin-top:4px"><?= $av ?> available</div>
                        <?php elseif (in_array($r['status'], ['approved', 'collected', 'return_pending'], true)): ?>
                            <a class="btn btn-sm btn-light" href="<?= e(url('admin/borrowing.php')) ?>"><?= icon('repeat') ?> Borrowing</a>
                        <?php else: ?>
                            <span class="muted small"><?= e(fmt_date($r['decided_at'] ?? $r['returned_at'])) ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
                <tr><td colspan="9"><div class="empty"><?= icon('inbox') ?><h3>No requests found</h3></div></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<dialog class="modal" id="reject-modal">
    <form method="post">
        <div class="modal-head"><h3>Reject request</h3><button class="icon-btn" type="button" data-close aria-label="Close"><?= icon('x') ?></button></div>
        <div class="modal-body">
            <?= csrf_field() ?><input type="hidden" name="action" value="reject"><input type="hidden" name="id" value="0"><input type="hidden" name="_status" value="<?= e($status) ?>">
            <p class="mb-2"><strong data-text="who"></strong></p>
            <div class="field mb-0"><label for="rj">Reason for rejection</label><textarea id="rj" name="reject_reason" required maxlength="1000" placeholder="e.g. Device reserved for a practical exam this week."></textarea><span class="hint">The student will see this reason in their notifications.</span></div>
        </div>
        <div class="modal-foot"><button class="btn btn-light" type="button" data-close>Cancel</button><button class="btn btn-red" type="submit"><?= icon('x') ?> Reject request</button></div>
    </form>
</dialog>
<?php require __DIR__ . '/../includes/footer.php'; ?>
