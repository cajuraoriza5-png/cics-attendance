<?php
/**
 * Delete Individual User
 */

$conn = new mysqli("localhost","root","","attendance");

if($conn->connect_error){
    die("Connection failed: " . $conn->connect_error);
}

if(isset($_GET['id']) && is_numeric($_GET['id'])) {
    $user_id = intval($_GET['id']);
    
    // Get user info before deletion
    $user_info = $conn->query("SELECT username, role FROM users WHERE id = $user_id")->fetch_assoc();
    
    if($user_info) {
        // Delete related face data first
        $conn->query("DELETE FROM face_data WHERE student_id = $user_id");
        
        // Delete related attendance records
        $conn->query("DELETE FROM attendance WHERE student_id = $user_id");
        
        // Delete the user
        $conn->query("DELETE FROM users WHERE id = $user_id");
        
        echo "<h2>✅ User Deleted Successfully</h2>";
        echo "<p>User '{$user_info['username']}' (Role: {$user_info['role']}) has been deleted from the system.</p>";
        echo "<p>All related face data and attendance records have also been removed.</p>";
    } else {
        echo "<h2>❌ User Not Found</h2>";
        echo "<p>No user found with ID: $user_id</p>";
    }
} else {
    echo "<h2>❌ Invalid User ID</h2>";
    echo "<p>Please provide a valid user ID.</p>";
}

echo "<p><a href='check_existing_data.php'>← Back to User Management</a></p>";

$conn->close();
?>
