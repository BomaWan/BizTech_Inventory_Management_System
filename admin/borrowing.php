<?php
require_once __DIR__ . '/../includes/functions.php';
$me = require_role('admin');

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
        if (!$r) throw new RuntimeException('Record not found.');

        if ($action === 'collect') {
            if ($r['status'] !== 'approved') throw new RuntimeException('Only approved requests can be marked as collected.');
            $collected = now();
            $due = date('Y-m-d H:i:s', strtotime($collected . ' +' . (int)$r['days'] . ' days'));
            $pdo->prepare("UPDATE requests SET status='collected', collected_at=?, due_date=? WHERE id=?")->execute([$collected, $due, $id]);
            notify((int)$r['user_id'], 'collected', "You collected \"{$r['device_name']}\" × {$r['quantity']}. Please return it by " . fmt_date($due, true) . '.', $id);
            $msg = "Marked as collected. Due on " . fmt_date($due, true) . '.';
        } elseif ($action === 'return') {
            if (!in_array($r['status'], ['collected', 'return_pending'], true)) throw new RuntimeException('Only borrowed devices can be marked as returned.');
            $condition = post_str('condition') === 'damaged' ? 'damaged' : 'good';
            $pdo->prepare("UPDATE requests SET status='returned', returned_at=?, return_condition=? WHERE id=?")->execute([now(), $condition, $id]);
            if ($condition === 'damaged') {
                $pdo->prepare('UPDATE devices SET quantity_damaged = quantity_damaged + ?, updated_at = ? WHERE id = ?')->execute([(int)$r['quantity'], now(), $r['device_id']]);
            }
            $late = strtotime($r['due_date']) < time();
            notify((int)$r['user_id'], 'returned', "Return of \"{$r['device_name']}\" confirmed" . ($late ? ' (returned late)' : '') . '. Thank you!', $id);
            $msg = 'Marked as returned' . ($condition === 'damaged' ? ' and recorded as damaged.' : '.');
        } elseif ($action === 'cancel') {
            if ($r['status'] !== 'approved') throw new RuntimeException('Only approved (not yet collected) requests can be cancelled.');
            $pdo->prepare("UPDATE requests SET status='cancelled' WHERE id=?")->execute([$id]);
            notify((int)$r['user_id'], 'info', "Your approved request for \"{$r['device_name']}\" was cancelled because it was not collected.", $id);
            $msg = 'Approved request cancelled; units released back to stock.';
        } else {
            throw new RuntimeException('Unknown action.');
        }
        $pdo->commit();
        flash('success', $msg);
    } catch (RuntimeException $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', $ex->getMessage());
    }
    redirect('admin/borrowing.php?view=' . rawurlencode(post_str('_view') ?: 'active'));
}

$views = ['active' => 'Active loans', 'awaiting' => 'Awaiting collection', 'overdue' => 'Overdue', 'returned' => 'Returned'];
$view = get_str('view') ?: 'active';
if (!isset($views[$view])) $view = 'active';

$base = "SELECT r.*, u.name AS user_name, u.student_id, u.phone, u.email, d.name AS device_name, d.code, d.photo, d.location
         FROM requests r JOIN users u ON u.id = r.user_id JOIN devices d ON d.id = r.device_id";
$params = [];
switch ($view) {
    case 'awaiting':
        $sql = "$base WHERE r.status = 'approved' ORDER BY r.decided_at";
        break;
    case 'overdue':
        $sql = "$base WHERE r.status IN ('collected','return_pending') AND r.due_date < ? ORDER BY r.due_date";
        $params[] = now();
        break;
    case 'returned':
        $sql = "$base WHERE r.status = 'returned' ORDER BY r.returned_at DESC LIMIT 300";
        break;
    default:
        $sql = "$base WHERE r.status IN ('approved','collected','return_pending')
                ORDER BY (r.status = 'return_pending') DESC, (r.due_date IS NULL), r.due_date, r.decided_at";
}
$st = db()->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

