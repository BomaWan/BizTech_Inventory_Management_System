<?php
/**
 * Database connection + automatic first-run installation.
 * On first request the database, tables and starter data are created automatically.
 */

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        $server = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=utf8mb4', DB_USER, DB_PASS, $options);
        $server->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $server = null;
        $pdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        http_response_code(500);
        echo '<!doctype html><meta charset="utf-8"><title>Database error</title>'
            . '<div style="font-family:system-ui;max-width:640px;margin:60px auto;padding:24px;border:1px solid #f1c4c7;border-radius:12px;background:#fff6f6;color:#5a0a10">'
            . '<h2 style="margin-top:0;color:#a60612">Cannot connect to MySQL</h2>'
            . '<p>Make sure MySQL is running in the XAMPP Control Panel, then refresh this page.</p>'
            . '<p>Settings are in <code>includes/config.php</code>.</p>'
            . '<pre style="white-space:pre-wrap;font-size:12px;color:#555">' . htmlspecialchars($e->getMessage()) . '</pre></div>';
        exit;
    }

    install_if_needed($pdo);
    return $pdo;
}

function install_if_needed(PDO $pdo): void
{
    $exists = $pdo->query("SHOW TABLES LIKE 'users'")->fetchColumn();
    if ($exists) {
        return;
    }

    $schema = [
        "CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            role ENUM('admin','student') NOT NULL DEFAULT 'student',
            name VARCHAR(120) NOT NULL,
            email VARCHAR(190) NOT NULL UNIQUE,
            student_id VARCHAR(50) NULL UNIQUE,
            course VARCHAR(150) NULL,
            phone VARCHAR(40) NULL,
            password_hash VARCHAR(255) NOT NULL,
            status ENUM('active','blocked') NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL,
            last_login DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS categories (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL UNIQUE,
            description VARCHAR(255) NULL,
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS devices (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            category_id INT UNSIGNED NULL,
            code VARCHAR(40) NOT NULL UNIQUE,
            name VARCHAR(150) NOT NULL,
            description TEXT NULL,
            photo VARCHAR(255) NULL,
            location VARCHAR(150) NULL,
            quantity_total INT UNSIGNED NOT NULL DEFAULT 0,
            quantity_damaged INT UNSIGNED NOT NULL DEFAULT 0,
            quantity_repair INT UNSIGNED NOT NULL DEFAULT 0,
            low_stock_threshold INT UNSIGNED NOT NULL DEFAULT 2,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            CONSTRAINT fk_devices_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS requests (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            device_id INT UNSIGNED NOT NULL,
            quantity INT UNSIGNED NOT NULL DEFAULT 1,
            days INT UNSIGNED NOT NULL,
            reason TEXT NOT NULL,
            status ENUM('pending','approved','rejected','cancelled','collected','return_pending','returned') NOT NULL DEFAULT 'pending',
            reject_reason TEXT NULL,
            requested_at DATETIME NOT NULL,
            decided_at DATETIME NULL,
            decided_by INT UNSIGNED NULL,
            collected_at DATETIME NULL,
            due_date DATETIME NULL,
            return_requested_at DATETIME NULL,
            returned_at DATETIME NULL,
            return_condition ENUM('good','damaged') NULL,
            reminder_sent_on DATE NULL,
            INDEX idx_requests_status (status),
            CONSTRAINT fk_requests_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_requests_device FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE,
            CONSTRAINT fk_requests_admin FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS notifications (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            type ENUM('approved','rejected','collected','returned','reminder','overdue','info') NOT NULL DEFAULT 'info',
            message VARCHAR(500) NOT NULL,
            request_id INT UNSIGNED NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            INDEX idx_notifications_user (user_id, is_read),
            CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_notifications_request FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    foreach ($schema as $sql) {
        $pdo->exec($sql);
    }

    seed_data($pdo);
}

function seed_data(PDO $pdo): void
{
    $now = date('Y-m-d H:i:s');

    $u = $pdo->prepare('INSERT INTO users (role,name,email,student_id,course,password_hash,status,created_at) VALUES (?,?,?,?,?,?,?,?)');
    $u->execute(['admin', 'System Administrator', 'admin@learnx.local', null, null, password_hash('Admin@123', PASSWORD_DEFAULT), 'active', $now]);
    $u->execute(['student', 'Demo Student', 'student@learnx.local', 'STU-0001', 'Diploma in Electronics & Robotics', password_hash('Student@123', PASSWORD_DEFAULT), 'active', $now]);

    $categories = [
        'Robotics' => 'Robot kits, arms, chassis and complete robotic platforms',
        'IoT'      => 'Connected modules, gateways and wireless communication',
        'Sensors'  => 'Environmental, motion, distance and other sensing modules',
        'Boards'   => 'Microcontrollers and single-board computers',
        'Motors'   => 'Servo, stepper and DC motors plus motor drivers',
    ];
    $c = $pdo->prepare('INSERT INTO categories (name,description,created_at) VALUES (?,?,?)');
    $catIds = [];
    foreach ($categories as $name => $desc) {
        $c->execute([$name, $desc, $now]);
        $catIds[$name] = (int)$pdo->lastInsertId();
    }

    sync_device_catalog($pdo);
}

/**
 * Starter device catalogue: [category, name, description, location, quantity, low-stock level, photo].
 * Photos live in uploads/devices/ (seed_*.jpg/png). They are real photos from Wikimedia Commons;
 * authors and licences are listed in includes/photo_credits.json.
 */
function device_catalog(): array
{
    return [
        ['Boards',   'Arduino Uno R3',               'ATmega328P microcontroller board with 14 digital I/O pins, 6 analog inputs and USB programming.', 'Lab A - Cabinet 1', 20, 3, 'seed_arduino_uno.jpg'],
        ['Boards',   'Raspberry Pi 4 Model B (4GB)', 'Quad-core single-board computer with Wi-Fi, Bluetooth, dual micro-HDMI and 40-pin GPIO.',      'Lab A - Cabinet 2', 8, 2,  'seed_rpi4.jpg'],
        ['IoT',      'ESP32 DevKit V1',              'Dual-core Wi-Fi + Bluetooth microcontroller ideal for IoT prototypes.',                         'Lab B - Drawer 3',  15, 3, 'seed_esp32.jpg'],
        ['IoT',      'LoRa SX1278 Module',           'Long-range, low-power 433 MHz radio transceiver (Ra-02) with antenna for sensor networks.',     'Lab B - Drawer 4',  6, 2,  'seed_lora_ra02.png'],
        ['Sensors',  'HC-SR04 Ultrasonic Sensor',    'Non-contact distance measurement from 2 cm to 400 cm.',                                        'Lab A - Bin 5',     25, 5, 'seed_hcsr04.jpg'],
        ['Sensors',  'DHT22 Temperature & Humidity', 'Digital temperature and humidity sensor with high accuracy.',                                  'Lab A - Bin 6',     12, 3, 'seed_dht22.jpg'],
        ['Sensors',  'MPU6050 Accelerometer/Gyro',   '6-axis motion tracking module (GY-521) with I2C interface.',                                   'Lab A - Bin 7',     3, 3,  'seed_mpu6050.jpg'],
        ['Motors',   'SG90 Micro Servo',             '9 g micro servo motor with 180-degree rotation.',                                              'Lab C - Shelf 1',   30, 5, 'seed_sg90.jpg'],
        ['Motors',   'NEMA 17 Stepper Motor',        '1.8-degree bipolar stepper motor for precise positioning.',                                    'Lab C - Shelf 2',   10, 2, 'seed_nema17.jpg'],
        ['Motors',   'L298N Motor Driver',           'Dual H-bridge driver for DC and stepper motors up to 2 A per channel.',                        'Lab C - Shelf 2',   14, 3, null],
        ['Robotics', '4WD Smart Robot Car Kit',      'Four-wheel-drive chassis kit with motors, wheels, battery holder and line-tracking sensors.',   'Robotics Bay',      5, 1,  null],
        ['Robotics', '6-DOF Robotic Arm Kit',        'Six-axis desktop robotic arm with metal servos and gripper.',                                  'Robotics Bay',      2, 1,  null],

        // Additional IoT devices
        ['IoT',      'NodeMCU ESP8266 Dev Board',    'ESP-12E Wi-Fi module on a breadboard-friendly board with USB-to-serial and 3.3 V regulator. Programmable with the Arduino IDE or MicroPython.', 'Lab B - Drawer 1', 12, 3, 'seed_nodemcu_esp8266.jpg'],
        ['IoT',      'ESP32-CAM Camera Module',      'ESP32 board with OV2640 camera, microSD slot and on-board flash LED. Used for Wi-Fi video streaming and image-capture projects.',             'Lab B - Drawer 2', 6, 2,  'seed_esp32_cam.jpg'],
        ['IoT',      'Raspberry Pi Zero 2 W',        'Compact quad-core single-board computer with Wi-Fi and Bluetooth, ideal for IoT gateways and headless projects.',                          'Lab A - Cabinet 2', 6, 2, 'seed_pi_zero_2w.jpg'],
        ['IoT',      'nRF24L01+ 2.4 GHz Transceiver','Low-power 2.4 GHz radio (Nordic nRF24L01+) for short-range wireless links between microcontrollers. SPI interface.',                      'Lab B - Drawer 5', 16, 4, 'seed_nrf24l01.jpg'],
        ['IoT',      'RFID-RC522 Reader Kit',        '13.56 MHz MFRC522 RFID reader/writer module with card and key-fob tags. SPI interface, 3.3 V.',                                           'Lab B - Drawer 6', 10, 3, 'seed_rc522.jpg'],
        ['IoT',      '5V Single-Channel Relay Module','Opto-isolated relay board (SRD-05VDC-SL-C) for switching mains or DC loads from a microcontroller, for home-automation projects.',        'Lab B - Bin 7',    20, 5, 'seed_relay_1ch.jpg'],
        ['IoT',      'Raspberry Pi Pico W',          'RP2040 microcontroller board with on-board Wi-Fi (CYW43439). Programmable in MicroPython or C/C++, ideal for low-cost connected sensors.',  'Lab A - Cabinet 3', 10, 3, 'seed_pico_w.jpg'],
        ['IoT',      'XBee-PRO 900 XSC RF Module',   'Digi XBee-PRO 900 MHz long-range radio with RPSMA antenna connector, for mesh and point-to-multipoint sensor networks.',                  'Lab B - Drawer 8', 4, 1,  'seed_xbee_pro.jpg'],
        ['Sensors',  'BME280 Environmental Sensor',  'Bosch BME280 breakout measuring temperature, humidity and barometric pressure over I2C/SPI. Ideal for IoT weather stations.',            'Lab A - Bin 8',    8, 2,  'seed_bme280.jpg'],
    ];
}

/**
 * Adds catalogue devices that are missing (matched by name) and fills in photos for catalogue
 * devices that have none. Safe to run more than once; never changes quantities or admin edits.
 */
function sync_device_catalog(PDO $pdo): array
{
    $now = date('Y-m-d H:i:s');
    $catIds = $pdo->query('SELECT name, id FROM categories')->fetchAll(PDO::FETCH_KEY_PAIR);
    $find = $pdo->prepare('SELECT id, photo FROM devices WHERE name = ?');
    $ins = $pdo->prepare('INSERT INTO devices (category_id,code,name,description,location,quantity_total,low_stock_threshold,photo,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $setPhoto = $pdo->prepare('UPDATE devices SET photo = ?, updated_at = ? WHERE id = ? AND photo IS NULL');
    $added = $photos = 0;
    foreach (device_catalog() as [$cat, $name, $desc, $loc, $qty, $low, $photo]) {
        $find->execute([$name]);
        $row = $find->fetch();
        if (!$row) {
            if (!isset($catIds[$cat])) {
                $pdo->prepare('INSERT INTO categories (name, created_at) VALUES (?, ?)')->execute([$cat, $now]);
                $catIds[$cat] = (int)$pdo->lastInsertId();
            }
            $ins->execute([$catIds[$cat], generate_device_code(), $name, $desc, $loc, $qty, $low, $photo, $now, $now]);
            $added++;
        } elseif ($photo && $row['photo'] === null) {
            $setPhoto->execute([$photo, $now, $row['id']]);
            $photos += $setPhoto->rowCount();
        }
    }
    return ['added' => $added, 'photos' => $photos];
}
