<?php
/**
 * Database Connection
 * This file handles the database connection for EliteFit Gym
 */

function connectDB() {
    $host = 'localhost';
    $db = 'elitefitgym';
    $user = 'root';
    $pass = 'Confrontation@433';

    try {
        $conn = new PDO("mysql:host=$host;dbname=$db", $user, $pass);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $conn;
    } catch (PDOException $e) {
        die("Database Connection failed: " . $e->getMessage());
    }
}
?>

