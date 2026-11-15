
<?php
date_default_timezone_set('Asia/Manila');

define(
    'APP_BASE_URL',
    rtrim(getenv('ATTENDANCE_BASE_URL') ?: 'http://localhost/Attendance', '/')
);

// Supabase PostgreSQL Configuration
$host = getenv('ATTENDANCE_DB_HOST') ?: 'aws-1-ap-northeast-1.pooler.supabase.com';
$port = getenv('ATTENDANCE_DB_PORT') ?: '5432';
$dbName = getenv('ATTENDANCE_DB_NAME') ?: 'postgres';
$dbUser = getenv('ATTENDANCE_DB_USER') ?: 'postgres.ppwrlfixllfyjhewaajj';
$dbPass = getenv('ATTENDANCE_DB_PASSWORD') ?: 'Ryu162006_';

try {

    $dsn = "pgsql:host=$host;port=$port;dbname=$dbName;sslmode=require";

    $conn = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);

} catch (PDOException $exception) {

    error_log('Attendance database connection failed: ' . $exception->getMessage());

    http_response_code(500);
    exit('Database connection failed. Please check your Supabase database configuration.');

}
