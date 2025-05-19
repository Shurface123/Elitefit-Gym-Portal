<?php
require_once '../models/User.php';

class AuthController {
    public static function login($conn, $email, $password) {
        $user = User::findByEmail($conn, $email);
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user'] = $user;
            header("Location: dashboard/" . strtolower($user['role']) . ".php");
            exit();
        } else {
            echo "Invalid credentials!";
        }
    }
}
