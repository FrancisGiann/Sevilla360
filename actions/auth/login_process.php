<?php
// 1. Start the session so we can remember the user after they log in
session_start();

// 2. Connect to the database
require 'config/db_connect.php';

// 3. Check if the form was actually submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // Grab the data from the HTML form's 'name' attributes
    $email = $_POST['email'];
    $password = $_POST['password'];

    // 4. Secure Database Query (Prepared Statements prevent SQL Injection hacking)
    $stmt = $conn->prepare("SELECT id, password_hash, role FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    // 5. Did we find an account with that email?
    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();

        // 6. Verify the typed password against the encrypted one in the database
        if (password_verify($password, $user['password_hash'])) {
            
            // Success! Save user data into the Session
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['logged_in'] = true;

            // 7. Redirect based on their role
            if ($user['role'] === 'admin' || $user['role'] === 'superadmin') {
                header("Location: ../admin_dashboard.php");
            } else {
                header("Location: ../user_dashboard.php");
            }
            exit();

        } else {
            // Password was wrong
            echo "<script>alert('Incorrect password!'); window.history.back();</script>";
        }
    } else {
        // Email not found
        echo "<script>alert('Email not found!'); window.history.back();</script>";
    }

    $stmt->close();
}
$conn->close();
?>