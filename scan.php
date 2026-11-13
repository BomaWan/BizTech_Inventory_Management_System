<?php
/**
 * Landing page for scanned device QR tags.
 * Shows where the device is kept, how many units are available and when borrowed units are due back.
 * Administrators additionally see who currently holds each borrowed unit.
 */
require_once __DIR__ . '/includes/functions.php';

$code = get_str('code', 40);
$device = $code !== '' ? find_device_by_code($code) : null;
$me = current_user();
$isAdmin = $me && $me['role'] === 'admin';

if (!$device) {
    http_response_code(404);
}
$available = $device ? (int)$device['available'] : 0;
$holders = $device ? device_holders((int)$device['id']) : [];
$onLoan = array_values(array_filter($holders, fn($h) => in_array($h['status'], ['collected', 'return_pending'], true)));
$reserved = array_values(array_filter($holders, fn($h) => $h['status'] === 'approved'));
$nextBack = null;
foreach ($onLoan as $h) {
    if ($h['due_date'] && strtotime($h['due_date']) >= time() && ($nextBack === null || $h['due_date'] < $nextBack)) {
        $nextBack = $h['due_date'];
    }
}
$overdueUnits = array_sum(array_map(fn($h) => is_overdue($h) ? (int)$h['quantity'] : 0, $onLoan));
$loanUnits = array_sum(array_map(fn($h) => (int)$h['quantity'], $onLoan));
$reservedUnits = array_sum(array_map(fn($h) => (int)$h['quantity'], $reserved));
$outOfService = $device ? (int)$device['quantity_damaged'] + (int)$device['quantity_repair'] : 0;
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $device ? e($device['name']) . ' · Location & availability' : 'Device not found' ?> · <?= e(APP_NAME) ?></title>
    <link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
</head>
<body>
<nav class="pub-nav">
    <a href="<?= e(url('index.php')) ?>"><img src="<?= e(asset('img/logo.svg')) ?>" alt="LearnX"></a>
    <div class="links">
        <?php if ($me): ?>
            <a class="btn" href="<?= e(url($isAdmin ? 'admin/index.php' : 'student/index.php')) ?>"><?= icon('grid') ?> Dashboard</a>
        <?php else: ?>
            <a class="btn" href="<?= e(url('login.php')) ?>"><?= icon('lock') ?> Login</a>
        <?php endif; ?>
    </div>
</nav>

<div class="scan-page">
<?php if (!$device): ?>
    <div class="card"><div class="empty">
        <?= icon('qr') ?>
        <h3>Device not found</h3>
        <p>This QR tag doesn't match any device in the inventory. The device may have been removed, or its tag replaced with a new one.</p>
        <a class="btn" href="<?= e(url('index.php')) ?>">Go to home page</a>
    </div></div>
