<?php
// Database configuration
define('DB_HOST', 'sql102.infinityfree.com');
define('DB_USER', 'if0_42361933'); // Change as needed
define('DB_PASSWORD', 'IbgkDFg65lPKR1'); // Change as needed
define('DB_NAME', 'if0_42361933_carrental');

// Create connection
function getConnection() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
    
    // Check connection
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    
    return $conn;
}