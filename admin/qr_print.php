<?php
/**
 * Printable QR sticker sheet (A4). ?id=N for one device, otherwise all devices (optionally ?cat=N).
 * ?size=small|medium|large controls the printed sticker width.
 */
require_once __DIR__ . '/../includes/functions.php';
$me = require_role('admin');

$sizes = ['small' => 40, 'medium' => 55, 'large' => 75]; // sticker width in mm
$size = get_str('size');
if (!isset($sizes[$size])) $size = 'medium';
$mm = $sizes[$size];

$id = get_int('id');
$cat = get_int('cat');
$copies = max(1, min(50, get_int('copies', 1)));
if ($id) {
    $d = find_device($id);
    $devices = $d ? array_fill(0, $copies, $d) : [];
} else {
    $st = db()->prepare(device_select_sql() . ($cat ? ' WHERE d.category_id = ?' : '') . ' ORDER BY c.name, d.name');
    $st->execute($cat ? [$cat] : []);
    $devices = $st->fetchAll();
}
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>QR sticker sheet · <?= e(APP_NAME) ?></title>
    <link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
    <style>
        @page { size: A4; margin: 10mm; }
        body { background: var(--bg); }
        .sheet-bar { position: sticky; top: 0; z-index: 5; display: flex; gap: 10px; flex-wrap: wrap; align-items: center; padding: 12px 20px; background: #fff; border-bottom: 1px solid var(--line); }
        .sheet-bar img { height: 40px; }
        .sheet-bar .grow { flex: 1; }
        .sheet { display: flex; flex-wrap: wrap; gap: 4mm; padding: 10mm; justify-content: flex-start; background: #fff; max-width: 210mm; margin: 20px auto; box-shadow: var(--shadow); min-height: 297mm; align-content: flex-start; }
        .tag { width: <?= $mm ?>mm; border: 0.3mm dashed #9aa7b8; border-radius: 3mm; padding: 2.2mm; text-align: center; break-inside: avoid; page-break-inside: avoid; background: #fff; color: #062551; font-family: "Segoe UI", Arial, sans-serif; }
        .tag-head { display: flex; align-items: center; justify-content: space-between; background: #062551; color: #fff; border-radius: 1.6mm; padding: 0.8mm 1.8mm; font-size: <?= round($mm * 0.075, 2) ?>mm; font-weight: 800; }
        .tag-head b { color: #0d9dd2; }
        .tag-head i { display: inline-block; width: 1.4mm; height: 1.4mm; background: #a60612; margin-left: 0.5mm; vertical-align: top; }
        .tag-head span { font-weight: 600; color: #9fbbe0; font-size: 0.75em; letter-spacing: .05em; }
        .tag-qr { margin: 1.6mm auto 1.2mm; width: <?= $mm - 9 ?>mm; height: <?= $mm - 9 ?>mm; }
        .tag-qr img, .tag-qr canvas { width: 100% !important; height: 100% !important; display: block; image-rendering: pixelated; }
        .tag-name { font-weight: 800; font-size: <?= round($mm * 0.075, 2) ?>mm; line-height: 1.15; }
        .tag-loc { margin-top: 1mm; background: #ecf8fd; border: 0.3mm solid #0d9dd2; border-radius: 1.4mm; padding: 0.8mm 1mm; font-weight: 700; font-size: <?= round($mm * 0.068, 2) ?>mm; line-height: 1.2; }
        .tag-loc small { display: block; font-size: 0.72em; color: #0b85b3; letter-spacing: .06em; font-weight: 800; }
        .tag-code { margin-top: 0.8mm; font-family: Consolas, monospace; font-size: <?= round($mm * 0.058, 2) ?>mm; color: #44526a; font-weight: 700; }
        <?php if ($size === 'small'): ?>.tag-head span { display: none; }<?php endif; ?>
        @media print {
            body { background: #fff; }
            .sheet-bar { display: none; }
            .sheet { box-shadow: none; margin: 0; padding: 0; max-width: none; min-height: 0; }
        }
    </style>
</head>
<body>
<div class="sheet-bar">
    <img src="<?= e(asset('img/logo.svg')) ?>" alt="LearnX">
    <div class="grow"><strong style="color:var(--navy)"><?= count($devices) ?> QR sticker<?= count($devices) === 1 ? '' : 's' ?></strong> · <?= e(ucfirst($size)) ?> (<?= $mm ?> mm wide) · A4
        <div class="small muted">Print at 100% scale ("Actual size"). Cut along the dashed lines and stick each tag on its device.</div></div>
    <form method="get" style="display:flex;gap:8px;align-items:center;margin:0">
        <?php if ($id): ?><input type="hidden" name="id" value="<?= $id ?>">
            <label class="small" for="copies">Copies</label><input id="copies" type="number" name="copies" min="1" max="50" value="<?= $copies ?>" style="width:80px">
        <?php else: ?><input type="hidden" name="cat" value="<?= $cat ?>"><?php endif; ?>
        <select name="size" onchange="this.form.submit()" style="width:auto">
            <?php foreach ($sizes as $k => $v): ?><option value="<?= $k ?>" <?= $k === $size ? 'selected' : '' ?>><?= ucfirst($k) ?> (<?= $v ?> mm)</option><?php endforeach; ?>
        </select>
        <?php if ($id): ?><button class="btn btn-light" type="submit">Update</button><?php endif; ?>
    </form>
    <button class="btn btn-sky" type="button" onclick="window.print()"><?= icon('printer') ?> Print</button>
</div>

<div class="sheet">
    <?php foreach ($devices as $d): ?>
        <div class="tag">
            <div class="tag-head"><div>Learn<b>X</b><i></i></div><span>INVENTORY TAG</span></div>
            <div class="tag-qr" data-qr="<?= e(scan_url($d['code'])) ?>" data-qr-size="300"></div>
            <div class="tag-name"><?= e($d['name']) ?></div>
            <div class="tag-loc"><small>LOCATION</small><?= e($d['location'] ?: 'Not set') ?></div>
            <div class="tag-code"><?= e($d['code']) ?></div>
        </div>
    <?php endforeach; ?>
    <?php if (!$devices): ?>
        <p class="muted">No devices to print.</p>
    <?php endif; ?>
</div>
<script src="<?= e(asset('vendor/qrcode-generator.min.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
