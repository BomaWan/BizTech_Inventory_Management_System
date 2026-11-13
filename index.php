<?php
require_once __DIR__ . '/includes/functions.php';

$me = current_user();
$dash = $me ? ($me['role'] === 'admin' ? 'admin/index.php' : 'student/index.php') : null;

$devices = db()->query(device_select_sql())->fetchAll();
$totalUnits = array_sum(array_map(fn($d) => (int)$d['quantity_total'], $devices));
$totalAvail = array_sum(array_map(fn($d) => (int)$d['available'], $devices));
$categories = db()->query("SELECT c.id, c.name FROM categories c ORDER BY c.name")->fetchAll();
$catStats = [];
foreach ($categories as $c) {
    $catStats[$c['id']] = ['name' => $c['name'], 'types' => 0, 'available' => 0];
}
foreach ($devices as $d) {
    if ($d['category_id'] && isset($catStats[$d['category_id']])) {
        $catStats[$d['category_id']]['types']++;
        $catStats[$d['category_id']]['available'] += (int)$d['available'];
    }
}
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(APP_NAME) ?> · Robotics &amp; IoT Devices</title>
    <link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
</head>
<body class="public">
<nav class="pub-nav">
    <a href="<?= e(url('index.php')) ?>"><img src="<?= e(asset('img/logo.svg')) ?>" alt="LearnX — The Future Learning"></a>
    <div class="links">
        <a class="text" href="#features">Features</a>
        <a class="text" href="#categories">Categories</a>
        <a class="text" href="#how">How it works</a>
        <?php if ($dash): ?>
            <a class="btn" href="<?= e(url($dash)) ?>"><?= icon('grid') ?> Go to dashboard</a>
        <?php else: ?>
            <a class="btn btn-light" href="<?= e(url('register.php')) ?>">Register</a>
            <a class="btn" href="<?= e(url('login.php')) ?>"><?= icon('lock') ?> Login</a>
        <?php endif; ?>
    </div>
</nav>

<header class="hero">
    <div class="hero-inner">
        <div>
            <span class="eyebrow"><i></i> LearnX Robotics &amp; IoT Lab</span>
            <h1>Borrow, track and manage <span>robotics &amp; IoT devices</span> with ease.</h1>
            <p class="lead">One place for students to find lab equipment and request it, and for administrators to manage stock, approvals, returns and QR-tagged devices.</p>
            <div class="cta">
                <?php if ($dash): ?>
                    <a class="btn btn-sky btn-lg" href="<?= e(url($dash)) ?>"><?= icon('grid') ?> Open my dashboard</a>
                <?php else: ?>
                    <a class="btn btn-sky btn-lg" href="<?= e(url('login.php?role=student')) ?>"><?= icon('user') ?> Student login</a>
                    <a class="btn btn-light btn-lg" href="<?= e(url('login.php?role=admin')) ?>"><?= icon('shield') ?> Admin login</a>
                <?php endif; ?>
            </div>
        </div>
        <div class="hero-card">
            <div class="row"><span class="ic navy"><?= icon('cpu') ?></span><div>Device types<small>Catalogued in the lab</small></div><b><?= count($devices) ?></b></div>
            <div class="row"><span class="ic"><?= icon('package') ?></span><div>Units available now<small>Out of <?= (int)$totalUnits ?> in total</small></div><b><?= (int)$totalAvail ?></b></div>
            <div class="row"><span class="ic red"><?= icon('tag') ?></span><div>Categories<small>Robotics, IoT, Sensors &amp; more</small></div><b><?= count($categories) ?></b></div>
        </div>
    </div>
</header>

<section class="section" id="features">
    <div class="section-head">
        <div class="kicker">Features</div>
        <h2>Everything the lab needs</h2>
        <p class="muted">Role-based access for students and administrators, live availability and QR-tagged equipment.</p>
    </div>
    <div class="features">
        <div class="feature"><div class="ic"><?= icon('search') ?></div><h3>Find devices fast</h3><p>Search by name, filter by category and see photo, description, location and live availability.</p></div>
        <div class="feature"><div class="ic"><?= icon('inbox') ?></div><h3>Request online</h3><p>Choose how many days you need a device, give a reason, and track the request from pending to returned.</p></div>
        <div class="feature"><div class="ic"><?= icon('qr') ?></div><h3>QR-tagged equipment</h3><p>Every device has a downloadable QR code. Scan it to see instantly how many units are available.</p></div>
        <div class="feature"><div class="ic"><?= icon('repeat') ?></div><h3>Borrowing &amp; returns</h3><p>Collection, due dates and returns are tracked, and overdue items are highlighted in red.</p></div>
        <div class="feature"><div class="ic"><?= icon('bell') ?></div><h3>Notifications</h3><p>Students get notified when requests are approved or rejected, and reminded before items are due.</p></div>
        <div class="feature"><div class="ic"><?= icon('chart') ?></div><h3>Reports &amp; alerts</h3><p>Low-stock alerts, most-borrowed devices and monthly borrowing reports for administrators.</p></div>
    </div>
</section>

<div class="cat-band" id="categories">
    <section class="section">
        <div class="section-head">
            <div class="kicker">Inventory</div>
            <h2>Browse by category</h2>
        </div>
        <div class="cat-tiles">
            <?php foreach ($catStats as $c): ?>
                <div class="cat-tile"><b><?= (int)$c['available'] ?></b><span><?= e($c['name']) ?></span><small><?= (int)$c['types'] ?> device type<?= $c['types'] == 1 ? '' : 's' ?> · units available</small></div>
            <?php endforeach; ?>
        </div>
    </section>
</div>

<section class="section" id="how">
    <div class="section-head">
        <div class="kicker">How it works</div>
        <h2>From request to return</h2>
    </div>
    <div class="steps">
        <div class="step"><h3>Log in</h3><p>Sign in with your student account, or register if you are new.</p></div>
        <div class="step"><h3>Request</h3><p>Find a device (or scan its QR code), choose the days and give a reason.</p></div>
        <div class="step"><h3>Collect</h3><p>Once approved, collect the device from the lab. Your due date starts then.</p></div>
        <div class="step"><h3>Return</h3><p>Return the device on time. You will get a reminder before it is due.</p></div>
    </div>
</section>

<footer class="pub-footer">
    <div><strong>LearnX</strong> — The Future Learning · <?= e(APP_TAGLINE) ?></div>
    <div>&copy; <?= date('Y') ?> LearnX</div>
</footer>
</body>
</html>
