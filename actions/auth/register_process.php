<?php
session_start();
require '../../config/db_connect.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $first_name = $_POST['first_name'];
    $last_name = $_POST['last_name'];
    $email = $_POST['email'];
    $dob = $_POST['dob'];
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    // 1. Basic validation
    if ($password !== $confirm_password) {
        echo "<script>alert('Passwords do not match!'); window.history.back();</script>";
        exit();
    }

    // 2. Hash the password for security
    $hashed_password = password_hash($password, PASSWORD_BCRYPT);

    try {
        // START TRANSACTION
        $conn->begin_transaction();

        // 3. Insert into USERS table
        $stmt1 = $conn->prepare("INSERT INTO users (email, password_hash, role) VALUES (?, ?, 'customer')");
        $stmt1->bind_param("ss", $email, $hashed_password);
        $stmt1->execute();
        
        // Get the ID that was just created for this user
        $new_user_id = $conn->insert_id; 

        // 4. Insert into CUSTOMERS table
        $stmt2 = $conn->prepare("INSERT INTO customers (user_id, first_name, last_name, email, dob) VALUES (?, ?, ?, ?, ?)");
        $stmt2->bind_param("issss", $new_user_id, $first_name, $last_name, $email, $dob);
        $stmt2->execute();

        // COMMIT TRANSACTION (Save to database)
        $conn->commit();

        echo "<script>alert('Account created successfully! Please log in.'); window.location.href='../../auth.php';</script>";

    } catch (Exception $e) {
        // ROLLBACK IF ERROR (e.g., email already exists)
        $conn->rollback();
        echo "<script>alert('Error: Email might already be registered.'); window.history.back();</script>";
    }

    $stmt1->close();
    $stmt2->close();
}
$conn->close();
?>