$cnt = db()->prepare("SELECT
    SUM(status IN ('approved','collected','return_pending')) AS active,
    SUM(status = 'approved') AS awaiting,
    SUM(status IN ('collected','return_pending') AND due_date < ?) AS overdue,
    SUM(status = 'returned') AS returned FROM requests");
$cnt->execute([now()]);
$counts = $cnt->fetch();

$pageTitle = 'Borrowing & Returns';
$pageSubtitle = 'Mark devices as collected or returned · overdue loans are shown in red';
$active = 'borrowing';
require __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <div class="tabs">
        <?php foreach ($views as $k => $label): ?>
            <a class="<?= $view === $k ? 'active' : '' ?>" href="<?= e(url('admin/borrowing.php?view=' . $k)) ?>"><?= e($label) ?><span class="count"><?= (int)($counts[$k] ?? 0) ?></span></a>
        <?php endforeach; ?>
    </div>
    <div class="card-head">
        <div class="input-icon" style="flex:1;min-width:220px"><?= icon('search') ?><input type="search" placeholder="Filter this list…" data-filter="#loan-rows"></div>
    </div>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Student</th><th>Device</th><th class="num">Qty</th><th>Approved</th><th>Collected</th><th>Due date</th><?= $view === 'returned' ? '<th>Returned</th>' : '' ?><th>Status</th><th class="num">Actions</th></tr></thead>
            <tbody id="loan-rows">
            <?php foreach ($rows as $r): $over = is_overdue($r); ?>
                <tr class="<?= $over ? 'row-overdue' : '' ?>" data-search="<?= e($r['user_name'] . ' ' . $r['student_id'] . ' ' . $r['device_name'] . ' ' . $r['code']) ?>">
                    <td><strong style="color:var(--navy)"><?= e($r['user_name']) ?></strong><br><small class="muted"><?= e($r['student_id'] ?? '') ?><?= $r['phone'] ? ' · ' . e($r['phone']) : '' ?></small></td>
                    <td><div class="cell-device"><img src="<?= e(device_photo_url($r)) ?>" alt=""><div><strong><?= e($r['device_name']) ?></strong><small><?= e($r['code']) ?></small></div></div></td>
                    <td class="num"><?= (int)$r['quantity'] ?></td>
                    <td class="nowrap"><?= e(fmt_date($r['decided_at'])) ?></td>
                    <td class="nowrap"><?= e(fmt_date($r['collected_at'], true)) ?></td>
                    <td class="nowrap due">
                        <?php if ($r['due_date']): ?>
                            <?= e(fmt_date($r['due_date'], true)) ?><br>
                            <?php if ($r['status'] !== 'returned'): ?><small><?= e(due_text($r)) ?></small><?php endif; ?>
                        <?php else: ?>
                            <span class="muted"><?= (int)$r['days'] ?> day<?= $r['days'] == 1 ? '' : 's' ?> after collection</span>
                        <?php endif; ?>
                    </td>
                    <?php if ($view === 'returned'): ?>
                        <td class="nowrap"><?= e(fmt_date($r['returned_at'], true)) ?><br>
                            <?= $r['return_condition'] === 'damaged' ? '<span class="badge badge-danger">Damaged</span>' : '<span class="badge badge-success">Good</span>' ?>
                            <?php if ($r['returned_at'] && $r['due_date'] && strtotime($r['returned_at']) > strtotime($r['due_date'])): ?><span class="badge badge-warning">Late</span><?php endif; ?>
                        </td>
                    <?php endif; ?>
                    <td><?= status_badge($r['status'], $over) ?><?php if ($over && $r['status'] === 'return_pending'): ?><br><small class="muted">Student marked as returned</small><?php endif; ?></td>
                    <td class="num">
                        <div class="btn-group" style="justify-content:flex-end">
                        <?php if ($r['status'] === 'approved'): ?>
                            <form class="inline" method="post">
                                <?= csrf_field() ?><input type="hidden" name="action" value="collect"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="_view" value="<?= e($view) ?>">
                                <button class="btn btn-sm btn-sky" type="submit"><?= icon('package') ?> Mark as collected</button>
                            </form>
                            <form class="inline" method="post" data-confirm="Cancel this approved request and release the units?">
                                <?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="_view" value="<?= e($view) ?>">
                                <button class="btn btn-sm btn-light" type="submit" title="Cancel (not collected)"><?= icon('x') ?></button>
                            </form>
                        <?php elseif (in_array($r['status'], ['collected', 'return_pending'], true)): ?>
                            <button class="btn btn-sm btn-success" type="button" data-modal="return-modal" data-fill='<?= json_encode(['id' => (int)$r['id'], 'who' => $r['user_name'] . ' — ' . $r['device_name'] . ' × ' . $r['quantity']], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>'><?= icon('check') ?> Mark as returned</button>
                        <?php else: ?>
                            <span class="muted small">—</span>
                        <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
                <tr><td colspan="9"><div class="empty"><?= icon('repeat') ?><h3>Nothing here</h3><p class="mb-0">No records in “<?= e($views[$view]) ?>”.</p></div></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<dialog class="modal" id="return-modal">
    <form method="post">
        <div class="modal-head"><h3>Confirm return</h3><button class="icon-btn" type="button" data-close aria-label="Close"><?= icon('x') ?></button></div>
        <div class="modal-body">
            <?= csrf_field() ?><input type="hidden" name="action" value="return"><input type="hidden" name="id" value="0"><input type="hidden" name="_view" value="<?= e($view) ?>">
            <p><strong data-text="who"></strong></p>
            <div class="field mb-0"><label for="cond">Condition of the returned device</label>
                <select id="cond" name="condition">
                    <option value="good">Good — return units to available stock</option>
                    <option value="damaged">Damaged — record units as damaged</option>
                </select>
            </div>
        </div>
        <div class="modal-foot"><button class="btn btn-light" type="button" data-close>Cancel</button><button class="btn btn-success" type="submit"><?= icon('check') ?> Mark as returned</button></div>
    </form>
</dialog>
<?php require __DIR__ . '/../includes/footer.php'; ?>
