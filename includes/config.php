<?php
/**
 * LearnX Robotics & IoT Inventory — configuration.
 * Adjust the database credentials if your MySQL setup differs from XAMPP defaults.
 */

define('DB_HOST', '127.0.0.1');
define('DB_PORT', 3306);
define('DB_NAME', 'learnx_inventory');
define('DB_USER', 'root');
define('DB_PASS', '');

define('APP_NAME', 'LearnX Inventory');
define('APP_TAGLINE', 'Robotics & IoT Devices Inventory Management');

/**
 * Public base URL used inside QR codes, e.g. 'http://192.168.1.20/Inventory Management System'.
 * Leave empty to auto-detect from the current request. Phones cannot reach "localhost",
 * so set this to your computer's LAN address if QR codes will be scanned by other devices.
 */
define('APP_URL', '');

/** Timezone used for all dates (borrow dates, due dates, overdue checks). */
define('APP_TIMEZONE', 'Pacific/Port_Moresby'); // matches this computer's Windows timezone (UTC+10)

/** Maximum number of days a student can borrow a device for. */
define('MAX_BORROW_DAYS', 30);

/** Device photo upload limit in bytes (2 MB). */
define('MAX_UPLOAD_BYTES', 2 * 1024 * 1024);

define('APP_ROOT', dirname(__DIR__));
define('UPLOAD_DIR', APP_ROOT . '/uploads/devices');
