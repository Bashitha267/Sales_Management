<?php
// session_start();

// --- Your Local MySQL Database Details ---

$dbHost = 'localhost';     // Or '127.0.0.1'
$dbPort = '3306';          // Default MySQL port
$dbName = 'ref'; // <-- CHANGE THIS
$dbUser = 'root';          // <-- CHANGE THIS (common default)
$dbPassword = '1234';           // <-- CHANGE THIS (common default is empty)

try {
    // 1. Enable error reporting for mysqli
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    // 2. Create the mysqli connection object
    $mysqli = new mysqli(
        $dbHost,
        $dbUser,
        $dbPassword,
        $dbName,
        // (int)$dbPort // Port must be an integer
    );

    // 3. Set the character set (good practice)
    // $mysqli->set_charset("utf8mb4");

    // If you get to this line, the connection is successful!
    // echo "MySQL connection successful!"; // Uncomment to test

} catch (mysqli_sql_exception $e) {
    // If connection fails, stop the script and show the error
    die("MySQL connection failed: " . $e->getMessage());
}

// You can now use the $mysqli object to run queries
// Example: $result = $mysqli->query("SELECT * FROM users");