<?php else: ?>
    <div class="scan-hero">
        <img src="<?= e(device_photo_url($device)) ?>" alt="">
        <div>
            <div class="cat"><?= e($device['category_name'] ?? 'Uncategorised') ?> · <?= e($device['code']) ?></div>
            <h1><?= e($device['name']) ?></h1>
            <div><?= availability_badge($device) ?></div>
        </div>
    </div>

    <div class="locate-card">
        <div class="locate-icon"><?= icon('pin') ?></div>
        <div>
            <div class="locate-label">Where to find it</div>
            <div class="locate-value"><?= e($device['location'] ?: 'Location not recorded. Ask the lab administrator.') ?></div>
            <div class="locate-sub">This is where the device is kept in the lab<?= $available > 0 ? '. Available units should be here.' : '.' ?></div>
        </div>
    </div>

    <div class="card mt-2">
        <div class="scan-count">
            <div class="muted">Units available right now</div>
            <div class="n <?= $available === 0 ? 'zero' : '' ?>"><?= $available ?></div>
            <div class="muted">out of <?= (int)$device['quantity_total'] ?> in total</div>
        </div>
        <div class="card-body" style="border-top:1px solid var(--line)">
            <div class="kv scan-kv" style="margin:0">
                <div><span>In the lab</span><strong><?= $available ?></strong></div>
                <div><span>On loan</span><strong><?= $loanUnits ?></strong></div>
                <div><span>Reserved</span><strong><?= $reservedUnits ?></strong></div>
                <div><span>Damaged / repair</span><strong><?= $outOfService ?></strong></div>
            </div>
            <?php if ($loanUnits > 0 || $reservedUnits > 0): ?>
                <div class="alert <?= $overdueUnits ? 'alert-error' : 'alert-info' ?> mt-2 mb-0"><?= icon($overdueUnits ? 'alert' : 'clock') ?><div>
                    <?php if ($loanUnits): ?>
                        <strong><?= $loanUnits ?> unit<?= $loanUnits === 1 ? ' is' : 's are' ?> on loan.</strong>
                        <?php if ($nextBack): ?>The next one is due back on <strong><?= e(fmt_date($nextBack, true)) ?></strong>.<?php endif; ?>
                        <?php if ($overdueUnits): ?><?= $overdueUnits ?> unit<?= $overdueUnits === 1 ? ' is' : 's are' ?> overdue.<?php endif; ?>
                    <?php endif; ?>
                    <?php if ($reservedUnits): ?><?= $reservedUnits ?> unit<?= $reservedUnits === 1 ? ' is' : 's are' ?> approved and waiting to be collected.<?php endif; ?>
                </div></div>
            <?php endif; ?>
        </div>

        <?php if ($isAdmin && $holders): ?>
            <div class="card-head" style="border-top:1px solid var(--line)"><h3><?= icon('users') ?> Who has the other units</h3><span class="badge badge-muted">Admin only</span></div>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Student</th><th>Contact</th><th class="num">Qty</th><th>Status</th><th>Due back</th></tr></thead>
                <tbody>
                <?php foreach ($holders as $h): $over = is_overdue($h); ?>
                    <tr class="<?= $over ? 'row-overdue' : '' ?>">
                        <td><strong style="color:var(--navy)"><?= e($h['user_name']) ?></strong><br><small class="muted"><?= e($h['student_id'] ?? '') ?></small></td>
                        <td class="small"><?= e($h['phone'] ?: '') ?><?= $h['phone'] ? '<br>' : '' ?><?= e($h['email']) ?></td>
                        <td class="num"><?= (int)$h['quantity'] ?></td>
                        <td><?= $h['status'] === 'approved' ? '<span class="badge badge-info">Awaiting collection</span>' : status_badge($h['status'], $over) ?></td>
                        <td class="due nowrap"><?= $h['due_date'] ? e(fmt_date($h['due_date'], true)) . '<br><small>' . e(due_text($h)) . '</small>' : '<span class="muted">Not collected yet</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>

        <?php $credit = photo_credit_html($device); if ($device['description'] || $credit): ?>
            <div class="card-body" style="border-top:1px solid var(--line)">
                <?php if ($device['description']): ?><p class="mb-0"><?= nl2br(e($device['description'])) ?></p><?php endif; ?>
                <?= $credit ?>
            </div>
        <?php endif; ?>
        <div class="card-body" style="border-top:1px solid var(--line);display:flex;gap:10px;flex-wrap:wrap">
            <?php if ($me && $me['role'] === 'student'): ?>
                <a class="btn btn-sky" href="<?= e(url('student/device.php?id=' . (int)$device['id'])) ?>"><?= icon('inbox') ?> Request this device</a>
            <?php elseif ($isAdmin): ?>
                <a class="btn" href="<?= e(url('admin/devices.php?q=' . rawurlencode($device['code']))) ?>"><?= icon('edit') ?> Manage this device</a>
                <a class="btn btn-light" href="<?= e(url('admin/borrowing.php')) ?>"><?= icon('repeat') ?> Borrowing &amp; returns</a>
            <?php else: ?>
                <a class="btn btn-sky" href="<?= e(url('login.php?role=student&next=' . rawurlencode('student/device.php?id=' . (int)$device['id']))) ?>"><?= icon('lock') ?> Log in to request this device</a>
                <a class="btn btn-light" href="<?= e(url('login.php?role=admin&next=' . rawurlencode('scan.php?code=' . $device['code']))) ?>"><?= icon('shield') ?> Admin: see who has it</a>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
</div>
</body>
</html>
