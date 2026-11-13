<?php
require_once __DIR__ . '/../includes/functions.php';
$me = require_role('student');
$uid = (int)$me['id'];
generate_return_reminders($uid);

$st = db()->prepare("SELECT
    SUM(status = 'pending') AS pending,
    SUM(status = 'approved') AS approved,
    SUM(status IN ('collected','return_pending')) AS on_loan,
    SUM(status IN ('collected','return_pending') AND due_date < ?) AS overdue,
    SUM(status = 'returned') AS returned
    FROM requests WHERE user_id = ?");
$st->execute([now(), $uid]);
$s = array_map('intval', $st->fetch() ?: []);

$st = db()->prepare("SELECT r.*, d.name AS device_name, d.code, d.photo FROM requests r JOIN devices d ON d.id = r.device_id
                     WHERE r.user_id = ? AND r.status IN ('approved','collected','return_pending')
                     ORDER BY (r.due_date IS NULL), r.due_date LIMIT 6");
$st->execute([$uid]);
$loans = $st->fetchAll();

$st = db()->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 5');
$st->execute([$uid]);
$notes = $st->fetchAll();

$categories = all_categories();
$popular = db()->query(device_select_sql() . ' WHERE 1 ORDER BY available DESC, d.name LIMIT 4')->fetchAll();

$pageTitle = 'Hello, ' . explode(' ', trim($me['name']))[0] . '!';
$pageSubtitle = 'Student dashboard · ' . date('l, d F Y');
$active = 'dashboard';
require __DIR__ . '/../includes/header.php';
?>
<?php if (($s['overdue'] ?? 0) > 0): ?>
    <div class="alert alert-error"><?= icon('alert') ?><div><strong>You have <?= $s['overdue'] ?> overdue device<?= $s['overdue'] === 1 ? '' : 's' ?>.</strong> Please return <?= $s['overdue'] === 1 ? 'it' : 'them' ?> to the lab as soon as possible. <a href="<?= e(url('student/borrowed.php')) ?>">View borrowed devices</a></div></div>
<?php endif; ?>
<?php if (($s['approved'] ?? 0) > 0): ?>
    <div class="alert alert-info"><?= icon('bell') ?><div><?= $s['approved'] ?> request<?= $s['approved'] === 1 ? ' is' : 's are' ?> approved and ready for collection from the lab.</div></div>
<?php endif; ?>

<div class="stats">
    <a class="stat amber" href="<?= e(url('student/requests.php?status=pending')) ?>"><div class="stat-icon"><?= icon('inbox') ?></div><div><div class="stat-value"><?= $s['pending'] ?? 0 ?></div><div class="stat-label">Pending requests</div></div></a>
    <a class="stat sky" href="<?= e(url('student/requests.php?status=approved')) ?>"><div class="stat-icon"><?= icon('check') ?></div><div><div class="stat-value"><?= $s['approved'] ?? 0 ?></div><div class="stat-label">Ready to collect</div></div></a>
    <a class="stat navy" href="<?= e(url('student/borrowed.php')) ?>"><div class="stat-icon"><?= icon('package') ?></div><div><div class="stat-value"><?= $s['on_loan'] ?? 0 ?></div><div class="stat-label">Borrowed now</div></div></a>
    <a class="stat red" href="<?= e(url('student/borrowed.php')) ?>"><div class="stat-icon"><?= icon('clock') ?></div><div><div class="stat-value"><?= $s['overdue'] ?? 0 ?></div><div class="stat-label">Overdue</div></div></a>
    <a class="stat green" href="<?= e(url('student/history.php')) ?>"><div class="stat-icon"><?= icon('history') ?></div><div><div class="stat-value"><?= $s['returned'] ?? 0 ?></div><div class="stat-label">Returned</div></div></a>
</div>

<div class="card">
    <div class="card-body">
        <form class="toolbar" method="get" action="<?= e(url('student/devices.php')) ?>">
            <div class="input-icon grow"><?= icon('search') ?><input type="search" name="q" placeholder="Search for a device, e.g. Arduino, servo, ESP32…"></div>
            <select name="cat">
                <option value="0">All categories</option>
                <?php foreach ($categories as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
            </select>
            <button class="btn btn-sky" type="submit"><?= icon('search') ?> Search</button>
            <a class="btn btn-light" href="<?= e(url('student/scan.php')) ?>"><?= icon('scan') ?> Scan QR</a>
        </form>
    </div>
</div>

<div class="grid grid-2-1">
    <div class="card">
        <div class="card-head"><h2><?= icon('package') ?> My current devices</h2><div class="actions"><a class="btn btn-sm btn-light" href="<?= e(url('student/borrowed.php')) ?>">View all</a></div></div>
        <?php if ($loans): ?>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Device</th><th class="num">Qty</th><th>Due</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($loans as $r): $over = is_overdue($r); ?>
                    <tr class="<?= $over ? 'row-overdue' : '' ?>">
                        <td><div class="cell-device"><img src="<?= e(device_photo_url($r)) ?>" alt=""><div><strong><?= e($r['device_name']) ?></strong><small><?= e($r['code']) ?></small></div></div></td>
                        <td class="num"><?= (int)$r['quantity'] ?></td>
                        <td class="due nowrap"><?= $r['due_date'] ? e(fmt_date($r['due_date'], true)) . '<br><small>' . e(due_text($r)) . '</small>' : '<span class="muted">After collection</span>' ?></td>
                        <td><?= status_badge($r['status'], $over) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php else: ?>
            <div class="empty"><?= icon('package') ?><h3>No borrowed devices</h3><p>Find a device and send a request to get started.</p><a class="btn btn-sky" href="<?= e(url('student/devices.php')) ?>">Find devices</a></div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-head"><h2><?= icon('bell') ?> Notifications</h2><div class="actions"><a class="btn btn-sm btn-light" href="<?= e(url('student/notifications.php')) ?>">All</a></div></div>
        <?php if ($notes): ?>
            <?php foreach ($notes as $n): ?>
                <div class="notif <?= $n['is_read'] ? '' : 'unread' ?>">
                    <span class="n-icon <?= e($n['type']) ?>"><?= icon(['approved' => 'check', 'rejected' => 'x', 'returned' => 'check', 'reminder' => 'clock', 'overdue' => 'alert', 'collected' => 'package'][$n['type']] ?? 'bell') ?></span>
                    <div class="msg"><div class="small"><?= e($n['message']) ?></div><div class="time"><?= e(time_ago($n['created_at'])) ?></div></div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty"><?= icon('bell') ?><h3>No notifications yet</h3></div>
        <?php endif; ?>
    </div>
</div>

<div class="card mt-2">
    <div class="card-head"><h2><?= icon('cpu') ?> Available devices</h2><div class="actions"><a class="btn btn-sm btn-light" href="<?= e(url('student/devices.php')) ?>">Browse all</a></div></div>
    <div class="card-body">
        <div class="device-grid">
            <?php foreach ($popular as $d): ?>
                <a class="device-card" href="<?= e(url('student/device.php?id=' . (int)$d['id'])) ?>">
                    <div class="thumb"><img src="<?= e(device_photo_url($d)) ?>" alt=""><?= availability_badge($d) ?></div>
                    <div class="body"><span class="cat"><?= e($d['category_name'] ?? '—') ?></span><h3><?= e($d['name']) ?></h3>
                        <div class="meta"><span><?= icon('pin') ?> <?= e($d['location'] ?: '—') ?></span><span><strong class="pill-qty"><?= (int)$d['available'] ?></strong> available</span></div></div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
