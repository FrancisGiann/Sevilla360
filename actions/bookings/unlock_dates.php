<?php
session_start();
require '../../config/db_connect.php';

// If the user has a locked room in their session, delete it from the database!
if (isset($_SESSION['locked_venue_id'])) {
    $venue_id = $_SESSION['locked_venue_id'];
    $session_id = session_id();

    $stmt = $conn->prepare("DELETE FROM booking_locks WHERE venue_id = ? AND session_id = ?");
    $stmt->bind_param("is", $venue_id, $session_id);
    $stmt->execute();
    $stmt->close();

    unset($_SESSION['locked_venue_id']);
    echo "Success|Unlocked";
} else {
    echo "Success|Nothing to unlock";
}
$conn->close();
?>