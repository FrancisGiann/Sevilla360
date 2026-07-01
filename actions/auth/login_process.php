<?php
// 1. Start the session so we can remember the user after they log in
session_start();

// 2. Connect to the database
require '../../config/db_connect.php';

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

            $display_name = 'Account'; 

            if ($user['role'] === 'customer') {
                $name_stmt = $conn->prepare("SELECT first_name FROM customers WHERE user_id = ?");
                $name_stmt->bind_param("i", $user['id']);
                $name_stmt->execute();
                $name_result = $name_stmt->get_result();
                
                if ($name_result->num_rows === 1) {
                    $customer_data = $name_result->fetch_assoc();
                    $display_name = $customer_data['first_name'];
                }
                $name_stmt->close();

            } else if ($user['role'] === 'admin' || $user['role'] === 'superadmin') {
                $name_stmt = $conn->prepare("SELECT full_name FROM staff WHERE user_id = ?");
                $name_stmt->bind_param("i", $user['id']);
                $name_stmt->execute();
                $name_result = $name_stmt->get_result();
                
                if ($name_result->num_rows === 1) {
                    $staff_data = $name_result->fetch_assoc();
                    $name_parts = explode(' ', trim($staff_data['full_name']));
                    $display_name = $name_parts[0]; 
                }
                $name_stmt->close();
            }

            $_SESSION['first_name'] = $display_name;


            // 7. Redirect based on their role
            if ($user['role'] === 'admin' || $user['role'] === 'superadmin') {
                header("Location: ../../admin_dashboard.php");
            } else {
                header("Location: ../../index.php");
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