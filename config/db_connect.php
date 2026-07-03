<?php
// 1. Set PHP Timezone
date_default_timezone_set('Asia/Manila');

// 2. Database credentials
$host = "localhost";
$username = "admin";
$password = "admin123";
$database = "sevilla360";

// 3. Create the connection FIRST
$conn = new mysqli($host, $username, $password, $database);

// 4. Check if the connection worked
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// 5. NOW you can set the MySQL Timezone (because $conn successfully exists!)
$conn->query("SET time_zone = '+08:00'");


?>