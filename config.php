<?php
// Strict error reporting for production safety
error_reporting(0); // Disabled entirely for production
ini_set('display_errors', 0);
ini_set('log_errors', 1); // Log errors directly to cPanel error_log

// Secure Session Configuration
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1);
ini_set('session.use_strict_mode', 1);

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
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false); // Force native prepared statements
} catch (PDOException $e) {
    // Log internally but fail silently on screen to prevent credential leakage
    error_log("Database connection failed: " . $e->getMessage());
    die(json_encode(['error' => 'Database connection failed. Please verify config.php credentials within cPanel.']));
}