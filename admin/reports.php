<?php
require_once __DIR__ . '/../includes/functions.php';
$me = require_role('admin');

$borrowedStatuses = "('collected','return_pending','returned')";
$monthStart = date('Y-m-01 00:00:00');
$nextMonth = date('Y-m-01 00:00:00', strtotime('first day of next month'));

// Most borrowed devices (all time, by units collected)
$most = db()->query("SELECT d.id, d.name, c.name AS category_name, COUNT(r.id) AS times, CAST(SUM(r.quantity) AS SIGNED) AS units
                     FROM requests r JOIN devices d ON d.id = r.device_id LEFT JOIN categories c ON c.id = d.category_id
                     WHERE r.status IN $borrowedStatuses
                     GROUP BY d.id ORDER BY units DESC, times DESC LIMIT 10")->fetchAll();
$maxUnits = $most ? max(array_map(fn($r) => (int)$r['units'], $most)) : 1;

// Borrowed this month
$st = db()->prepare("SELECT r.*, u.name AS user_name, u.student_id, d.name AS device_name, d.code
                     FROM requests r JOIN users u ON u.id = r.user_id JOIN devices d ON d.id = r.device_id
                     WHERE r.status IN $borrowedStatuses AND r.collected_at >= ? AND r.collected_at < ?
                     ORDER BY r.collected_at DESC");
$st->execute([$monthStart, $nextMonth]);
$thisMonth = $st->fetchAll();
$unitsThisMonth = array_sum(array_map(fn($r) => (int)$r['quantity'], $thisMonth));

// Borrowing per category this month
$st = db()->prepare("SELECT COALESCE(c.name,'Uncategorised') AS name, CAST(SUM(r.quantity) AS SIGNED) AS units
                     FROM requests r JOIN devices d ON d.id = r.device_id LEFT JOIN categories c ON c.id = d.category_id
                     WHERE r.status IN $borrowedStatuses AND r.collected_at >= ? AND r.collected_at < ?
                     GROUP BY c.id ORDER BY units DESC");
$st->execute([$monthStart, $nextMonth]);
$byCat = $st->fetchAll();
$maxCat = $byCat ? max(array_map(fn($r) => (int)$r['units'], $byCat)) : 1;

// Low stock
$devices = db()->query(device_select_sql() . ' ORDER BY d.name')->fetchAll();
$low = array_values(array_filter($devices, fn($d) => (int)$d['available'] <= (int)$d['low_stock_threshold']));
usort($low, fn($a, $b) => (int)$a['available'] <=> (int)$b['available']);

// Request stats this month
$st = db()->prepare("SELECT status, COUNT(*) n FROM requests WHERE requested_at >= ? AND requested_at < ? GROUP BY status");
$st->execute([$monthStart, $nextMonth]);
$reqStats = $st->fetchAll(PDO::FETCH_KEY_PAIR);
$reqTotal = array_sum($reqStats);

$pageTitle = 'Reports';
$pageSubtitle = 'Low-stock alerts, most borrowed devices and borrowing this month (' . date('F Y') . ')';
$active = 'reports';
require __DIR__ . '/../includes/header.php';
?>
<div class="stats">
    <div class="stat sky"><div class="stat-icon"><?= icon('repeat') ?></div><div><div class="stat-value"><?= count($thisMonth) ?></div><div class="stat-label">Loans this month</div><div class="stat-sub"><?= $unitsThisMonth ?> units borrowed</div></div></div>
    <div class="stat navy"><div class="stat-icon"><?= icon('inbox') ?></div><div><div class="stat-value"><?= $reqTotal ?></div><div class="stat-label">Requests this month</div><div class="stat-sub"><?= (int)($reqStats['rejected'] ?? 0) ?> rejected</div></div></div>
    <div class="stat red"><div class="stat-icon"><?= icon('alert') ?></div><div><div class="stat-value"><?= count($low) ?></div><div class="stat-label">Low-stock devices</div><div class="stat-sub">At or below alert level</div></div></div>
    <div class="stat amber"><div class="stat-icon"><?= icon('chart') ?></div><div><div class="stat-value"><?= $most ? e($most[0]['units']) : 0 ?></div><div class="stat-label">Top device units</div><div class="stat-sub"><?= $most ? e($most[0]['name']) : 'No loans yet' ?></div></div></div>
</div>

<div class="grid grid-2">
    <div class="card">
        <div class="card-head"><h2><?= icon('chart') ?> Most borrowed devices</h2><div class="actions"><span class="muted small">All time</span></div></div>
        <div class="card-body">
            <?php if ($most): ?>
                <div class="bar-list">
                    <?php foreach ($most as $i => $r): ?>
                        <div class="bar-row">
                            <div class="top"><span><strong><?= $i + 1 ?>. <?= e($r['name']) ?></strong> <span class="muted small"><?= e($r['category_name'] ?? '') ?></span></span><span class="nowrap"><strong><?= (int)$r['units'] ?></strong> units · <?= (int)$r['times'] ?> loan<?= $r['times'] == 1 ? '' : 's' ?></span></div>
                            <div class="meter"><i style="width:<?= round((int)$r['units'] / max(1, $maxUnits) * 100) ?>%"></i></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty"><?= icon('chart') ?><h3>No borrowing data yet</h3><p class="mb-0">Data appears once devices are collected.</p></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><h2><?= icon('alert') ?> Low stock alert</h2></div>
        <?php if ($low): ?>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Device</th><th class="num">Available</th><th class="num">Alert at</th><th style="width:30%">Level</th></tr></thead>
                <tbody>
                <?php foreach ($low as $d): $pct = (int)$d['quantity_total'] ? round((int)$d['available'] / (int)$d['quantity_total'] * 100) : 0; ?>
                    <tr>
                        <td><strong style="color:var(--navy)"><?= e($d['name']) ?></strong><br><small class="muted"><?= e($d['category_name'] ?? '') ?></small></td>
                        <td class="num <?= (int)$d['available'] === 0 ? 'text-danger' : '' ?>"><strong><?= (int)$d['available'] ?></strong> / <?= (int)$d['quantity_total'] ?></td>
                        <td class="num"><?= (int)$d['low_stock_threshold'] ?></td>
                        <td><div class="meter red"><i style="width:<?= $pct ?>%"></i></div></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php else: ?>
            <div class="empty"><?= icon('check') ?><h3>All devices are above their alert level</h3></div>
        <?php endif; ?>
    </div>
</div>

<div class="grid grid-2-1 mt-2">
    <div class="card">
        <div class="card-head"><h2><?= icon('calendar') ?> Borrowed this month</h2><div class="actions"><button class="btn btn-sm btn-light" type="button" onclick="window.print()"><?= icon('printer') ?> Print report</button></div></div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Date collected</th><th>Student</th><th>Device</th><th class="num">Qty</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($thisMonth as $r): $over = is_overdue($r); ?>
                <tr class="<?= $over ? 'row-overdue' : '' ?>">
                    <td class="nowrap"><?= e(fmt_date($r['collected_at'], true)) ?></td>
                    <td><?= e($r['user_name']) ?> <span class="muted small"><?= e($r['student_id'] ?? '') ?></span></td>
                    <td><?= e($r['device_name']) ?></td>
                    <td class="num"><?= (int)$r['quantity'] ?></td>
                    <td><?= status_badge($r['status'], $over) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$thisMonth): ?>
                <tr><td colspan="5"><div class="empty"><?= icon('calendar') ?><h3>No devices borrowed this month yet</h3></div></td></tr>
            <?php endif; ?>
            </tbody>
        </table></div>
    </div>
    <div class="card">
        <div class="card-head"><h2><?= icon('tag') ?> This month by category</h2></div>
        <div class="card-body">
            <?php if ($byCat): ?>
                <div class="bar-list">
                    <?php foreach ($byCat as $r): ?>
                        <div class="bar-row">
                            <div class="top"><strong><?= e($r['name']) ?></strong><span><?= (int)$r['units'] ?> units</span></div>
                            <div class="meter"><i style="width:<?= round((int)$r['units'] / max(1, $maxCat) * 100) ?>%"></i></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="muted mb-0">No data for <?= e(date('F Y')) ?>.</p>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
