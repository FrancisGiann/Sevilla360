<?php
session_start();
require '../../config/db_connect.php';

// =========================================================================
// DATABASE HYGIENE: The "Piggyback" Auto-Delete
// Automatically delete unverified accounts that are older than 24 hours.
// =========================================================================
$cleanup_stmt = $conn->prepare("
    DELETE FROM users 
    WHERE is_verified = FALSE 
    AND verification_expires_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
");
$cleanup_stmt->execute();
$cleanup_stmt->close();
// =========================================================================

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    //  Sanitize and retrieve POST data
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $email = trim($_POST['email']);
    $dob = $_POST['dob'];
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    //  Basic Validation
    if ($password !== $confirm_password) {
        echo "<script>alert('Passwords do not match!'); window.history.back();</script>";
        exit();
    }

    if (empty($first_name) || empty($last_name) || empty($email) || empty($password)) {
        echo "<script>alert('Please fill in all required fields.'); window.history.back();</script>";
        exit();
    }

    // Hash the password
    $hashed_password = password_hash($password, PASSWORD_BCRYPT);

    //  Generate Verification Details
    // Creates a random 6-digit string (e.g., "049215")
    $verification_code = sprintf("%06d", random_int(1, 999999));
    
    // Set expiration time to 15 minutes from now (Matches the Philippine Timezone set in db_connect.php)
    $expires_at = date('Y-m-d H:i:s', strtotime('+15 minutes'));

    try {
        // START TRANSACTION
        $conn->begin_transaction();

        //  Insert into USERS table
        // We set role = 'customer' and pass the verification code and expiration
        $stmt1 = $conn->prepare("INSERT INTO users (email, password_hash, role, verification_code, verification_expires_at) VALUES (?, ?, 'customer', ?, ?)");
        $stmt1->bind_param("ssss", $email, $hashed_password, $verification_code, $expires_at);
        $stmt1->execute();
        
        // Grab the auto-incremented ID of the user we just created
        $new_user_id = $conn->insert_id; 

        //  Insert into CUSTOMERS table
        $stmt2 = $conn->prepare("INSERT INTO customers (user_id, first_name, last_name, email, dob) VALUES (?, ?, ?, ?, ?)");
        $stmt2->bind_param("issss", $new_user_id, $first_name, $last_name, $email, $dob);
        $stmt2->execute();

        // COMMIT TRANSACTION (Save both queries to database)
        $conn->commit();

        // Send to Verification Page
        // NOTE: In production, you will use PHPMailer to send $verification_code to $email here.
        // For development, we alert the code so you can copy/paste it into the modal!
        
        echo "<script>
            alert('DEVELOPMENT MODE:\\nYour verification code is: " . $verification_code . "');
            
            // Redirect back to auth.php and trigger the modal using the URL parameter
            window.location.href = '../../auth.php?verify_email=" . urlencode($email) . "';
        </script>";
        exit();

    } catch (Exception $e) {
        // 10. ROLLBACK IF ERROR (e.g., Email is already taken)
        $conn->rollback();
        
        // Check if it's a Duplicate Entry error (MySQL Error 1062)
        if ($conn->errno == 1062) {
            echo "<script>alert('Error: That email address is already registered.'); window.history.back();</script>";
        } else {
            // Generic error for debugging
            $error_message = $e->getMessage();
            echo "<script>alert('Database Error: " . addslashes($error_message) . "'); window.history.back();</script>";
        }
    }

    // Close statements
    if (isset($stmt1)) $stmt1->close();
    if (isset($stmt2)) $stmt2->close();
}

$conn->close();
?>