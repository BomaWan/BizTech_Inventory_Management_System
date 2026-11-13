<?php
require_once __DIR__ . '/../includes/functions.php';
$me = require_role('student');
$uid = (int)$me['id'];
generate_return_reminders($uid);

$id = get_int('id') ?: post_int('device_id');
$code = get_str('code', 40);
$device = $id ? find_device($id) : ($code !== '' ? find_device_by_code($code) : null);

if (!$device) {
    flash('error', $code !== '' ? "No device matches the scanned code \"$code\"." : 'Device not found.');
    redirect($code !== '' ? 'student/scan.php' : 'student/devices.php');
}
$id = (int)$device['id'];

$old = ['quantity' => 1, 'days' => 7, 'reason' => ''];
$errors = [];

if (is_post()) {
    verify_csrf();
    $old['quantity'] = post_int('quantity');
    $old['days'] = post_int('days');
    $old['reason'] = post_str('reason', 1000);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('SELECT id FROM devices WHERE id = ? FOR UPDATE')->execute([$id]);
        $device = find_device($id);
        $available = (int)$device['available'];

        if ($available <= 0) $errors[] = 'This device is currently unavailable.';
        elseif ($old['quantity'] < 1 || $old['quantity'] > $available) $errors[] = "Quantity must be between 1 and $available.";
        if ($old['days'] < 1 || $old['days'] > MAX_BORROW_DAYS) $errors[] = 'Number of days must be between 1 and ' . MAX_BORROW_DAYS . '.';
        if (mb_strlen($old['reason']) < 5) $errors[] = 'Please give a reason (at least 5 characters).';

        $dup = $pdo->prepare("SELECT COUNT(*) FROM requests WHERE user_id = ? AND device_id = ? AND status = 'pending'");
        $dup->execute([$uid, $id]);
        if ((int)$dup->fetchColumn() > 0) $errors[] = 'You already have a pending request for this device. Cancel it first if you want to change it.';

        if (!$errors) {
            $pdo->prepare('INSERT INTO requests (user_id, device_id, quantity, days, reason, status, requested_at) VALUES (?,?,?,?,?,?,?)')
                ->execute([$uid, $id, $old['quantity'], $old['days'], $old['reason'], 'pending', now()]);
            $pdo->commit();
            flash('success', "Request sent for \"{$device['name']}\". You will be notified when it is approved or rejected.");
            redirect('student/requests.php?status=pending');
        }
        $pdo->rollBack();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

$st = db()->prepare("SELECT * FROM requests WHERE user_id = ? AND device_id = ? AND status IN ('pending','approved','collected','return_pending') ORDER BY requested_at DESC");
$st->execute([$uid, $id]);
$myActive = $st->fetchAll();
$available = (int)$device['available'];
$out = (int)$device['borrowed_qty'] + (int)$device['reserved_qty'];

$pageTitle = $device['name'];
$pageSubtitle = 'Device details · ' . ($device['category_name'] ?? 'Uncategorised');
$active = 'devices';
require __DIR__ . '/../includes/header.php';
?>
<p><a href="<?= e(url('student/devices.php')) ?>"><?= icon('back') ?> Back to devices</a></p>

<div class="card">
    <div class="card-body detail">
        <div>
            <div class="detail-photo"><img src="<?= e(device_photo_url($device)) ?>" alt="<?= e($device['name']) ?>"></div>
            <?= photo_credit_html($device) ?>
        </div>
        <div>
            <span class="badge badge-info"><?= e($device['category_name'] ?? 'Uncategorised') ?></span>
            <?= availability_badge($device) ?>
            <h2 class="mt-2" style="font-size:1.6rem"><?= e($device['name']) ?></h2>
            <div class="muted"><?= icon('pin') ?> <?= e($device['location'] ?: 'Location not set') ?> · <code><?= e($device['code']) ?></code></div>

            <div class="kv">
                <div><span>Available now</span><strong class="<?= $available === 0 ? 'text-danger' : '' ?>"><?= $available ?></strong></div>
                <div><span>Total quantity</span><strong><?= (int)$device['quantity_total'] ?></strong></div>
                <div><span>Borrowed</span><strong><?= $out ?></strong></div>
                <div><span>Damaged / repair</span><strong><?= (int)$device['quantity_damaged'] + (int)$device['quantity_repair'] ?></strong></div>
            </div>
            <div class="small" style="margin-bottom:6px"><strong>Status:</strong> <?= $available > 0 ? '<span class="text-success">Available</span>' : '<span class="text-danger">All units are borrowed or unavailable</span>' ?></div>
            <?php if ($device['description']): ?>
                <h4 class="mt-2">Description</h4>
                <p><?= nl2br(e($device['description'])) ?></p>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="grid grid-2">
    <div class="card">
        <div class="card-head"><h2><?= icon('inbox') ?> Request this device</h2></div>
        <div class="card-body">
            <?php if ($errors): ?>
                <div class="alert alert-error"><?= icon('alert') ?><div><?= implode('<br>', array_map('e', $errors)) ?></div></div>
            <?php endif; ?>
            <?php if ($available > 0): ?>
                <form method="post">
                    <?= csrf_field() ?><input type="hidden" name="device_id" value="<?= $id ?>">
                    <div class="form-grid">
                        <div class="field"><label for="qty">Quantity</label><input type="number" id="qty" name="quantity" min="1" max="<?= $available ?>" value="<?= (int)min(max(1, $old['quantity']), $available) ?>" required><span class="hint">Up to <?= $available ?></span></div>
                        <div class="field"><label for="days">Number of days</label>
                            <select id="days" name="days">
                                <?php foreach ([1, 2, 3, 5, 7, 10, 14, 21, 30] as $n): if ($n > MAX_BORROW_DAYS) continue; ?>
                                    <option value="<?= $n ?>" <?= $old['days'] === $n ? 'selected' : '' ?>><?= $n ?> day<?= $n === 1 ? '' : 's' ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span class="hint">Counted from when you collect it.</span>
                        </div>
                        <div class="field full"><label for="reason">Reason</label><textarea id="reason" name="reason" required maxlength="1000" placeholder="e.g. Final-year project: line-following robot"><?= e($old['reason']) ?></textarea></div>
                    </div>
                    <button class="btn btn-sky btn-lg mt-2" type="submit"><?= icon('arrow') ?> Send request</button>
                </form>
            <?php else: ?>
                <div class="empty"><?= icon('clock') ?><h3>Currently unavailable</h3><p class="mb-0">All units are borrowed, reserved or being repaired. Check back later.</p></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><h2><?= icon('history') ?> My requests for this device</h2></div>
        <?php if ($myActive): ?>
            <ul class="list">
                <?php foreach ($myActive as $r): $over = is_overdue($r); ?>
                    <li>
                        <div class="grow">
                            <div class="title">× <?= (int)$r['quantity'] ?> for <?= (int)$r['days'] ?> day<?= $r['days'] == 1 ? '' : 's' ?></div>
                            <div class="sub">Requested <?= e(fmt_date($r['requested_at'], true)) ?><?= $r['due_date'] ? ' · due ' . e(fmt_date($r['due_date'], true)) : '' ?></div>
                        </div>
                        <?= status_badge($r['status'], $over) ?>
                        <?php if (in_array($r['status'], ['pending', 'approved'], true)): ?>
                            <form class="inline" method="post" action="<?= e(url('student/requests.php')) ?>" data-confirm="Cancel this request?">
                                <?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <button class="btn btn-sm btn-outline-red" type="submit">Cancel</button>
                            </form>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <div class="empty"><?= icon('inbox') ?><h3>No active requests</h3><p class="mb-0">Your requests for this device will appear here.</p></div>
        <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
