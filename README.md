# LearnX Inventory — Robotics & IoT Devices

A web app for managing robotics and IoT lab equipment, built to the requirements in
`Inventory Management System.pdf`. It uses PHP 8 and MySQL/MariaDB and runs on XAMPP.
The colour scheme comes from the LearnX logo: navy `#062551`, sky blue `#0d9dd2` and red `#a60612`.

## Running it

1. Open the **XAMPP Control Panel** and start **Apache** and **MySQL**.
2. In a browser, go to **http://localhost/Inventory%20Management%20System/**

On the first visit the app creates the `learnx_inventory` database and its tables, then adds starter
data: 5 categories (Robotics, IoT, Sensors, Boards, Motors) and 12 sample devices.
There is no SQL file to import.

| Role    | Login                                    | Password      |
|---------|------------------------------------------|---------------|
| Admin   | `admin@learnx.local`                     | `Admin@123`   |
| Student | `student@learnx.local` or ID `STU-0001`  | `Student@123` |

Change these passwords after you first log in (go to **My Profile**).

## Configuration (`includes/config.php`)

- **Database:** `DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME`. The defaults match a standard XAMPP install (`root` with no password).
- **`APP_URL`:** the address that goes inside QR codes. Leave it empty to use automatic detection: when you
  open the site on `localhost`, the QR codes use this PC's Wi-Fi/LAN address instead (for example
  `http://192.168.1.195/…`), so phones on the same network can open them. Set it yourself
  (for example `http://192.168.1.20/Inventory Management System`) if you want a fixed address.
- **`APP_TIMEZONE`:** used for due dates and overdue checks.
- **`MAX_BORROW_DAYS`:** the longest borrowing period a student can choose.

## How a request moves through the system

```
Student requests (pending) → Admin approves (stock reserved) → Admin marks collected (due date starts)
   → Student presses Return (return pending) → Admin marks returned (condition: good / damaged)
```

- **Available units** = total − damaged − under repair − units approved, borrowed or awaiting return confirmation.
- A loan is **overdue** when its due date has passed and it hasn't been returned. Overdue loans are shown in red.
- **Return reminders** are sent to the student once a day when a loan is due within 24 hours or is already overdue.
- When an admin rejects a request they must give a reason. The student sees it in their notifications.

## Features

**Admin:**
- A dashboard showing totals (devices, available, borrowed, students, pending requests) and availability by category.
- Managing devices (with photos, "mark as damaged" and "under repair"), categories, requests and borrowing/returns.
- Managing users: add, edit, block, remove and reset passwords.
- A QR tag for each device that shows its name, **location** and code. You can download one label (PNG),
  download all of them as a ZIP, or print a sticker sheet on A4 (40, 55 or 75 mm stickers). You can also regenerate a device's QR code.
- Reports: low-stock alerts, most borrowed devices and items borrowed this month.

**Student:**
- Search for devices and filter by category.
- Open a device to see its details and send a request (quantity, days, reason). Requests can be cancelled.
- My Requests, My Borrowed Devices (with a Return button and overdue warnings), and Notifications.
- History of past borrowing, and a profile page with password change.
- Scan a device's QR code using the camera, a photo of the label, or by typing the device code.

**Public:** a landing page, and the scan page (`scan.php?code=…`).

## QR tags: scan to locate a device

1. Go to **Admin → QR Codes**. Download the labels (one at a time or as a ZIP) or click **Print sticker sheet**.
   Print at 100% scale.
2. Stick each tag on its device, or on the box or drawer it's kept in.
3. Scan the tag with any phone camera. No app or login is needed. The page shows:
   - **Where to find it:** the device's location in the lab (set in **Devices → Edit**)
   - how many units are available, on loan, reserved and damaged or under repair
   - when the next borrowed unit is due back
   - **for admins only:** who has each borrowed unit, with their contact details and due date. Overdue loans are shown in red.

The phone must be on the **same Wi-Fi network** as the PC running XAMPP. Windows Firewall must also allow Apache
(XAMPP sets this up the first time Apache starts). If the router later gives the PC a different address, labels
printed earlier will stop working. To avoid this, reserve the PC's address in the router, or set `APP_URL`.

The in-app camera scanner (**Student → Scan QR Code**) needs `localhost` or `https://`, because browsers block
camera access on plain-`http` network addresses. Scanning a photo of the label works on any address.

## Security

- Passwords are stored as bcrypt hashes. Every form has CSRF protection.
- All database queries use prepared statements (PDO). Output is HTML-escaped.
- A user's role is checked on every page. Blocked users are signed out on their next request.
- Logins are throttled after 5 failed attempts.
- Uploaded files are checked by their actual content type. Scripts can't run from the `uploads/` folder.

## Folder structure

```
index.php, login.php, register.php, logout.php, scan.php   public pages
admin/      dashboard, devices, categories, requests, borrowing, users, qr, qr_print, reports, profile
student/    dashboard, devices, device, scan, requests, borrowed, notifications, history, profile
includes/   config, database setup, helpers, layout
assets/     css, js, images, vendor (qrcode-generator 1.4.4, html5-qrcode 2.3.8, jsQR 1.4.0, JSZip 3.10.1, stored locally so they work offline)
uploads/    device photos
```

To reset everything, drop the `learnx_inventory` database in phpMyAdmin and reload the site.
The app will recreate it with the starter data.

## Device photos

The starter devices use real photos from Wikimedia Commons. They are stored in `uploads/devices/` as `seed_*` files.
Each photo is under a free licence (CC BY, CC BY-SA or CC0), and its author and licence are listed in
`includes/photo_credits.json`. The device detail page and the QR scan page show this credit under the photo,
which the licences require.

Photos that admins upload replace these and need no credit. The device list itself is in `device_catalog()` in `includes/db.php`.
