<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$items = ['admin123', 'faculty123', 'student123'];
foreach ($items as $value) {
    echo $value, ': ', password_hash($value, PASSWORD_DEFAULT), PHP_EOL;
}
