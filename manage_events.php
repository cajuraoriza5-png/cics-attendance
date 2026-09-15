<?php
/**
 * manage_events.php
 * Manage events (create, edit, delete)
 */
date_default_timezone_set('Asia/Manila');
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

// Handle event update
if(isset($_POST['update_event']) && isset($_POST['event_id'])) {
    $event_id = $_POST['event_id'];
    
    // Get old values for history tracking
    $old_stmt = $conn->prepare("SELECT * FROM events WHERE id = ?");
    $old_stmt->bind_param("i", $event_id);
    $old_stmt->execute();
    $old_event = $old_stmt->get_result()->fetch_assoc();
    
    // Update event details
    $venue = $_POST['venue'] ?? '';
    $event_type = $_POST['event_type'] ?? 'Regular';
    $event_description = $_POST['event_description'] ?? '';
    $event_history = $_POST['event_history'] ?? '';
    $late_penalty = $_POST['late_penalty'] ?? 25;
    $absent_penalty = $_POST['absent_penalty'] ?? 50;
    
    // Time fields
    $morning_login_start = $_POST['morning_login_start'] ?? '07:00:00';
    $morning_login_end = $_POST['morning_login_end'] ?? '07:30:00';
    $morning_late_time   = ($_POST['morning_late_time']   ?? '') !== '' ? $_POST['morning_late_time']   : null;
    $morning_logout_start = $_POST['morning_logout_start'] ?? '11:30:00';
    $morning_logout_end = $_POST['morning_logout_end'] ?? '12:00:00';
    $afternoon_login_start = $_POST['afternoon_login_start'] ?? '13:00:00';
    $afternoon_login_end = $_POST['afternoon_login_end'] ?? '13:30:00';
    $afternoon_late_time  = ($_POST['afternoon_late_time']  ?? '') !== '' ? $_POST['afternoon_late_time']  : null;
    $afternoon_logout_start = $_POST['afternoon_logout_start'] ?? '16:30:00';
    $afternoon_logout_end = $_POST['afternoon_logout_end'] ?? '17:00:00';
    
    // Update event
    $update_stmt = $conn->prepare("
        UPDATE events SET 
            venue = ?, 
            event_type = ?, 
            event_description = ?, 
            event_history = ?, 
            late_penalty = ?, 
            absent_penalty = ?,
            morning_login_start = ?, 
            morning_login_end = ?,
            morning_late_time = ?,
            morning_logout_start = ?, 
            morning_logout_end = ?,
            afternoon_login_start = ?, 
            afternoon_login_end = ?,
            afternoon_late_time = ?,
            afternoon_logout_start = ?, 
            afternoon_logout_end = ?
        WHERE id = ?
    ");
    
    $update_stmt->bind_param(
        "ssssddssssssssssi",
        $venue,
        $event_type,
        $event_description,
        $event_history,
        $late_penalty,
        $absent_penalty,
        $morning_login_start,
        $morning_login_end,
        $morning_late_time,
        $morning_logout_start,
        $morning_logout_end,
        $afternoon_login_start,
        $afternoon_login_end,
        $afternoon_late_time,
        $afternoon_logout_start,
        $afternoon_logout_end,
        $event_id
    );
    
    if($update_stmt->execute()) {
        // Log the change in history
        $change_description = "Event updated: venue, schedule, and/or penalties modified";
        
        $history_stmt = $conn->prepare("
            INSERT INTO event_history_log (event_id, admin_id, action_type, change_description)
            VALUES (?, ?, 'UPDATE', ?)
        ");
        
        $admin_id = $_SESSION['admin_id'] ?? $_SESSION['id'];
        $history_stmt->bind_param("iis", $event_id, $admin_id, $change_description);
        $history_stmt->execute();
        
        header("Location: manage_events.php?updated=1");
        exit();
    }
}

echo "<!DOCTYPE html>
<html lang='en'>
<head>
<meta charset='UTF-8'>
<meta name='viewport' content='width=device-width, initial-scale=1.0'>
<title>Manage Events - Enhanced System</title>
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
    max-width: 1400px;
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
    grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
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
    margin-bottom: 20px;
}

.event-title {
    font-size: 20px;
    font-weight: bold;
    color: #FFD700;
    margin-bottom: 10px;
}

.event-meta {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 10px;
}

.event-type {
    background: rgba(255,215,0,0.2);
    color: #FFD700;
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
}

.event-venue {
    background: rgba(0,123,255,0.2);
    color: #007bff;
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
}

.event-date {
    color: rgba(255,255,255,0.8);
    font-size: 14px;
    margin-bottom: 10px;
}

.event-description {
    color: rgba(255,255,255,0.7);
    font-size: 14px;
    margin-bottom: 15px;
    line-height: 1.4;
}

.qr-section {
    background: rgba(255,255,255,0.05);
    border: 1px solid rgba(255,215,0,0.2);
    border-radius: 15px;
    padding: 15px;
    margin: 15px 0;
    text-align: center;
}

.qr-title {
    color: #FFD700;
    font-weight: 600;
    margin-bottom: 10px;
    font-size: 16px;
}

.qr-code {
    width: 120px;
    height: 120px;
    border: 2px solid rgba(255,215,0,0.3);
    border-radius: 10px;
    margin: 10px auto;
    background: white;
    padding: 5px;
}

.qr-placeholder {
    width: 120px;
    height: 120px;
    border: 2px dashed rgba(255,215,0,0.3);
    border-radius: 10px;
    margin: 10px auto;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #FFD700;
    font-size: 12px;
    text-align: center;
    background: rgba(255,215,0,0.05);
}

.event-banner {
    width: 100%;
    height: 150px;
    object-fit: cover;
    border-radius: 10px;
    margin-bottom: 15px;
    border: 2px solid rgba(255,215,0,0.3);
}

.action-buttons {
    display: flex;
    gap: 10px;
    justify-content: center;
    margin-top: 20px;
    flex-wrap: wrap;
}

.btn {
    padding: 8px 16px;
    border: none;
    border-radius: 15px;
    font-size: 12px;
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

.btn-success {
    background: linear-gradient(45deg, #28a745, #20c997);
    color: white;
}

.btn-info {
    background: linear-gradient(45deg, #007bff, #0056b3);
    color: white;
}

.btn-secondary {
    background: rgba(255,255,255,0.1);
    color: white;
    border: 1px solid rgba(255,215,0,0.3);
}

.stats-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
    gap: 10px;
    margin: 15px 0;
}

.stat-item {
    background: rgba(255,255,255,0.05);
    padding: 10px;
    border-radius: 10px;
    text-align: center;
}

.stat-value {
    font-size: 18px;
    font-weight: bold;
    color: #FFD700;
}

.stat-label {
    font-size: 11px;
    color: rgba(255,255,255,0.7);
    margin-top: 2px;
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
        <h1>📅 Manage Events</h1>
    </div>

<?php if(isset($_GET['updated']) && $_GET['updated'] == 1) { ?>
    <div style='background: rgba(40,167,69,0.2); border: 1px solid rgba(40,167,69,0.5); color: #51cf66; padding: 15px; border-radius: 15px; margin-bottom: 20px; text-align: center; font-weight: 600;'>
        ✅ Event updated successfully! Changes have been logged in event history.
    </div>
<?php } ?>

// Get events with all details
$events_query = $conn->query("
    SELECT * FROM events 
    ORDER BY event_date DESC, created_at DESC 
    LIMIT 20
");

if($events_query->num_rows > 0) {
    echo "<div class='events-grid'>";
    
    while($event = $events_query->fetch_assoc()) {
        // Get attendance stats for this event
        $stats_query = $conn->prepare("
            SELECT 
                COUNT(*) as total_scanned,
                SUM(CASE WHEN morning_in IS NOT NULL THEN 1 ELSE 0 END) as morning_scanned,
                SUM(CASE WHEN afternoon_in IS NOT NULL THEN 1 ELSE 0 END) as afternoon_scanned
            FROM attendance 
            WHERE event_id = ? AND date = ?
        ");
        $event_date = $event['event_date'] ?? date('Y-m-d');
        $stats_query->bind_param("is", $event['id'], $event_date);
        $stats_query->execute();
        $stats = $stats_query->get_result()->fetch_assoc();
        
        echo "<div class='event-card'>";
        
        // Event banner
        if($event['event_banner'] && file_exists('event_banners/' . $event['event_banner'])) {
            echo "<img src='event_banners/" . htmlspecialchars($event['event_banner']) . "' alt='Event Banner' class='event-banner'>";
        }
        
        echo "<div class='event-header'>";
        echo "<div class='event-title'>" . htmlspecialchars($event['event_name']) . "</div>";
        
        echo "<div class='event-meta'>";
        echo "<div class='event-type'>" . htmlspecialchars($event['event_type'] ?? 'Regular') . "</div>";
        if($event['venue']) {
            echo "<div class='event-venue'>📍 " . htmlspecialchars($event['venue']) . "</div>";
        }
        echo "</div>";
        
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
        
        if($event['event_description']) {
            echo "<div class='event-description'>" . htmlspecialchars(substr($event['event_description'], 0, 100)) . (strlen($event['event_description']) > 100 ? '...' : '') . "</div>";
        }
        echo "</div>";
        
        // QR Code Section within Event
        echo "<div class='qr-section'>";
        echo "<div class='qr-title'>📱 Event QR Code</div>";
        
        $qr_file_path = 'event_qr_codes/' . ($event['qr_code'] ?? '');
        if($event['qr_code'] && file_exists($qr_file_path)) {
            echo "<img src='$qr_file_path' alt='Event QR Code' class='qr-code'>";
        } else {
            echo "<div class='qr-placeholder'>
                📱<br>QR Code<br>Not Generated
            </div>";
        }
        
        echo "<div style='margin-top: 10px;'>";
        if($event['mobile_scan_link']) {
            $mobile_url = "http://localhost/attendance/mobile_scan.php?event_id=" . urlencode($event['mobile_scan_link']);
            echo "<a href='$mobile_url' target='_blank' class='btn btn-info' style='margin: 5px;'>📱 Scanner</a>";
        }
        
        if($event['id']) {
            $qr_gen_url = "generate_qr_code.php?event_id=" . $event['id'];
            echo "<a href='$qr_gen_url' target='_blank' class='btn btn-success' style='margin: 5px;'>🔄 Generate QR</a>";
        }
        echo "</div>";
        echo "</div>";
        
        // Attendance Stats
        echo "<div class='stats-row'>";
        echo "<div class='stat-item'>";
        echo "<div class='stat-value'>" . ($stats['total_scanned'] ?? 0) . "</div>";
        echo "<div class='stat-label'>Total</div>";
        echo "</div>";
        echo "<div class='stat-item'>";
        echo "<div class='stat-value'>" . ($stats['morning_scanned'] ?? 0) . "</div>";
        echo "<div class='stat-label'>Morning</div>";
        echo "</div>";
        echo "<div class='stat-item'>";
        echo "<div class='stat-value'>" . ($stats['afternoon_scanned'] ?? 0) . "</div>";
        echo "<div class='stat-label'>Afternoon</div>";
        echo "</div>";
        echo "</div>";
        
        // Inline Edit Form
        echo "<div style='margin-top: 20px; padding: 15px; background: rgba(255,255,255,0.05); border-radius: 15px; border: 1px solid rgba(255,215,0,0.2);'>";
        echo "<h4 style='color: #FFD700; margin-bottom: 15px;'>✏️ Quick Edit</h4>";
        echo "<form method='POST' style='display: grid; gap: 10px;'>";
        echo "<input type='hidden' name='event_id' value='" . $event['id'] . "'>";
        
        echo "<div style='display: grid; grid-template-columns: 1fr 1fr; gap: 10px;'>";
        echo "<input type='text' name='venue' value='" . htmlspecialchars($event['venue'] ?? '') . "' placeholder='Venue' style='padding: 8px; border: 1px solid rgba(255,215,0,0.3); border-radius: 8px; background: rgba(255,255,255,0.1); color: white;'>";
        
        echo "<select name='event_type' style='padding: 8px; border: 1px solid rgba(255,215,0,0.3); border-radius: 8px; background: rgba(255,255,255,0.1); color: white;'>";
        echo "<option value='Regular' " . (($event['event_type'] ?? 'Regular') == 'Regular' ? 'selected' : '') . ">Regular</option>";
        echo "<option value='Special' " . (($event['event_type'] ?? '') == 'Special' ? 'selected' : '') . ">Special</option>";
        echo "<option value='Exam' " . (($event['event_type'] ?? '') == 'Exam' ? 'selected' : '') . ">Exam</option>";
        echo "<option value='Meeting' " . (($event['event_type'] ?? '') == 'Meeting' ? 'selected' : '') . ">Meeting</option>";
        echo "<option value='Activity' " . (($event['event_type'] ?? '') == 'Activity' ? 'selected' : '') . ">Activity</option>";
        echo "<option value='Morning Only' " . (($event['event_type'] ?? '') == 'Morning Only' ? 'selected' : '') . ">Morning Only</option>";
        echo "<option value='Afternoon Only' " . (($event['event_type'] ?? '') == 'Afternoon Only' ? 'selected' : '') . ">Afternoon Only</option>";
        echo "</select>";
        echo "</div>";
        
        echo "<textarea name='event_description' placeholder='Event Description' style='padding: 8px; border: 1px solid rgba(255,215,0,0.3); border-radius: 8px; background: rgba(255,255,255,0.1); color: white; resize: vertical; min-height: 60px;'>" . htmlspecialchars($event['event_description'] ?? '') . "</textarea>";
        
        echo "<div style='display: grid; grid-template-columns: 1fr 1fr; gap: 10px;'>";
        echo "<input type='number' name='late_penalty' value='" . htmlspecialchars($event['late_penalty'] ?? 25) . "' placeholder='Late Penalty' style='padding: 8px; border: 1px solid rgba(255,215,0,0.3); border-radius: 8px; background: rgba(255,255,255,0.1); color: white;'>";
        echo "<input type='number' name='absent_penalty' value='" . htmlspecialchars($event['absent_penalty'] ?? 50) . "' placeholder='Absent Penalty' style='padding: 8px; border: 1px solid rgba(255,215,0,0.3); border-radius: 8px; background: rgba(255,255,255,0.1); color: white;'>";
        echo "</div>";
        
        echo "<div style='display: flex; gap: 10px; justify-content: center;'>";
        echo "<button type='submit' name='update_event' class='btn btn-primary' style='background: linear-gradient(45deg, #FFD700, #FFA500); color: black; padding: 8px 16px; font-size: 12px;'>💾 Save Changes</button>";
        echo "</div>";
        
        echo "</form>";
        echo "</div>";
        
        // Action buttons
        echo "<div class='action-buttons'>";
        echo "<a href='create_event_new.php' class='btn btn-success'>📅 New Event</a>";
        echo "<a href='admin_dashboard.php' class='btn btn-secondary'>🏠 Dashboard</a>";
        echo "</div>";
        
        echo "</div>";
    }
    
    echo "</div>";
} else {
    echo "<div style='text-align: center; padding: 60px 20px; background: rgba(0,0,0,0.8); border-radius: 20px; border: 2px solid rgba(255,215,0,0.3);'>";
    echo "<h2 style='color: #FFD700; margin-bottom: 20px;'>📅 No Events Found</h2>";
    echo "<p style='color: rgba(255,255,255,0.8); margin-bottom: 30px;'>Create your first event to get started with QR codes and mobile scanning.</p>";
    echo "<a href='create_event_new.php' class='btn btn-primary'>📅 Create Event</a>";
    echo "</div>";
}

echo "</div>
</body>
</html>";

$conn->close();
?>
