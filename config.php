<?php
// Strict error reporting for production safety
error_reporting(E_ALL);
ini_set('display_errors', 0); // Set to 1 only during initial debugging

// Strict Timezone Localisation
date_default_timezone_set('Australia/Perth');

// Database Credentials (Update these with your cPanel MySQL details)
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_cpanel_dbname');
define('DB_USER', 'your_cpanel_dbuser');
define('DB_PASS', 'your_cpanel_dbpassword');

// Application URL (Used for generating secure client portal links)
define('APP_URL', 'https://accounting.jpwebsites.com.au'); // Ensure no trailing slash

// Admin Authentication (Change this immediately before deploying)
define('ADMIN_USER', 'jarrod');
define('ADMIN_PASS', 'ChangeThisPassword123!');

// Establish strict PDO Connection
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Fail silently in production to prevent credential leakage on screen
    die(json_encode(['error' => 'Database connection failed. Please verify config.php credentials within cPanel.']));
}