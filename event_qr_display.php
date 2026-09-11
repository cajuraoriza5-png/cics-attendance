<?php
/**
 * Event QR Code Display
 * Shows QR codes for events with mobile scanning links
 */

session_start();
$conn = new mysqli("localhost","root","","attendance");

if($conn->connect_error){
    die("Connection failed: " . $conn->connect_error);
}

// Check if admin is logged in
if(!isset($_SESSION['admin_id']) && !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: admin_login.php");
    exit();
}

echo "<!DOCTYPE html>
<html lang='en'>
<head>
<meta charset='UTF-8'>
<meta name='viewport' content='width=device-width, initial-scale=1.0'>
<title>Event QR Codes - Mobile Scanning</title>
<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}

body {
    background: linear-gradient(135deg, rgba(0,0,0,0.95), rgba(0,0,0,0.85)), 
                url('data:image/svg+xml,<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 1200 800\"><defs><linearGradient id=\"gold\" x1=\"0%\" y1=\"0%\" x2=\"100%\" y2=\"100%\"><stop offset=\"0%\" style=\"stop-color:%23FFD700;stop-opacity:0.1\"/><stop offset=\"100%\" style=\"stop-color:%23FFA500;stop-opacity:0.1\"/></linearGradient></defs><rect width=\"1200\" height=\"800\" fill=\"url(%23gold)\"/></svg>');
    background-attachment: fixed;
    min-height: 100vh;
    color: white;
    padding: 20px;
}

.container {
    max-width: 1200px;
    margin: 0 auto;
}

.header {
    background: rgba(0,0,0,0.8);
    backdrop-filter: blur(20px);
    border: 2px solid rgba(255,215,0,0.3);
    border-radius: 18px;
    padding: 20px;
    display: flex;
    align-items: center;
    gap: 20px;
    margin-bottom: 30px;
    box-shadow: 0 15px 35px rgba(0,0,0,0.5);
}

.header h1 {
    font-size: 28px;
    background: linear-gradient(45deg, #FFD700, #FFA500);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    font-weight: 900;
}

.back-btn {
    background: rgba(255,255,255,0.1);
    color: white;
    padding: 10px 20px;
    border-radius: 20px;
    text-decoration: none;
    border: 1px solid rgba(255,215,0,0.3);
    transition: all 0.3s ease;
}

.back-btn:hover {
    background: rgba(255,215,0,0.2);
}

.events-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
    gap: 25px;
}

.event-card {
    background: rgba(0,0,0,0.8);
    backdrop-filter: blur(20px);
    border: 2px solid rgba(255,215,0,0.3);
    border-radius: 20px;
    padding: 25px;
    box-shadow: 0 15px 35px rgba(0,0,0,0.5);
    transition: transform 0.3s ease;
}

.event-card:hover {
    transform: translateY(-5px);
}

.event-header {
    text-align: center;
    margin-bottom: 20px;
}

.event-title {
    font-size: 20px;
    font-weight: bold;
    color: #FFD700;
    margin-bottom: 10px;
}

.event-type {
    background: rgba(255,215,0,0.2);
    color: #FFD700;
    padding: 5px 12px;
    border-radius: 15px;
    font-size: 12px;
    font-weight: 600;
    display: inline-block;
    margin-bottom: 10px;
}

.event-venue {
    background: rgba(0,123,255,0.2);
    color: #007bff;
    padding: 5px 12px;
    border-radius: 15px;
    font-size: 12px;
    font-weight: 600;
    display: inline-block;
    margin: 5px;
}

.event-date {
    color: rgba(255,255,255,0.8);
    font-size: 14px;
}

.qr-section {
    text-align: center;
    margin: 20px 0;
}

.qr-code {
    width: 200px;
    height: 200px;
    border: 3px solid rgba(255,215,0,0.3);
    border-radius: 15px;
    margin: 15px auto;
    background: white;
    padding: 10px;
}

.qr-placeholder {
    width: 200px;
    height: 200px;
    border: 3px dashed rgba(255,215,0,0.5);
    border-radius: 15px;
    margin: 15px auto;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #FFD700;
    font-size: 14px;
    text-align: center;
    background: rgba(255,215,0,0.1);
}

.scan-info {
    background: rgba(0,255,0,0.1);
    border: 1px solid rgba(0,255,0,0.3);
    border-radius: 10px;
    padding: 15px;
    margin: 15px 0;
}

.scan-info h4 {
    color: #51cf66;
    margin-bottom: 10px;
}

.scan-info p {
    color: rgba(255,255,255,0.8);
    font-size: 14px;
    line-height: 1.5;
}

.action-buttons {
    display: flex;
    gap: 10px;
    justify-content: center;
    margin-top: 20px;
}

