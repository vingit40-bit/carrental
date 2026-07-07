<?php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root'); // Change as needed
define('DB_PASSWORD', ''); // Change as needed
define('DB_NAME', 'car_rental');

// Create connection
function getConnection() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
    
    // Check connection
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    
    return $conn;
}