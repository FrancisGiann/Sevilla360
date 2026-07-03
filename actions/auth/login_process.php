<?php
// Start the session so we can remember the user after they log in
session_start();

// Connect to the database
require '../../config/db_connect.php';

// Check if the form was actually submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // Grab the data from the HTML form's 'name' attributes
    $email = $_POST['email'];
    $password = $_POST['password'];

    // Secure Database Query (Prepared Statements prevent SQL Injection hacking)
    $stmt = $conn->prepare("SELECT id, password_hash, role, is_verified FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    // check if we found a user with that email
    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        
        // Check if the user is verified
        if ($user['is_verified'] == 0) {
            echo "<script>
                alert('Please verify your email address first!'); 
                window.location.href = '../../auth.php?verify_email=" . urlencode($email) . "';
            </script>";
            exit();
        }

        //Verify the typed password against the encrypted one in the database
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


            // Redirect based on their role
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