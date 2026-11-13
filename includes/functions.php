<?php
/**
 * Bootstrap + shared helpers. Every page includes this file first.
 */

require_once __DIR__ . '/config.php';

date_default_timezone_set(APP_TIMEZONE);
error_reporting(E_ALL);
ini_set('display_errors', '0');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('LEARNXINV');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/icons.php';

/* ------------------------------------------------------------------ output */

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function fmt_date(?string $dt, bool $withTime = false): string
{
    if (!$dt) {
        return '—';
    }
    $ts = strtotime($dt);
    return $withTime ? date('d M Y, H:i', $ts) : date('d M Y', $ts);
}

function time_ago(string $dt): string
{
    $diff = time() - strtotime($dt);
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . ' min ago';
    if ($diff < 86400) return floor($diff / 3600) . ' h ago';
    if ($diff < 604800) return floor($diff / 86400) . ' d ago';
    return fmt_date($dt);
}

/* --------------------------------------------------------------- URL paths */

/** URL path of the application root, e.g. "/Inventory%20Management%20System". */
function base_path(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $root   = str_replace('\\', '/', realpath(APP_ROOT));
    $script = str_replace('\\', '/', realpath($_SERVER['SCRIPT_FILENAME'] ?? '') ?: '');
    $name   = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $rel    = (stripos($script, $root) === 0) ? substr($script, strlen($root)) : '';
    $raw    = ($rel !== '' && strlen($name) >= strlen($rel)) ? substr($name, 0, strlen($name) - strlen($rel)) : '';
    $raw    = rtrim($raw, '/');
    $base   = implode('/', array_map('rawurlencode', explode('/', $raw)));
    return $base;
}

function url(string $path = ''): string
{
    return base_path() . '/' . ltrim($path, '/');
}

/** Absolute URL (used inside QR codes). */
function absolute_url(string $path = ''): string
{
    if (APP_URL !== '') {
        $base = rtrim(APP_URL, '/');
        $parts = parse_url($base);
        if (!empty($parts['path'])) {
            $encoded = implode('/', array_map(fn($s) => rawurlencode(rawurldecode($s)), explode('/', $parts['path'])));
            $base = substr($base, 0, strlen($base) - strlen($parts['path'])) . $encoded;
        }
        return $base . '/' . ltrim($path, '/');
    }
    $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    $scheme = $https ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . url($path);
}

function redirect(string $path): void
{
    header('Location: ' . url($path));
    exit;
}

function asset(string $path): string
{
    $file = APP_ROOT . '/assets/' . $path;
    $v = is_file($file) ? filemtime($file) : 1;
    return url('assets/' . $path) . '?v=' . $v;
}

/* ------------------------------------------------------------ flash + csrf */

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

/** Validates the CSRF token on POST requests; aborts on mismatch. */
function verify_csrf(): void
{
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(419);
        flash('error', 'Your session expired. Please try again.');
        $back = $_SERVER['HTTP_REFERER'] ?? url('index.php');
        header('Location: ' . $back);
        exit;
    }
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function post_str(string $key, int $max = 1000): string
{
    $v = $_POST[$key] ?? '';
    return is_string($v) ? mb_substr(trim($v), 0, $max) : '';
}

function post_int(string $key, int $default = 0): int
{
    $v = $_POST[$key] ?? null;
    return (is_scalar($v) && is_numeric($v)) ? (int)$v : $default;
}

function get_str(string $key, int $max = 200): string
{
    $v = $_GET[$key] ?? '';
    return is_string($v) ? mb_substr(trim($v), 0, $max) : '';
}

function get_int(string $key, int $default = 0): int
{
    $v = $_GET[$key] ?? null;
    return (is_scalar($v) && is_numeric($v)) ? (int)$v : $default;
}

/* ------------------------------------------------------------------- auth */

function current_user(): ?array
{
    static $user = false;
    if ($user !== false) {
        return $user;
    }
    $user = null;
    if (!empty($_SESSION['uid'])) {
        $st = db()->prepare('SELECT * FROM users WHERE id = ?');
        $st->execute([$_SESSION['uid']]);
        $row = $st->fetch();
        if ($row && $row['status'] === 'active') {
            $user = $row;
        } else {
            unset($_SESSION['uid'], $_SESSION['role']);
            if ($row && $row['status'] === 'blocked') {
                flash('error', 'Your account has been blocked. Please contact the administrator.');
            }
        }
    }
    return $user;
}

