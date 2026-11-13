<?php
require_once __DIR__ . '/../includes/functions.php';
$me = require_role('admin');

if (is_post()) {
    verify_csrf();
    if (post_str('action') === 'regenerate') {
        $d = find_device(post_int('id'));
        if ($d) {
            $code = generate_device_code();
            db()->prepare('UPDATE devices SET code = ?, updated_at = ? WHERE id = ?')->execute([$code, now(), $d['id']]);
            flash('success', "New QR code generated for \"{$d['name']}\" ($code). Print a new label: the old label for this device no longer works.");
        }
    }
    redirect('admin/qr.php' . (post_int('_cat') ? '?cat=' . post_int('_cat') : ''));
}

$categories = all_categories();
$cat = get_int('cat');
$sql = device_select_sql() . ($cat ? ' WHERE d.category_id = ?' : '') . ' ORDER BY d.name';
$st = db()->prepare($sql);
$st->execute($cat ? [$cat] : []);
$devices = $st->fetchAll();

$qrBase = qr_base_url();
$localOnly = qr_is_local_only();
$viaLanAuto = APP_URL === '' && is_loopback_host(parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'), PHP_URL_HOST) ?: 'localhost') && !$localOnly;
$noLocation = count(array_filter($devices, fn($d) => trim((string)$d['location']) === ''));

$labelData = array_map(fn($d) => [
    'text' => scan_url($d['code']), 'name' => $d['name'], 'code' => $d['code'], 'location' => (string)$d['location'],
], $devices);
$catName = '';
foreach ($categories as $c) if ((int)$c['id'] === $cat) $catName = $c['name'];

$pageTitle = 'QR Codes';
$pageSubtitle = 'Download or print a QR tag for every device and stick it on the device';
$active = 'qr';
$extraScripts = [asset('vendor/qrcode-generator.min.js'), asset('vendor/jszip.min.js')];
require __DIR__ . '/../includes/header.php';
?>
<?php if ($localOnly): ?>
    <div class="alert alert-warning"><?= icon('alert') ?><div>
        QR codes currently point to <code><?= e($qrBase) ?></code>, which only works on this computer, so phones won't be able to open them.
        Connect this computer to the same Wi-Fi as the phones, or set <code>APP_URL</code> in <code>includes/config.php</code>, then download the labels again.
    </div></div>
<?php else: ?>
    <div class="alert alert-info"><?= icon('wifi') ?><div>
        QR codes open <code><?= e($qrBase) ?>/scan.php</code>. Phones connected to the <strong>same Wi-Fi network</strong> as this computer can scan them.
        <?php if ($viaLanAuto): ?>
            This network address was detected automatically. If your router gives this computer a different address later, labels printed now will stop working.
            To prevent that, reserve the address in your router (a "DHCP reservation") or set <code>APP_URL</code> in <code>includes/config.php</code>.
        <?php endif; ?>
    </div></div>
<?php endif; ?>
<?php if ($noLocation): ?>
    <div class="alert alert-warning"><?= icon('pin') ?><div><?= $noLocation ?> device<?= $noLocation === 1 ? ' has' : 's have' ?> no location set, so their labels can't say where the device belongs. Add one on the <a href="<?= e(url('admin/devices.php')) ?>">Devices</a> page.</div></div>
<?php endif; ?>

<div class="card">
    <div class="card-head">
        <h2><?= icon('qr') ?> How to tag a device</h2>
    </div>
    <div class="card-body">
        <div class="steps qr-steps">
            <div class="step"><h3>Download or print</h3><p>Download one label, all labels as a ZIP, or print a sticker sheet.</p></div>
            <div class="step"><h3>Stick it on</h3><p>Stick each label on its device, or on the box or drawer it's kept in.</p></div>
            <div class="step"><h3>Scan</h3><p>Anyone can scan it with a phone camera. No app or login is needed.</p></div>
            <div class="step"><h3>Locate</h3><p>The page shows where the device is kept and how many are available. Admins also see who has borrowed units.</p></div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <div class="chips" style="flex:1">
            <a class="chip <?= $cat === 0 ? 'active' : '' ?>" href="<?= e(url('admin/qr.php')) ?>">All</a>
            <?php foreach ($categories as $c): ?>
                <a class="chip <?= $cat === (int)$c['id'] ? 'active' : '' ?>" href="<?= e(url('admin/qr.php?cat=' . (int)$c['id'])) ?>"><?= e($c['name']) ?></a>
            <?php endforeach; ?>
        </div>
        <div class="actions">
            <div class="input-icon"><?= icon('search') ?><input type="search" placeholder="Filter devices…" data-filter="#qr-grid"></div>
        </div>
    </div>
    <div class="card-body" style="border-bottom:1px solid var(--line);display:flex;gap:10px;flex-wrap:wrap;align-items:center">
        <button class="btn btn-sky" type="button" id="zip-btn" <?= $devices ? '' : 'disabled' ?>><?= icon('download') ?> Download all <?= count($devices) ?> labels (ZIP)</button>
        <form method="get" action="<?= e(url('admin/qr_print.php')) ?>" target="_blank" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:0">
            <input type="hidden" name="cat" value="<?= (int)$cat ?>">
            <select name="size" aria-label="Sticker size" style="width:auto">
                <option value="small">Small stickers (40 mm)</option>
                <option value="medium" selected>Medium stickers (55 mm)</option>
                <option value="large">Large stickers (75 mm)</option>
            </select>
            <button class="btn" type="submit" <?= $devices ? '' : 'disabled' ?>><?= icon('printer') ?> Print sticker sheet</button>
        </form>
        <span class="muted small" id="zip-status"></span>
    </div>
    <div class="card-body">
        <div class="qr-grid" id="qr-grid">
            <?php foreach ($devices as $d): $link = scan_url($d['code']); ?>
                <div class="qr-card" data-search="<?= e($d['name'] . ' ' . $d['code'] . ' ' . ($d['category_name'] ?? '') . ' ' . $d['location']) ?>">
                    <div class="qr-box" data-qr="<?= e($link) ?>" data-qr-size="160"></div>
                    <h4><?= e($d['name']) ?></h4>
                    <div class="qr-loc <?= trim((string)$d['location']) === '' ? 'missing' : '' ?>"><?= icon('pin') ?> <?= e($d['location'] ?: 'No location set') ?></div>
                    <code><?= e($d['code']) ?></code>
                    <div class="small muted"><?= e($d['category_name'] ?? '—') ?> · <strong class="pill-qty"><?= (int)$d['available'] ?></strong> / <?= (int)$d['quantity_total'] ?> available</div>
                    <div class="btn-group no-print" style="justify-content:center">
                        <button class="btn btn-sm btn-sky" type="button" data-qr-download="<?= e($link) ?>" data-name="<?= e($d['name']) ?>" data-code="<?= e($d['code']) ?>" data-location="<?= e($d['location'] ?? '') ?>"><?= icon('download') ?> Download</button>
                        <a class="btn btn-sm btn-light" href="<?= e(url('admin/qr_print.php?id=' . (int)$d['id'])) ?>" target="_blank" title="Print this label"><?= icon('printer') ?></a>
                        <a class="btn btn-sm btn-light" href="<?= e($link) ?>" target="_blank" rel="noopener" title="Preview what a scan shows"><?= icon('eye') ?></a>
                        <form class="inline" method="post" data-confirm="Generate a new QR code for &quot;<?= e($d['name']) ?>&quot;? The label already on this device will stop working.">
                            <?= csrf_field() ?><input type="hidden" name="action" value="regenerate"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><input type="hidden" name="_cat" value="<?= (int)$cat ?>">
                            <button class="btn btn-sm btn-light" type="submit" title="Generate new code"><?= icon('repeat') ?></button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if (!$devices): ?>
            <div class="empty"><?= icon('qr') ?><h3>No devices</h3><p class="mb-0">Add devices to generate their QR codes.</p></div>
        <?php endif; ?>
    </div>
</div>
<?php
$labelsJson = json_encode($labelData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES);
$zipName = json_encode('LearnX-QR-labels' . ($catName ? '-' . preg_replace('/[^A-Za-z0-9]+/', '-', $catName) : '') . '.zip');
$inlineScript = <<<JS
(function () {
    var items = $labelsJson, btn = document.getElementById('zip-btn'), status = document.getElementById('zip-status');
    if (!btn) return;
    btn.addEventListener('click', function () {
        btn.disabled = true;
        LearnXQR.downloadAll(items, $zipName, function (done, total) { status.textContent = 'Creating labels… ' + done + ' / ' + total; })
            .then(function () { status.textContent = 'ZIP downloaded with ' + items.length + ' labels.'; })
            .catch(function (e) { status.textContent = 'Could not create the ZIP: ' + e; })
            .then(function () { btn.disabled = false; });
    });
})();
JS;
require __DIR__ . '/../includes/footer.php';
