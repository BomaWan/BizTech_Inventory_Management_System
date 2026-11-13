<?php
/**
 * Dashboard layout header. Expects: $pageTitle (string), $active (nav key).
 * Optional: $pageSubtitle.
 */
$me = current_user();
$role = $me['role'] ?? 'student';

if ($role === 'admin') {
    $pending = pending_request_count();
    $overdue = overdue_count();
    $nav = [
        ['dashboard',  'admin/index.php',      'grid',    'Dashboard', 0],
        ['devices',    'admin/devices.php',    'cpu',     'Devices', 0],
        ['categories', 'admin/categories.php', 'tag',     'Categories', 0],
        ['requests',   'admin/requests.php',   'inbox',   'Requests', $pending],
        ['borrowing',  'admin/borrowing.php',  'repeat',  'Borrowing & Returns', $overdue],
        ['users',      'admin/users.php',      'users',   'Users / Students', 0],
        ['qr',         'admin/qr.php',         'qr',      'QR Codes', 0],
        ['reports',    'admin/reports.php',    'chart',   'Reports', 0],
        ['profile',    'admin/profile.php',    'user',    'My Profile', 0],
    ];
} else {
    $unread = unread_notifications((int)$me['id']);
    $nav = [
        ['dashboard',     'student/index.php',         'grid',    'Dashboard', 0],
        ['devices',       'student/devices.php',       'search',  'Find Devices', 0],
        ['scan',          'student/scan.php',          'scan',    'Scan QR Code', 0],
        ['requests',      'student/requests.php',      'inbox',   'My Requests', 0],
        ['borrowed',      'student/borrowed.php',      'package', 'My Borrowed Devices', 0],
        ['notifications', 'student/notifications.php', 'bell',    'Notifications', $unread],
        ['history',       'student/history.php',       'history', 'History', 0],
        ['profile',       'student/profile.php',       'user',    'My Profile', 0],
    ];
}
$active = $active ?? '';
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle ?? 'Dashboard') ?> · <?= e(APP_NAME) ?></title>
    <link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
</head>
<body class="app">
<aside class="sidebar" id="sidebar">
    <a class="sidebar-brand" href="<?= e(url($role === 'admin' ? 'admin/index.php' : 'student/index.php')) ?>">
        <img src="<?= e(asset('img/logo.svg')) ?>" alt="LearnX — The Future Learning">
    </a>
    <div class="sidebar-role"><?= icon($role === 'admin' ? 'shield' : 'user') ?> <?= $role === 'admin' ? 'Administrator' : 'Student Panel' ?></div>
    <nav class="sidebar-nav">
        <?php foreach ($nav as [$key, $href, $ic, $label, $badge]): ?>
            <a href="<?= e(url($href)) ?>" class="<?= $active === $key ? 'active' : '' ?>">
                <?= icon($ic) ?><span><?= e($label) ?></span>
                <?php if ($badge > 0): ?><em class="nav-badge"><?= (int)$badge ?></em><?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>
    <form method="post" action="<?= e(url('logout.php')) ?>" class="sidebar-logout">
        <?= csrf_field() ?>
        <button type="submit"><?= icon('logout') ?><span>Logout</span></button>
    </form>
</aside>
<div class="sidebar-backdrop" data-close-sidebar></div>

<div class="main">
    <header class="topbar">
        <button class="icon-btn menu-btn" type="button" data-toggle-sidebar aria-label="Open menu"><?= icon('menu') ?></button>
        <div class="topbar-title">
            <h1><?= e($pageTitle ?? 'Dashboard') ?></h1>
            <?php if (!empty($pageSubtitle)): ?><p><?= e($pageSubtitle) ?></p><?php endif; ?>
        </div>
        <div class="topbar-right">
            <?php if ($role === 'student'): ?>
                <a class="icon-btn has-dot" href="<?= e(url('student/notifications.php')) ?>" aria-label="Notifications">
                    <?= icon('bell') ?><?php if (!empty($unread)): ?><i class="dot"></i><?php endif; ?>
                </a>
            <?php else: ?>
                <a class="icon-btn has-dot" href="<?= e(url('admin/requests.php?status=pending')) ?>" aria-label="Pending requests">
                    <?= icon('bell') ?><?php if (!empty($pending)): ?><i class="dot"></i><?php endif; ?>
                </a>
            <?php endif; ?>
            <a class="user-chip" href="<?= e(url($role === 'admin' ? 'admin/profile.php' : 'student/profile.php')) ?>">
                <span class="avatar"><?= e(initials($me['name'])) ?></span>
                <span class="user-chip-text"><strong><?= e($me['name']) ?></strong><small><?= $role === 'admin' ? 'Administrator' : e($me['student_id'] ?: 'Student') ?></small></span>
            </a>
        </div>
    </header>

    <main class="content">
        <?php foreach (take_flashes() as $f): ?>
            <div class="alert alert-<?= e($f['type']) ?>" data-dismissable>
                <?= icon($f['type'] === 'success' ? 'check' : ($f['type'] === 'error' ? 'alert' : 'bell')) ?>
                <div><?= e($f['message']) ?></div>
                <button type="button" class="alert-close" aria-label="Dismiss">&times;</button>
            </div>
        <?php endforeach; ?>
