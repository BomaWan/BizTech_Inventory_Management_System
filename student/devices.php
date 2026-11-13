<?php
require_once __DIR__ . '/../includes/functions.php';
$me = require_role('student');
generate_return_reminders((int)$me['id']);

$categories = all_categories();
$q = get_str('q');
$cat = get_int('cat');
$onlyAvailable = !empty($_GET['available']);

$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(d.name LIKE ? OR d.description LIKE ? OR d.code = ?)';
    array_push($params, "%$q%", "%$q%", $q);
}
if ($cat) {
    $where[] = 'd.category_id = ?';
    $params[] = $cat;
}
$sql = 'SELECT * FROM (' . device_select_sql() . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ') x'
     . ($onlyAvailable ? ' WHERE x.available > 0' : '') . ' ORDER BY x.name';
$st = db()->prepare($sql);
$st->execute($params);
$devices = $st->fetchAll();

$pageTitle = 'Find Devices';
$pageSubtitle = 'Search by name or filter by category';
$active = 'devices';
require __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <div class="card-body">
        <form class="toolbar" method="get">
            <div class="input-icon grow"><?= icon('search') ?><input type="search" name="q" value="<?= e($q) ?>" placeholder="Search devices by name…"></div>
            <select name="cat" onchange="this.form.submit()">
                <option value="0">All categories</option>
                <?php foreach ($categories as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $cat === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
            </select>
            <label style="display:flex;gap:6px;align-items:center;font-weight:500"><input type="checkbox" name="available" value="1" <?= $onlyAvailable ? 'checked' : '' ?> onchange="this.form.submit()"> Available only</label>
            <button class="btn btn-sky" type="submit"><?= icon('search') ?> Search</button>
            <?php if ($q !== '' || $cat || $onlyAvailable): ?><a class="btn btn-ghost" href="<?= e(url('student/devices.php')) ?>">Clear</a><?php endif; ?>
        </form>
        <div class="chips mt-2">
            <a class="chip <?= $cat === 0 ? 'active' : '' ?>" href="<?= e(url('student/devices.php?q=' . rawurlencode($q))) ?>">All</a>
            <?php foreach ($categories as $c): ?>
                <a class="chip <?= $cat === (int)$c['id'] ? 'active' : '' ?>" href="<?= e(url('student/devices.php?cat=' . (int)$c['id'] . '&q=' . rawurlencode($q))) ?>"><?= e($c['name']) ?></a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<p class="muted small"><?= count($devices) ?> device<?= count($devices) === 1 ? '' : 's' ?> found</p>
<?php if ($devices): ?>
    <div class="device-grid">
        <?php foreach ($devices as $d): ?>
            <a class="device-card" href="<?= e(url('student/device.php?id=' . (int)$d['id'])) ?>">
                <div class="thumb"><img src="<?= e(device_photo_url($d)) ?>" alt="" loading="lazy"><?= availability_badge($d) ?></div>
                <div class="body">
                    <span class="cat"><?= e($d['category_name'] ?? '—') ?></span>
                    <h3><?= e($d['name']) ?></h3>
                    <div class="meta"><span><?= icon('pin') ?> <?= e($d['location'] ?: '—') ?></span><span><strong class="pill-qty"><?= (int)$d['available'] ?></strong> / <?= (int)$d['quantity_total'] ?></span></div>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <div class="card"><div class="empty"><?= icon('search') ?><h3>No devices match your search</h3><p class="mb-0">Try another name or category.</p></div></div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
