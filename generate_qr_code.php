<?php
/**
 * QR Code Generator for Events
 * Generates QR codes that link to mobile scanning system
 */

$conn = new mysqli("localhost","root","","attendance");

if($conn->connect_error){
    die("Connection failed: " . $conn->connect_error);
}

// Get event ID from URL
$event_id = $_GET['event_id'] ?? '';

if(!$event_id) {
    die("Event ID required");
}

// Get event information
$stmt = $conn->prepare("SELECT * FROM events WHERE id = ?");
$stmt->bind_param("i", $event_id);
$stmt->execute();
$event = $stmt->get_result()->fetch_assoc();

if(!$event) {
    die("Event not found");
}

// Generate mobile scan URL
$mobile_scan_url = "http://localhost/attendance/mobile_scan.php?event_id=" . urlencode($event['mobile_scan_link']);

// Generate QR code using Google Charts API
$qr_data = urlencode($mobile_scan_url);
$qr_size = 300;
$qr_url = "https://chart.googleapis.com/chart?chs={$qr_size}x{$qr_size}&cht=qr&chl={$qr_data}&choe=UTF-8";

// Save QR code image
$qr_filename = $event['qr_code'] ?? 'qr_' . $event_id . '.png';
$qr_filepath = 'event_qr_codes/' . $qr_filename;

// Create directory if it doesn't exist
if(!is_dir('event_qr_codes')) {
    mkdir('event_qr_codes', 0755, true);
}

// Download and save QR code
$image_data = file_get_contents($qr_url);
if($image_data) {
    file_put_contents($qr_filepath, $image_data);
    
    // Update database with QR code filename
    $update_stmt = $conn->prepare("UPDATE events SET qr_code = ? WHERE id = ?");
    $update_stmt->bind_param("si", $qr_filename, $event_id);
    $update_stmt->execute();
}

// Display QR code
header('Content-Type: image/png');
readfile($qr_filepath);
?>
