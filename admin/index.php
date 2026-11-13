<?php
require_once __DIR__ . '/../includes/functions.php';
$me = require_role('admin');

$devices = db()->query(device_select_sql() . ' ORDER BY d.name')->fetchAll();
$categories = all_categories();

$totalUnits = $totalAvail = $totalBorrowed = $totalReserved = $totalOut = 0;
foreach ($devices as $d) {
    $totalUnits    += (int)$d['quantity_total'];
    $totalAvail    += (int)$d['available'];
    $totalBorrowed += (int)$d['borrowed_qty'];
    $totalReserved += (int)$d['reserved_qty'];
    $totalOut      += (int)$d['quantity_damaged'] + (int)$d['quantity_repair'];
}
$totalStudents = (int)db()->query("SELECT COUNT(*) FROM users WHERE role = 'student'")->fetchColumn();
$pending = pending_request_count();
$overdueN = overdue_count();

// Category filter
$cat = get_int('cat');
$filtered = $cat ? array_values(array_filter($devices, fn($d) => (int)$d['category_id'] === $cat)) : $devices;
$catAvail = [];
foreach ($devices as $d) {
    $k = (int)$d['category_id'];
    $catAvail[$k] = ($catAvail[$k] ?? 0) + (int)$d['available'];
}

$lowStock = array_values(array_filter($devices, fn($d) => (int)$d['available'] <= (int)$d['low_stock_threshold']));
usort($lowStock, fn($a, $b) => (int)$a['available'] <=> (int)$b['available']);