function require_role(string $role): array
{
    $user = current_user();
    if (!$user) {
        flash('error', 'Please log in to continue.');
        redirect('login.php?role=' . $role);
    }
    if ($user['role'] !== $role) {
        redirect($user['role'] === 'admin' ? 'admin/index.php' : 'student/index.php');
    }
    return $user;
}

function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$user['id'];
    $_SESSION['role'] = $user['role'];
    db()->prepare('UPDATE users SET last_login = ? WHERE id = ?')->execute([now(), $user['id']]);
}

function password_problem(string $password): ?string
{
    if (strlen($password) < 8) {
        return 'Password must be at least 8 characters long.';
    }
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        return 'Password must contain both letters and numbers.';
    }
    return null;
}

function random_password(): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $out = '';
    for ($i = 0; $i < 8; $i++) {
        $out .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $out . random_int(10, 99);
}

/* -------------------------------------------------------------- inventory */

function generate_device_code(): string
{
    return 'DEV-' . strtoupper(bin2hex(random_bytes(4)));
}

/** Status values that hold stock out of the available pool. */
const OUT_STATUSES = "'approved','collected','return_pending'";

/** Base SELECT for devices with computed availability columns. */
function device_select_sql(): string
{
    return "SELECT d.*, c.name AS category_name,
            COALESCE(o.reserved_qty,0) AS reserved_qty,
            COALESCE(o.borrowed_qty,0) AS borrowed_qty,
            GREATEST(CAST(d.quantity_total AS SIGNED) - CAST(d.quantity_damaged AS SIGNED)
                     - CAST(d.quantity_repair AS SIGNED) - COALESCE(o.out_qty,0), 0) AS available
        FROM devices d
        LEFT JOIN categories c ON c.id = d.category_id
        LEFT JOIN (
            SELECT device_id,
                   CAST(SUM(quantity) AS SIGNED) AS out_qty,
                   CAST(SUM(CASE WHEN status = 'approved' THEN quantity ELSE 0 END) AS SIGNED) AS reserved_qty,
                   CAST(SUM(CASE WHEN status IN ('collected','return_pending') THEN quantity ELSE 0 END) AS SIGNED) AS borrowed_qty
            FROM requests WHERE status IN (" . OUT_STATUSES . ")
            GROUP BY device_id
        ) o ON o.device_id = d.id";
}

