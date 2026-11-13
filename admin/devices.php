<?php
require_once __DIR__ . '/../includes/functions.php';
$me = require_role('admin');

$categories = all_categories();
$catIds = array_map(fn($c) => (int)$c['id'], $categories);

if (is_post()) {
    verify_csrf();
    $action = post_str('action');
    $id = post_int('id');

    try {
        if ($action === 'save') {
            $name = post_str('name', 150);
            $categoryId = post_int('category_id');
            $location = post_str('location', 150);
            $description = post_str('description', 5000);
            $total = post_int('quantity_total', -1);
            $low = post_int('low_stock_threshold', -1);

            if ($name === '') throw new RuntimeException('Device name is required.');
            if (!in_array($categoryId, $catIds, true)) throw new RuntimeException('Please choose a valid category.');
            if ($total < 0 || $total > 100000) throw new RuntimeException('Total quantity must be a number between 0 and 100000.');
            if ($low < 0 || $low > 100000) throw new RuntimeException('Low-stock level must be 0 or more.');

            $existing = $id ? find_device($id) : null;
            if ($id && !$existing) throw new RuntimeException('Device not found.');

            if ($existing) {
                $committed = (int)$existing['quantity_damaged'] + (int)$existing['quantity_repair'] + (int)$existing['reserved_qty'] + (int)$existing['borrowed_qty'];
                if ($total < $committed) {
                    throw new RuntimeException("Total quantity cannot be less than $committed (units currently borrowed, reserved, damaged or under repair).");
                }
            }

            $photo = handle_photo_upload('photo');
            $now = now();
            if ($existing) {
                $newPhoto = $existing['photo'];
                if ($photo) {
                    delete_photo($existing['photo']);
                    $newPhoto = $photo;
                } elseif (!empty($_POST['remove_photo'])) {
                    delete_photo($existing['photo']);
                    $newPhoto = null;
                }
                db()->prepare('UPDATE devices SET category_id=?, name=?, description=?, location=?, quantity_total=?, low_stock_threshold=?, photo=?, updated_at=? WHERE id=?')
                    ->execute([$categoryId, $name, $description ?: null, $location ?: null, $total, $low, $newPhoto, $now, $id]);
                flash('success', "Device \"$name\" updated.");
            } else {
                db()->prepare('INSERT INTO devices (category_id,code,name,description,location,quantity_total,low_stock_threshold,photo,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$categoryId, generate_device_code(), $name, $description ?: null, $location ?: null, $total, $low, $photo, $now, $now]);
                flash('success', "Device \"$name\" added. Its QR code is ready on the QR Codes page.");
            }
        } elseif ($action === 'condition') {
            $d = find_device($id);
            if (!$d) throw new RuntimeException('Device not found.');
            $damaged = post_int('quantity_damaged', -1);
            $repair = post_int('quantity_repair', -1);
            if ($damaged < 0 || $repair < 0) throw new RuntimeException('Quantities cannot be negative.');
            $inLab = (int)$d['quantity_total'] - (int)$d['reserved_qty'] - (int)$d['borrowed_qty'];
            if ($damaged + $repair > $inLab) {
                throw new RuntimeException("Only $inLab unit(s) of \"{$d['name']}\" are in the lab; damaged + under repair cannot exceed that.");
            }
            db()->prepare('UPDATE devices SET quantity_damaged=?, quantity_repair=?, updated_at=? WHERE id=?')->execute([$damaged, $repair, now(), $id]);
            flash('success', "Condition of \"{$d['name']}\" updated: $damaged damaged, $repair under repair.");
        } elseif ($action === 'delete') {
            $d = find_device($id);
            if (!$d) throw new RuntimeException('Device not found.');
            if ((int)$d['reserved_qty'] + (int)$d['borrowed_qty'] > 0) {
                throw new RuntimeException("\"{$d['name']}\" cannot be deleted while units are borrowed or approved for collection.");
            }
            db()->prepare('DELETE FROM devices WHERE id = ?')->execute([$id]);
            delete_photo($d['photo']);
            flash('success', "Device \"{$d['name']}\" deleted.");
        }
    } catch (RuntimeException $ex) {
        flash('error', $ex->getMessage());
    }
    $qs = http_build_query(array_filter(['q' => post_str('_q'), 'cat' => post_int('_cat') ?: null]));
    redirect('admin/devices.php' . ($qs ? '?' . $qs : ''));
}

$q = get_str('q');
$cat = get_int('cat');
$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(d.name LIKE ? OR d.code LIKE ? OR d.location LIKE ?)';
    array_push($params, "%$q%", "%$q%", "%$q%");
}
if ($cat) {
    $where[] = 'd.category_id = ?';
    $params[] = $cat;
}
$st = db()->prepare(device_select_sql() . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY d.name');
$st->execute($params);
$devices = $st->fetchAll();

$pageTitle = 'Manage Devices';
$pageSubtitle = 'Add, edit, delete and mark devices as damaged or under repair';
$active = 'devices';
$extraScripts = [asset('vendor/qrcode-generator.min.js')];
require __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <div class="card-head">
        <form class="toolbar" method="get" style="flex:1">
            <div class="input-icon grow"><?= icon('search') ?><input type="search" name="q" value="<?= e($q) ?>" placeholder="Search by name, code or location"></div>
            <select name="cat" onchange="this.form.submit()">
                <option value="0">All categories</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= $cat === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-light" type="submit"><?= icon('search') ?> Search</button>
            <?php if ($q !== '' || $cat): ?><a class="btn btn-ghost" href="<?= e(url('admin/devices.php')) ?>">Clear</a><?php endif; ?>
        </form>
        <div class="actions">
            <button class="btn btn-sky" type="button" data-modal="device-modal" data-reset data-title="Add device" data-fill='{"id":"0","low_stock_threshold":"2","quantity_total":"1"}'><?= icon('plus') ?> Add device</button>
        </div>
    </div>
    <?php if (!$categories): ?>
        <div class="alert alert-warning" style="margin:16px 20px 0"><?= icon('alert') ?><div>Create a category first on the <a href="<?= e(url('admin/categories.php')) ?>">Categories</a> page.</div></div>
    <?php endif; ?>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Device</th><th>Category</th><th>Location</th><th class="num">Total</th><th class="num">Available</th><th class="num">Borrowed</th><th class="num">Damaged</th><th class="num">Repair</th><th>Status</th><th class="num">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($devices as $d):
                $fill = json_encode([
                    'id' => (int)$d['id'], 'name' => $d['name'], 'category_id' => (int)$d['category_id'], 'location' => $d['location'],
                    'quantity_total' => (int)$d['quantity_total'], 'low_stock_threshold' => (int)$d['low_stock_threshold'], 'description' => $d['description'],
                ], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
                $cond = json_encode(['id' => (int)$d['id'], 'quantity_damaged' => (int)$d['quantity_damaged'], 'quantity_repair' => (int)$d['quantity_repair'], 'name' => $d['name'],
                    'in_lab' => (int)$d['quantity_total'] - (int)$d['reserved_qty'] - (int)$d['borrowed_qty']], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
            ?>
                <tr>
                    <td><div class="cell-device"><img src="<?= e(device_photo_url($d)) ?>" alt=""><div><strong><?= e($d['name']) ?></strong><small><?= e($d['code']) ?></small></div></div></td>
                    <td><?= e($d['category_name'] ?? '—') ?></td>
                    <td><?= e($d['location'] ?: '—') ?></td>
                    <td class="num"><?= (int)$d['quantity_total'] ?></td>
                    <td class="num pill-qty"><?= (int)$d['available'] ?></td>
                    <td class="num"><?= (int)$d['borrowed_qty'] + (int)$d['reserved_qty'] ?></td>
                    <td class="num <?= $d['quantity_damaged'] ? 'text-danger' : '' ?>"><?= (int)$d['quantity_damaged'] ?></td>
                    <td class="num"><?= (int)$d['quantity_repair'] ?></td>
                    <td><?= availability_badge($d) ?></td>
                    <td class="num">
                        <div class="btn-group" style="justify-content:flex-end">
                            <button class="btn btn-sm btn-light" type="button" title="QR code" data-modal="qr-modal" data-qr-text="<?= e(scan_url($d['code'])) ?>" data-name="<?= e($d['name']) ?>" data-code="<?= e($d['code']) ?>" data-location="<?= e($d['location'] ?? '') ?>"><?= icon('qr') ?></button>
                            <button class="btn btn-sm btn-light" type="button" title="Edit" data-modal="device-modal" data-reset data-title="Edit device" data-fill='<?= $fill ?>'><?= icon('edit') ?></button>
                            <button class="btn btn-sm btn-light" type="button" title="Mark damaged / under repair" data-modal="condition-modal" data-fill='<?= $cond ?>'><?= icon('wrench') ?></button>
                            <form class="inline" method="post" data-confirm="Delete &quot;<?= e($d['name']) ?>&quot;? Its request history will also be removed. This cannot be undone.">
                                <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                                <input type="hidden" name="_q" value="<?= e($q) ?>"><input type="hidden" name="_cat" value="<?= (int)$cat ?>">
                                <button class="btn btn-sm btn-outline-red" type="submit" title="Delete"><?= icon('trash') ?></button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$devices): ?>
                <tr><td colspan="10"><div class="empty"><?= icon('cpu') ?><h3>No devices found</h3><p class="mb-0">Try a different search, or add a new device.</p></div></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add / edit device -->
<dialog class="modal wide" id="device-modal">
    <form method="post" enctype="multipart/form-data">
        <div class="modal-head"><h3 data-modal-title>Add device</h3><button class="icon-btn" type="button" data-close aria-label="Close"><?= icon('x') ?></button></div>
        <div class="modal-body">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="0">
            <input type="hidden" name="_q" value="<?= e($q) ?>"><input type="hidden" name="_cat" value="<?= (int)$cat ?>">
            <div class="form-grid">
                <div class="field full"><label for="dv-name">Device name</label><input type="text" id="dv-name" name="name" required maxlength="150"></div>
                <div class="field"><label for="dv-cat">Category</label>
                    <select id="dv-cat" name="category_id" required>
                        <?php foreach ($categories as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
                    </select></div>
                <div class="field"><label for="dv-loc">Location</label><input type="text" id="dv-loc" name="location" maxlength="150" placeholder="e.g. Lab A - Cabinet 1"></div>
                <div class="field"><label for="dv-qty">Total quantity</label><input type="number" id="dv-qty" name="quantity_total" min="0" required></div>
                <div class="field"><label for="dv-low">Low-stock alert level</label><input type="number" id="dv-low" name="low_stock_threshold" min="0" required><span class="hint">Alert when available units fall to this number or below.</span></div>
                <div class="field full"><label for="dv-desc">Description</label><textarea id="dv-desc" name="description" maxlength="5000"></textarea></div>
                <div class="field full"><label for="dv-photo">Photo <span class="hint">(JPG, PNG, WEBP or GIF, max 2 MB)</span></label><input type="file" id="dv-photo" name="photo" accept="image/jpeg,image/png,image/webp,image/gif"></div>
                <div class="field full" id="remove-photo-row"><label style="font-weight:500"><input type="checkbox" name="remove_photo" value="1"> Remove current photo</label></div>
            </div>
        </div>
        <div class="modal-foot"><button class="btn btn-light" type="button" data-close>Cancel</button><button class="btn btn-sky" type="submit"><?= icon('check') ?> Save device</button></div>
    </form>
</dialog>

<!-- Damaged / under repair -->
<dialog class="modal" id="condition-modal">
    <form method="post">
        <div class="modal-head"><h3>Damaged / under repair</h3><button class="icon-btn" type="button" data-close aria-label="Close"><?= icon('x') ?></button></div>
        <div class="modal-body">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="condition"><input type="hidden" name="id" value="0">
            <input type="hidden" name="_q" value="<?= e($q) ?>"><input type="hidden" name="_cat" value="<?= (int)$cat ?>">
            <p><strong data-text="name"></strong><br><span class="muted small">Units currently in the lab: <b data-text="in_lab"></b>. Units marked damaged or under repair cannot be borrowed.</span></p>
            <div class="form-grid">
                <div class="field"><label for="cd-dmg">Damaged units</label><input type="number" id="cd-dmg" name="quantity_damaged" min="0" required></div>
                <div class="field"><label for="cd-rep">Under repair units</label><input type="number" id="cd-rep" name="quantity_repair" min="0" required></div>
            </div>
            <p class="hint mt-2 mb-0">Set both to 0 to mark everything as working again.</p>
        </div>
        <div class="modal-foot"><button class="btn btn-light" type="button" data-close>Cancel</button><button class="btn" type="submit"><?= icon('wrench') ?> Update condition</button></div>
    </form>
</dialog>

<!-- QR -->
<dialog class="modal" id="qr-modal">
    <div class="modal-head"><h3>Device QR code</h3><button class="icon-btn" type="button" data-close aria-label="Close"><?= icon('x') ?></button></div>
    <div class="modal-body">
        <div class="qr-label">
            <div class="qr-large" id="qr-target"></div>
            <strong id="qr-name"></strong>
            <code id="qr-code"></code>
            <p class="hint mb-0" style="text-align:center">Scanning opens a page with the number of units available.</p>
        </div>
    </div>
    <div class="modal-foot"><button class="btn btn-light" type="button" data-close>Close</button><button class="btn btn-sky" type="button" id="qr-dl"><?= icon('download') ?> Download QR</button></div>
</dialog>
<?php
$inlineScript = <<<'JS'
(function () {
    var dm = document.getElementById('device-modal');
    dm.addEventListener('modal:open', function () {
        var isEdit = dm.querySelector('[name=id]').value !== '0';
        document.getElementById('remove-photo-row').style.display = isEdit ? '' : 'none';
    });
    var qm = document.getElementById('qr-modal'), cur = null;
    qm.addEventListener('modal:open', function (ev) {
        var o = ev.detail.opener;
        cur = { text: o.getAttribute('data-qr-text'), name: o.getAttribute('data-name'), code: o.getAttribute('data-code'), loc: o.getAttribute('data-location') };
        LearnXQR.render(document.getElementById('qr-target'), cur.text, 240);
        document.getElementById('qr-name').textContent = cur.name;
        document.getElementById('qr-code').textContent = cur.code;
    });
    document.getElementById('qr-dl').addEventListener('click', function () { if (cur) LearnXQR.download(cur.text, cur.name, cur.code, cur.loc); });
})();
JS;
require __DIR__ . '/../includes/footer.php';
