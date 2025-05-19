<?php
// Database configuration
$host = "localhost";
$username = "root";
$password = "Confrontation@433"; 
$database = "elitefitgym";

// Create database connection
$conn = new mysqli($host, $username, $password, $database);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set charset to ensure proper encoding
$conn->set_charset("utf8mb4");
