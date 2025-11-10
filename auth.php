<?php
session_start();
require_once 'config.php';

function isLoggedIn()
{
    return isset($_SESSION['user_id']);
}

function requireLogin()
{
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit();
    }
}

/**
 * Attempts to log in a user.
 * If successful, sets session data and redirects to the appropriate dashboard by role.
 * Returns true if login was successful, false otherwise.
 */
function login($username, $password)
{
    global $mysqli;

    $stmt = $mysqli->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();

    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    // echo $user['username'];
    // echo $password;
    // echo $user['password'];
    // echo $user['role'];
    // echo password_verify($password, $user['password']);
    if ($user && $password && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];

        // Role based redirection
        switch ($user['role']) {
            case 'admin':
                header('Location: admin_dashboard.php');
                break;
            case 'rep':
                header('Location: /ref/refs/ref_dashboard.php');
                break;
            case 'representative':
                header('Location: /ref/leader/leader_dashboard.php');
                break;
            default:
                // Unknown role - redirect to a safe place or logout
                header('Location: login.php');
        }
        exit;
    }

    return false;
}

function logout()
{
    session_unset();
    session_destroy();
    header('Location: login.php');
    exit();
}
?>