$st = db()->prepare("SELECT r.*, u.name AS user_name, u.student_id, d.name AS device_name
                     FROM requests r JOIN users u ON u.id = r.user_id JOIN devices d ON d.id = r.device_id
                     WHERE r.status IN ('collected','return_pending') AND r.due_date < ? ORDER BY r.due_date LIMIT 6");
$st->execute([now()]);
$overdueRows = $st->fetchAll();

$recent = db()->query("SELECT r.*, u.name AS user_name, u.student_id, d.name AS device_name
                       FROM requests r JOIN users u ON u.id = r.user_id JOIN devices d ON d.id = r.device_id
                       WHERE r.status = 'pending' ORDER BY r.requested_at DESC LIMIT 6")->fetchAll();

$pageTitle = 'Admin Dashboard';
$pageSubtitle = 'Overview of the Robotics & IoT inventory · ' . date('l, d F Y');
$active = 'dashboard';
require __DIR__ . '/../includes/header.php';
?>
<div class="stats">
    <a class="stat navy" href="<?= e(url('admin/devices.php')) ?>">
        <div class="stat-icon"><?= icon('cpu') ?></div>
        <div><div class="stat-value"><?= $totalUnits ?></div><div class="stat-label">Total Devices</div><div class="stat-sub"><?= count($devices) ?> device types</div></div>
    </a>
    <div class="stat sky">
        <div class="stat-icon"><?= icon('package') ?></div>
        <div><div class="stat-value"><?= $totalAvail ?></div><div class="stat-label">Total Available</div><div class="stat-sub"><?= $totalOut ?> damaged / under repair</div></div>
    </div>
    <a class="stat amber" href="<?= e(url('admin/borrowing.php')) ?>">
        <div class="stat-icon"><?= icon('repeat') ?></div>
        <div><div class="stat-value"><?= $totalBorrowed ?></div><div class="stat-label">Total Borrowed</div><div class="stat-sub"><?= $totalReserved ?> approved, awaiting collection</div></div>
    </a>
    <a class="stat green" href="<?= e(url('admin/users.php')) ?>">
        <div class="stat-icon"><?= icon('users') ?></div>
        <div><div class="stat-value"><?= $totalStudents ?></div><div class="stat-label">Total Students</div><div class="stat-sub">Registered accounts</div></div>
    </a>
    <a class="stat red" href="<?= e(url('admin/requests.php?status=pending')) ?>">
        <div class="stat-icon"><?= icon('inbox') ?></div>
        <div><div class="stat-value"><?= $pending ?></div><div class="stat-label">Pending Requests</div><div class="stat-sub"><?= $overdueN ?> overdue loan<?= $overdueN === 1 ? '' : 's' ?></div></div>
    </a>
</div>

<?php if ($overdueN > 0): ?>
    <div class="alert alert-error"><?= icon('alert') ?><div><strong><?= $overdueN ?> borrowed item<?= $overdueN === 1 ? ' is' : 's are' ?> overdue.</strong> <a href="<?= e(url('admin/borrowing.php?view=overdue')) ?>">Review overdue loans</a></div></div>
<?php endif; ?>
<?php if ($lowStock): ?>
    <div class="alert alert-warning"><?= icon('alert') ?><div><strong>Low stock alert:</strong> <?= count($lowStock) ?> device<?= count($lowStock) === 1 ? ' is' : 's are' ?> at or below the low-stock level. <a href="<?= e(url('admin/reports.php')) ?>">View report</a></div></div>
<?php endif; ?>

<div class="card">
    <div class="card-head">
        <h2><?= icon('package') ?> Equipment availability by category</h2>
        <div class="actions"><a class="btn btn-sm btn-light" href="<?= e(url('admin/devices.php')) ?>">Manage devices</a></div>
    </div>
    <div class="card-body" style="padding-bottom:0">
        <div class="chips">
            <a class="chip <?= $cat === 0 ? 'active' : '' ?>" href="<?= e(url('admin/index.php')) ?>">All <span class="count"><?= $totalAvail ?></span></a>
            <?php foreach ($categories as $c): ?>
                <a class="chip <?= $cat === (int)$c['id'] ? 'active' : '' ?>" href="<?= e(url('admin/index.php?cat=' . (int)$c['id'])) ?>"><?= e($c['name']) ?> <span class="count"><?= (int)($catAvail[(int)$c['id']] ?? 0) ?></span></a>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="card-body p0 mt-2">
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Device</th><th>Category</th><th class="num">Total</th><th class="num">Available</th><th class="num">Borrowed</th><th class="num">Damaged / Repair</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($filtered as $d): ?>
                    <tr>
                        <td><div class="cell-device"><img src="<?= e(device_photo_url($d)) ?>" alt=""><div><strong><?= e($d['name']) ?></strong><small><?= e($d['code']) ?></small></div></div></td>
                        <td><?= e($d['category_name'] ?? '—') ?></td>
                        <td class="num"><?= (int)$d['quantity_total'] ?></td>
                        <td class="num pill-qty"><?= (int)$d['available'] ?></td>
                        <td class="num"><?= (int)$d['borrowed_qty'] + (int)$d['reserved_qty'] ?></td>
                        <td class="num"><?= (int)$d['quantity_damaged'] ?> / <?= (int)$d['quantity_repair'] ?></td>
                        <td><?= availability_badge($d) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$filtered): ?>
                    <tr><td colspan="7"><div class="empty"><?= icon('cpu') ?><h3>No devices in this category</h3></div></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="grid grid-2">
    <div class="card">
        <div class="card-head"><h2><?= icon('inbox') ?> Latest pending requests</h2>
            <div class="actions"><a class="btn btn-sm btn-light" href="<?= e(url('admin/requests.php?status=pending')) ?>">View all</a></div></div>
        <?php if ($recent): ?>
            <ul class="list">
                <?php foreach ($recent as $r): ?>
                    <li>
                        <span class="avatar"><?= e(initials($r['user_name'])) ?></span>
                        <div class="grow">
                            <div class="title"><?= e($r['device_name']) ?> × <?= (int)$r['quantity'] ?></div>
                            <div class="sub"><?= e($r['user_name']) ?> (<?= e($r['student_id'] ?? '') ?>) · <?= (int)$r['days'] ?> day<?= $r['days'] == 1 ? '' : 's' ?> · <?= e(time_ago($r['requested_at'])) ?></div>
                        </div>
                        <a class="btn btn-sm btn-sky" href="<?= e(url('admin/requests.php?status=pending')) ?>">Review</a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <div class="empty"><?= icon('check') ?><h3>No pending requests</h3><p class="mb-0">All caught up.</p></div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-head"><h2><?= icon('alert') ?> Low stock alert</h2>
            <div class="actions"><a class="btn btn-sm btn-light" href="<?= e(url('admin/reports.php')) ?>">Reports</a></div></div>
        <?php if ($lowStock): ?>
            <ul class="list">
                <?php foreach (array_slice($lowStock, 0, 6) as $d): ?>
                    <li>
                        <div class="grow">
                            <div class="title"><?= e($d['name']) ?></div>
                            <div class="sub"><?= e($d['category_name'] ?? '—') ?> · alert level <?= (int)$d['low_stock_threshold'] ?></div>
                        </div>
                        <strong class="<?= (int)$d['available'] === 0 ? 'text-danger' : '' ?>"><?= (int)$d['available'] ?> / <?= (int)$d['quantity_total'] ?></strong>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <div class="empty"><?= icon('check') ?><h3>Stock levels are healthy</h3></div>
        <?php endif; ?>
    </div>
</div>

<?php if ($overdueRows): ?>
<div class="card mt-2">
    <div class="card-head"><h2 class="text-danger"><?= icon('clock') ?> Overdue loans</h2>
        <div class="actions"><a class="btn btn-sm btn-outline-red" href="<?= e(url('admin/borrowing.php?view=overdue')) ?>">Manage</a></div></div>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Student</th><th>Device</th><th class="num">Qty</th><th>Due date</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($overdueRows as $r): ?>
                <tr class="row-overdue">
                    <td><?= e($r['user_name']) ?> <span class="muted small"><?= e($r['student_id'] ?? '') ?></span></td>
                    <td><?= e($r['device_name']) ?></td>
                    <td class="num"><?= (int)$r['quantity'] ?></td>
                    <td class="due"><?= e(fmt_date($r['due_date'], true)) ?><br><small><?= e(due_text($r)) ?></small></td>
                    <td><?= status_badge($r['status'], true) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
