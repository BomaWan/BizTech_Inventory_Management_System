<?php
require_once __DIR__ . '/../includes/functions.php';
$me = require_role('student');
$uid = (int)$me['id'];
generate_return_reminders($uid);

$st = db()->prepare("SELECT r.*, d.name AS device_name, d.code, d.photo, c.name AS category_name
                     FROM requests r JOIN devices d ON d.id = r.device_id LEFT JOIN categories c ON c.id = d.category_id
                     WHERE r.user_id = ? AND r.status = 'returned' ORDER BY r.returned_at DESC");
$st->execute([$uid]);
$rows = $st->fetchAll();
$units = array_sum(array_map(fn($r) => (int)$r['quantity'], $rows));
$late = count(array_filter($rows, fn($r) => $r['due_date'] && strtotime($r['returned_at']) > strtotime($r['due_date'])));

$pageTitle = 'Borrowing History';
$pageSubtitle = 'All devices you have borrowed and returned';
$active = 'history';
require __DIR__ . '/../includes/header.php';
?>
<div class="stats">
    <div class="stat navy"><div class="stat-icon"><?= icon('history') ?></div><div><div class="stat-value"><?= count($rows) ?></div><div class="stat-label">Completed loans</div></div></div>
    <div class="stat sky"><div class="stat-icon"><?= icon('package') ?></div><div><div class="stat-value"><?= $units ?></div><div class="stat-label">Units borrowed</div></div></div>
    <div class="stat <?= $late ? 'red' : 'green' ?>"><div class="stat-icon"><?= icon('clock') ?></div><div><div class="stat-value"><?= $late ?></div><div class="stat-label">Returned late</div></div></div>
</div>
<div class="card">
    <div class="card-head">
        <h2><?= icon('history') ?> Previously borrowed devices</h2>
        <div class="actions"><div class="input-icon"><?= icon('search') ?><input type="search" placeholder="Filter…" data-filter="#hist-rows"></div></div>
    </div>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Device</th><th>Category</th><th class="num">Qty</th><th>Borrowed</th><th>Due</th><th>Returned</th><th>Condition</th></tr></thead>
            <tbody id="hist-rows">
            <?php foreach ($rows as $r): $wasLate = $r['due_date'] && strtotime($r['returned_at']) > strtotime($r['due_date']); ?>
                <tr data-search="<?= e($r['device_name'] . ' ' . $r['code'] . ' ' . $r['category_name']) ?>">
                    <td><a class="cell-device" href="<?= e(url('student/device.php?id=' . (int)$r['device_id'])) ?>"><img src="<?= e(device_photo_url($r)) ?>" alt=""><div><strong><?= e($r['device_name']) ?></strong><small><?= e($r['code']) ?></small></div></a></td>
                    <td><?= e($r['category_name'] ?? '—') ?></td>
                    <td class="num"><?= (int)$r['quantity'] ?></td>
                    <td class="nowrap"><?= e(fmt_date($r['collected_at'])) ?></td>
                    <td class="nowrap"><?= e(fmt_date($r['due_date'])) ?></td>
                    <td class="nowrap"><?= e(fmt_date($r['returned_at'])) ?> <?= $wasLate ? '<span class="badge badge-danger">Late</span>' : '<span class="badge badge-success">On time</span>' ?></td>
                    <td><?= $r['return_condition'] === 'damaged' ? '<span class="badge badge-danger">Damaged</span>' : '<span class="badge badge-success">Good</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
                <tr><td colspan="7"><div class="empty"><?= icon('history') ?><h3>No history yet</h3><p class="mb-0">Devices you return will be listed here.</p></div></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