function find_device(int $id): ?array
{
    $st = db()->prepare(device_select_sql() . ' WHERE d.id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function find_device_by_code(string $code): ?array
{
    $st = db()->prepare(device_select_sql() . ' WHERE d.code = ?');
    $st->execute([$code]);
    return $st->fetch() ?: null;
}

function all_categories(): array
{
    return db()->query('SELECT c.*, (SELECT COUNT(*) FROM devices d WHERE d.category_id = c.id) AS device_count
                        FROM categories c ORDER BY c.name')->fetchAll();
}

function device_photo_url(?array $device): string
{
    if ($device && !empty($device['photo']) && is_file(UPLOAD_DIR . '/' . $device['photo'])) {
        return url('uploads/devices/' . rawurlencode($device['photo']));
    }
    return url('assets/img/device-placeholder.svg');
}

/** Attribution for bundled Wikimedia Commons photos (seed_* files), or null for admin uploads. */
function photo_credit(?string $photo): ?array
{
    static $credits = null;
    if (!$photo || !str_starts_with($photo, 'seed_')) {
        return null;
    }
    if ($credits === null) {
        $file = __DIR__ . '/photo_credits.json';
        $credits = is_file($file) ? (json_decode((string)file_get_contents($file), true) ?: []) : [];
    }
    return $credits[$photo] ?? null;
}

function photo_credit_html(?array $device): string
{
    $c = $device && is_file(UPLOAD_DIR . '/' . ($device['photo'] ?? '')) ? photo_credit($device['photo']) : null;
    if (!$c) {
        return '';
    }
    return '<div class="photo-credit">Photo: ' . e($c['author']) . ' · <a href="' . e($c['page']) . '" target="_blank" rel="noopener">'
        . e($c['license']) . ', Wikimedia Commons</a></div>';
}

/** This computer's private network (Wi-Fi/LAN) IPv4 address, or null if none is found. */
function lan_ip(): ?string
{
    static $ip = false;
    if ($ip !== false) {
        return $ip;
    }
    $ip = null;
    $candidates = @gethostbynamel(gethostname()) ?: [];
    if (!empty($_SERVER['SERVER_ADDR'])) {
        array_unshift($candidates, $_SERVER['SERVER_ADDR']);
    }
    $best = 99;
    foreach ($candidates as $c) {
        if (!filter_var($c, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) continue;
        $rank = str_starts_with($c, '192.168.') ? 1 : (str_starts_with($c, '10.') ? 2 : (preg_match('/^172\.(1[6-9]|2\d|3[01])\./', $c) ? 3 : 99));
        if ($rank < $best) {
            $best = $rank;
            $ip = $c;
        }
    }
    return $ip;
}

function is_loopback_host(string $host): bool
{
    $host = trim(strtolower($host), '[]');
    return $host === 'localhost' || $host === '::1' || str_starts_with($host, '127.');
}

/**
 * Base URL written into QR codes. Uses APP_URL when set; otherwise, if the site is being viewed on
 * "localhost", swaps in this computer's network address so phones on the same Wi-Fi can open it.
 */
function qr_base_url(): string
{
    if (APP_URL !== '') {
        return rtrim(absolute_url(''), '/');
    }
    $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $parts  = parse_url('http://' . $host);
    $name   = $parts['host'] ?? 'localhost';
    if (is_loopback_host($name) && ($ip = lan_ip())) {
        $host = $ip . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }
    return ($https ? 'https' : 'http') . '://' . $host . base_path();
}

/** Whether QR codes will only work on this computer (no network address could be used). */
function qr_is_local_only(): bool
{
    $host = parse_url(qr_base_url(), PHP_URL_HOST) ?: 'localhost';
    return is_loopback_host($host);
}

function scan_url(string $code): string
{
    return qr_base_url() . '/scan.php?code=' . rawurlencode($code);
}

/** Who currently holds units of a device (approved, borrowed or awaiting return). */
function device_holders(int $deviceId): array
{
    $st = db()->prepare("SELECT r.*, u.name AS user_name, u.student_id, u.phone, u.email
                         FROM requests r JOIN users u ON u.id = r.user_id
                         WHERE r.device_id = ? AND r.status IN ('approved','collected','return_pending')
                         ORDER BY (r.due_date IS NULL), r.due_date");
    $st->execute([$deviceId]);
    return $st->fetchAll();
}

/** Stores an uploaded device photo; returns new filename, null when none was sent. Throws on invalid file. */
function handle_photo_upload(string $field): ?string
{
    if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Photo upload failed (error code ' . (int)$f['error'] . ').');
    }
    if ($f['size'] > MAX_UPLOAD_BYTES) {
        throw new RuntimeException('Photo is too large. Maximum size is 2 MB.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $map = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    if (!isset($map[$mime])) {
        throw new RuntimeException('Photo must be a JPG, PNG, WEBP or GIF image.');
    }
    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0775, true);
    }
    $name = 'dev_' . bin2hex(random_bytes(8)) . '.' . $map[$mime];
    if (!move_uploaded_file($f['tmp_name'], UPLOAD_DIR . '/' . $name)) {
        throw new RuntimeException('Could not save the uploaded photo.');
    }
    return $name;
}

function delete_photo(?string $name): void
{
    if ($name && preg_match('/^dev_[a-f0-9]{16}\.(jpg|png|webp|gif)$/', $name)) {
        $path = UPLOAD_DIR . '/' . $name;
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

/* ---------------------------------------------------------- notifications */

function notify(int $userId, string $type, string $message, ?int $requestId = null): void
{
    db()->prepare('INSERT INTO notifications (user_id,type,message,request_id,created_at) VALUES (?,?,?,?,?)')
        ->execute([$userId, $type, $message, $requestId, now()]);
}

function unread_notifications(int $userId): int
{
    $st = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $st->execute([$userId]);
    return (int)$st->fetchColumn();
}

/** Creates (at most once per day per loan) return reminders for loans due within 24 h or overdue. */
function generate_return_reminders(int $userId): void
{
    $today = date('Y-m-d');
    $soon = date('Y-m-d H:i:s', time() + 86400);
    $st = db()->prepare("SELECT r.id, r.due_date, d.name FROM requests r JOIN devices d ON d.id = r.device_id
                         WHERE r.user_id = ? AND r.status = 'collected' AND r.due_date <= ?
                           AND (r.reminder_sent_on IS NULL OR r.reminder_sent_on < ?)");
    $st->execute([$userId, $soon, $today]);
    $upd = db()->prepare('UPDATE requests SET reminder_sent_on = ? WHERE id = ?');
    foreach ($st->fetchAll() as $loan) {
        if (strtotime($loan['due_date']) < time()) {
            notify($userId, 'overdue', 'OVERDUE: "' . $loan['name'] . '" was due on ' . fmt_date($loan['due_date'], true) . '. Please return it as soon as possible.', (int)$loan['id']);
        } else {
            notify($userId, 'reminder', 'Reminder: "' . $loan['name'] . '" is due on ' . fmt_date($loan['due_date'], true) . '.', (int)$loan['id']);
        }
        $upd->execute([$today, $loan['id']]);
    }
}

function pending_request_count(): int
{
    return (int)db()->query("SELECT COUNT(*) FROM requests WHERE status = 'pending'")->fetchColumn();
}

function overdue_count(): int
{
    $st = db()->prepare("SELECT COUNT(*) FROM requests WHERE status IN ('collected','return_pending') AND due_date < ?");
    $st->execute([now()]);
    return (int)$st->fetchColumn();
}

/* ------------------------------------------------------------- UI helpers */

function is_overdue(array $r): bool
{
    return in_array($r['status'], ['collected', 'return_pending'], true)
        && !empty($r['due_date']) && strtotime($r['due_date']) < time();
}

function status_badge(string $status, bool $overdue = false): string
{
    if ($overdue) {
        return '<span class="badge badge-danger">Overdue</span>';
    }
    $map = [
        'pending'        => ['Pending', 'badge-warning'],
        'approved'       => ['Approved', 'badge-info'],
        'rejected'       => ['Rejected', 'badge-danger'],
        'cancelled'      => ['Cancelled', 'badge-muted'],
        'collected'      => ['Borrowed', 'badge-primary'],
        'return_pending' => ['Return pending', 'badge-warning'],
        'returned'       => ['Returned', 'badge-success'],
        'active'         => ['Active', 'badge-success'],
        'blocked'        => ['Blocked', 'badge-danger'],
    ];
    [$label, $cls] = $map[$status] ?? [ucfirst($status), 'badge-muted'];
    return '<span class="badge ' . $cls . '">' . e($label) . '</span>';
}

function availability_badge(array $d): string
{
    $a = (int)$d['available'];
    if ($a <= 0) {
        return '<span class="badge badge-danger">Unavailable</span>';
    }
    if ($a <= (int)$d['low_stock_threshold']) {
        return '<span class="badge badge-warning">Low stock</span>';
    }
    return '<span class="badge badge-success">Available</span>';
}

function due_text(array $r): string
{
    if (empty($r['due_date'])) {
        return '—';
    }
    $secs = strtotime($r['due_date']) - time();
    $days = (int)ceil(abs($secs) / 86400);
    if ($secs < 0) {
        return 'Overdue by ' . $days . ' day' . ($days === 1 ? '' : 's');
    }
    if ($secs < 86400) {
        return 'Due within 24 h';
    }
    return $days . ' day' . ($days === 1 ? '' : 's') . ' left';
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    $s = mb_strtoupper(mb_substr($parts[0] ?? '', 0, 1));
    if (count($parts) > 1) {
        $s .= mb_strtoupper(mb_substr(end($parts), 0, 1));
    }
    return $s ?: '?';
}