.btn {
    padding: 10px 20px;
    border: none;
    border-radius: 20px;
    font-size: 14px;
    font-weight: bold;
    cursor: pointer;
    transition: all 0.3s ease;
    text-decoration: none;
    display: inline-block;
}

.btn-primary {
    background: linear-gradient(45deg, #FFD700, #FFA500);
    color: black;
}

.btn-secondary {
    background: rgba(255,255,255,0.1);
    color: white;
    border: 1px solid rgba(255,215,0,0.3);
}

.btn-success {
    background: linear-gradient(45deg, #28a745, #20c997);
    color: white;
}

@media (max-width: 768px) {
    .events-grid {
        grid-template-columns: 1fr;
    }
    
    .header {
        flex-direction: column;
        text-align: center;
    }
    
    .action-buttons {
        flex-direction: column;
    }
}
</style>
</head>
<body>

<div class='container'>
    <div class='header'>
        <a href='admin_dashboard.php' class='back-btn'>← Dashboard</a>
        <h1>📱 Event QR Codes - Mobile Scanning</h1>
    </div>";

// Get events with mobile scan links
$events_query = $conn->query("
    SELECT * FROM events 
    WHERE mobile_scan_link IS NOT NULL 
    ORDER BY event_date DESC, created_at DESC
");

if($events_query->num_rows > 0) {
    echo "<div class='events-grid'>";
    
    while($event = $events_query->fetch_assoc()) {
        $mobile_scan_url = "http://localhost/attendance/mobile_scan.php?event_id=" . urlencode($event['mobile_scan_link']);
        $qr_code_url = "generate_qr_code.php?event_id=" . $event['id'];
        
        echo "<div class='event-card'>";
        echo "<div class='event-header'>";
        echo "<div class='event-title'>" . htmlspecialchars($event['event_name']) . "</div>";
        echo "<div class='event-type'>" . htmlspecialchars($event['event_type'] ?? 'Regular') . "</div>";
        if($event['venue']) {
            echo "<div class='event-venue'>📍 " . htmlspecialchars($event['venue']) . "</div>";
        }
        echo "<div class='event-date'>";
        if($event['start_date'] && $event['end_date']) {
            $start = new DateTime($event['start_date']);
            $end = new DateTime($event['end_date']);
            echo $start->format('M d, Y');
            if($start->format('Y-m-d') != $end->format('Y-m-d')) {
                echo ' - ' . $end->format('M d, Y');
            }
        }
        echo "</div>";
        echo "</div>";
        
        echo "<div class='qr-section'>";
        
        // Check if QR code file exists
        $qr_file_path = 'event_qr_codes/' . ($event['qr_code'] ?? '');
        if($event['qr_code'] && file_exists($qr_file_path)) {
            echo "<img src='$qr_file_path' alt='QR Code' class='qr-code'>";
        } else {
            echo "<div class='qr-placeholder'>
                📱<br>
                QR Code<br>
                Not Generated
            </div>";
        }
        echo "</div>";
        
        echo "<div class='scan-info'>";
        echo "<h4>📱 Mobile Scanning Information</h4>";
        echo "<p><strong>Scan Link:</strong> " . htmlspecialchars($mobile_scan_url) . "</p>";
        echo "<p><strong>Usage:</strong> Officers can scan this QR code with their phones to access the mobile attendance scanner.</p>";
        echo "<p><strong>Multi-Device:</strong> Multiple officers can scan simultaneously using different phones.</p>";
        echo "</div>";
        
        echo "<div class='action-buttons'>";
        echo "<a href='$mobile_scan_url' target='_blank' class='btn btn-primary'>📱 Test Scanner</a>";
        echo "<a href='$qr_code_url' target='_blank' class='btn btn-success'>🔄 Generate QR</a>";
        echo "<a href='mobile_scan.php?event_id=" . urlencode($event['mobile_scan_link']) . "' target='_blank' class='btn btn-secondary'>🔗 Direct Link</a>";
        echo "</div>";
        
        echo "</div>";
    }
    
    echo "</div>";
} else {
    echo "<div style='text-align: center; padding: 60px 20px; background: rgba(0,0,0,0.8); border-radius: 20px; border: 2px solid rgba(255,215,0,0.3);'>";
    echo "<h2 style='color: #FFD700; margin-bottom: 20px;'>📱 No Events with QR Codes</h2>";
    echo "<p style='color: rgba(255,255,255,0.8); margin-bottom: 30px;'>Create events first to generate QR codes for mobile scanning.</p>";
    echo "<a href='create_event_new.php' class='btn btn-primary'>📅 Create Event</a>";
    echo "</div>";
}

echo "</div>
</body>
</html>";

$conn->close();
?>
