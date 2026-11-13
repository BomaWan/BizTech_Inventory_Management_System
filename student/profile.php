<?php
require_once __DIR__ . '/../includes/functions.php';
$me = require_role('student');
generate_return_reminders((int)$me['id']);
$profilePath = 'student/profile.php';
require __DIR__ . '/../includes/profile_page.php';
