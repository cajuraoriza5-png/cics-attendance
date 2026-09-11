<?php
/**
 * Mobile Attendance Scanning System
 * Allows officers to scan attendance using their phones
 */
date_default_timezone_set('Asia/Manila');
session_start();
$conn = new mysqli("localhost","root","","attendance");

if($conn->connect_error){
    die("Connection failed: " . $conn->connect_error);
}

// Get event information from URL parameter
$event_id = $_GET['event_id'] ?? '';
$officer_id = $_GET['officer_id'] ?? session_id(); // Use session ID as unique officer identifier
$event = null;

if($event_id) {
    // Find the event by mobile_scan_link
    $stmt = $conn->prepare("SELECT * FROM events WHERE mobile_scan_link = ? LIMIT 1");
    $scan_link = 'mobile_scan.php?event_id=' . $event_id;
    $stmt->bind_param("s", $scan_link);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if($result->num_rows > 0) {
        $event = $result->fetch_assoc();
    }
}

// Log officer access for multi-device tracking
if($event && $officer_id) {
    $log_stmt = $conn->prepare("
        INSERT INTO officer_access_log (event_id, officer_id, access_time, device_info) 
        VALUES (?, ?, NOW(), ?)
        ON DUPLICATE KEY UPDATE access_time = NOW()
    ");
    $device_info = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    $log_stmt->bind_param("iss", $event['id'], $officer_id, $device_info);
    $log_stmt->execute();
}

// Handle attendance scanning
if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['scan_attendance'])) {
    
    $student_id = trim($_POST['student_id']);
    $scan_type = $_POST['scan_type']; // 'in' or 'out'
    $time_period = $_POST['time_period']; // 'morning' or 'afternoon'
    
    // Validate student
    $student_query = $conn->prepare("SELECT * FROM users WHERE student_id = ? AND role = 'student'");
    $student_query->bind_param("s", $student_id);
    $student_query->execute();
    $student_result = $student_query->get_result();
    
    if($student_result->num_rows == 0) {
        $error = "Student not found!";
    } else {
        $student = $student_result->fetch_assoc();
        
        // Check if already scanned for this time period
        $check_query = $conn->prepare("
            SELECT * FROM attendance 
            WHERE student_id = ? AND event_id = ? AND date = CURDATE()
        ");
        $check_query->bind_param("ii", $student['id'], $event['id']);
        $check_query->execute();
        $existing_attendance = $check_query->get_result()->fetch_assoc();
        
        $now = date('H:i:s');
        // Use late_time if set; fall back to login_end as the late cutoff
        $login_end_col = $time_period . '_login_end';
        $late_time_col = $time_period . '_late_time';
        $late_time = !empty($event[$late_time_col]) ? $event[$late_time_col] : ($event[$login_end_col] ?? null);
        $late_penalty = floatval($event['late_penalty'] ?? 0);
        $field_name = $time_period . '_' . $scan_type;
        $status_field = $time_period . '_status';

        if($existing_attendance) {
            $old_penalty = floatval($existing_attendance['penalty'] ?? 0);

            if($scan_type == 'in'){
                $status = ($late_time && $now > $late_time) ? 'Late' : 'Present';
                $session_penalty = ($status == 'Late') ? $late_penalty : 0.0;
                $total_penalty = $old_penalty + $session_penalty;

                $update_query = $conn->prepare("
                    UPDATE attendance
                    SET $field_name = ?, $status_field = ?, penalty = ?
                    WHERE id = ?
                ");
                $update_query->bind_param("ssdi", $now, $status, $total_penalty, $existing_attendance['id']);
                $update_query->execute();

                if($session_penalty > 0){
                    $conn->query("UPDATE users SET total_penalty = total_penalty + {$session_penalty} WHERE id = {$student['id']}");
                }
            } else {
                // Logout with prior login: only record out-time, preserve existing status
                $update_query = $conn->prepare("
                    UPDATE attendance SET $field_name = ? WHERE id = ?
                ");
                $update_query->bind_param("si", $now, $existing_attendance['id']);
                $update_query->execute();
            }

            $success = "Attendance updated successfully for " . htmlspecialchars($student['first_name'] . ' ' . $student['last_name']);
        } else {
            // No existing record
            if($scan_type == 'out'){
                // No prior login + scanning out = Late
                $status = 'Late';
                $session_penalty = $late_penalty;
            } else {
                $status = ($late_time && $now > $late_time) ? 'Late' : 'Present';
                $session_penalty = ($status == 'Late') ? $late_penalty : 0.0;
            }

            $insert_query = $conn->prepare("
                INSERT INTO attendance (
                    student_id, event_id, date,
                    morning_in, morning_status,
                    afternoon_in, afternoon_status,
                    penalty
                ) VALUES (?, ?, CURDATE(), ?, ?, ?, ?, ?)
            ");

            $morning_in = ($scan_type == 'in' && $time_period == 'morning') ? $now : null;
            $morning_status = ($time_period == 'morning') ? $status : null;
            $afternoon_in = ($scan_type == 'in' && $time_period == 'afternoon') ? $now : null;
            $afternoon_status = ($time_period == 'afternoon') ? $status : null;

            $insert_query->bind_param("iissssd", $student['id'], $event['id'], $morning_in, $morning_status, $afternoon_in, $afternoon_status, $session_penalty);
            $insert_query->execute();

            if($session_penalty > 0){
                $conn->query("UPDATE users SET total_penalty = total_penalty + {$session_penalty} WHERE id = {$student['id']}");
            }

            $success = "Attendance recorded successfully for " . htmlspecialchars($student['first_name'] . ' ' . $student['last_name']);
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Mobile Attendance Scanner</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background: linear-gradient(135deg, rgba(0,0,0,0.95), rgba(0,0,0,0.85)), 
                url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 800"><defs><linearGradient id="gold" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" style="stop-color:%23FFD700;stop-opacity:0.1"/><stop offset="100%" style="stop-color:%23FFA500;stop-opacity:0.1"/></linearGradient></defs><rect width="1200" height="800" fill="url(%23gold)"/></svg>');
            background-attachment: fixed;
            min-height: 100vh;
            color: white;
            padding: 10px;
            margin: 0;
            font-size: 16px;
        }

        .container {
            max-width: 100%;
            margin: 0 auto;
            padding: 10px;
        }

        .event-header {
            background: rgba(0,0,0,0.8);
            backdrop-filter: blur(20px);
            border: 2px solid rgba(255,215,0,0.3);
            border-radius: 25px;
            padding: 25px;
            margin-bottom: 20px;
            text-align: center;
            box-shadow: 0 15px 35px rgba(0,0,0,0.5);
        }

        .event-header h1 {
            font-size: 28px;
            background: linear-gradient(45deg, #FFD700, #FFA500);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            font-weight: 900;
            margin-bottom: 10px;
        }

        .event-header .event-type {
            background: rgba(255,215,0,0.2);
            color: #FFD700;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
            display: inline-block;
            margin: 10px 0;
        }

        .event-header .date-range {
            color: rgba(255,255,255,0.8);
            font-size: 16px;
            margin: 10px 0;
        }

        .scanner-card {
            background: rgba(0,0,0,0.8);
            backdrop-filter: blur(20px);
            border: 2px solid rgba(255,215,0,0.3);
            border-radius: 25px;
            padding: 30px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.5);
        }

        .scanner-title {
            font-size: 24px;
            background: linear-gradient(45deg, #FFD700, #FFA500);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            font-weight: 800;
            text-align: center;
            margin-bottom: 25px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: rgba(255,215,0,0.9);
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 15px;
            border: 2px solid rgba(255,215,0,0.2);
            border-radius: 15px;
            font-size: 16px;
            background: rgba(255,255,255,0.05);
            color: white;
            outline: none;
            transition: all 0.3s ease;
        }

        .form-group input:focus,
        .form-group select:focus {
            border-color: rgba(255,215,0,0.6);
            box-shadow: 0 0 20px rgba(255,215,0,0.3);
        }

        .scan-options {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 20px;
        }

        .btn {
            display: block;
            width: 100%;
            padding: 15px;
            border: none;
            border-radius: 15px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .btn-primary {
            background: linear-gradient(45deg, #FFD700, #FFA500);
            color: black;
            box-shadow: 0 8px 25px rgba(255,215,0,0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 35px rgba(255,215,0,0.4);
        }

        .btn-secondary {
            background: rgba(255,255,255,0.1);
            color: white;
            border: 2px solid rgba(255,215,0,0.3);
        }

        .btn-secondary:hover {
            background: rgba(255,215,0,0.2);
        }

        .error {
            background: rgba(220,53,69,0.2);
            border: 1px solid rgba(220,53,69,0.5);
            color: #ff6b6b;
            padding: 15px;
            border-radius: 15px;
            margin-bottom: 20px;
            font-weight: 600;
            text-align: center;
        }

        .success {
            background: rgba(40,167,69,0.2);
            border: 1px solid rgba(40,167,69,0.5);
            color: #51cf66;
            padding: 15px;
            border-radius: 15px;
            margin-bottom: 20px;
            font-weight: 600;
            text-align: center;
        }

        .quick-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-top: 20px;
        }

        .stat-item {
            background: rgba(255,255,255,0.05);
            padding: 15px;
            border-radius: 15px;
            text-align: center;
            border: 1px solid rgba(255,215,0,0.2);
        }

        .stat-value {
            font-size: 24px;
            font-weight: bold;
            color: #FFD700;
        }

        .stat-label {
            font-size: 12px;
            color: rgba(255,255,255,0.7);
            margin-top: 5px;
        }

        .current-time {
            text-align: center;
            font-size: 18px;
            color: #FFD700;
            margin: 20px 0;
            font-weight: bold;
        }

        @media (max-width: 480px) {
            .scan-options {
                grid-template-columns: 1fr;
            }
            
            .quick-stats {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if($event): ?>
            <div class="event-header">
                <h1>📱 Mobile Scanner</h1>
                <h2><?php echo htmlspecialchars($event['event_name']); ?></h2>
                <div class="event-type-badge"><?php echo htmlspecialchars($event['event_type']); ?></div>
                <?php if($event['venue']): ?>
                <div class="venue-badge" style="
                    background: rgba(0,123,255,0.2); 
                    color: #007bff; 
                    padding: 4px 12px; 
                    border-radius: 12px; 
                    font-size: 12px; 
                    font-weight: 600;
                    display: inline-block;
                    margin: 5px 0;
                ">
                    📍 <?php echo htmlspecialchars($event['venue']); ?>
                </div>
                <?php endif; ?>
                <div class="date-range">
                    <?php 
                    $start = new DateTime($event['start_date']);
                    $end = new DateTime($event['end_date']);
                    echo $start->format('M d, Y') . ' - ' . $end->format('M d, Y');
                    ?>
                </div>
                
                <!-- QR Code Display within Event -->
                <div style="margin: 20px 0; padding: 15px; background: rgba(255,255,255,0.1); border-radius: 15px; border: 2px solid rgba(255,215,0,0.3);">
                    <h4 style="color: #FFD700; margin-bottom: 10px; text-align: center;">📱 Event QR Code</h4>
                    <div style="text-align: center;">
                        <?php
                        $qr_file_path = 'event_qr_codes/' . ($event['qr_code'] ?? '');
                        if($event['qr_code'] && file_exists($qr_file_path)) {
                            echo "<img src='$qr_file_path' alt='Event QR Code' style='width: 150px; height: 150px; border: 2px solid rgba(255,215,0,0.5); border-radius: 10px; background: white; padding: 5px;'>";
                        } else {
                            echo "<div style='width: 150px; height: 150px; margin: 0 auto; border: 2px dashed rgba(255,215,0,0.5); border-radius: 10px; display: flex; align-items: center; justify-content: center; color: #FFD700; font-size: 12px; text-align: center; background: rgba(255,215,0,0.1);'>
                                📱<br>QR Code<br>Not Generated
                            </div>";
                        }
                        ?>
                    </div>
                    <div style="text-align: center; margin-top: 10px;">
                        <small style="color: rgba(255,255,255,0.7);">Scan this code for other officers to join</small>
                    </div>
                </div>
            </div>

            <div class="scanner-card">
                <div class="scanner-title">📷 Quick Attendance Scan</div>
                
                <?php if(isset($error)): ?>
                    <div class="error"><?php echo $error; ?></div>
                <?php endif; ?>

                <?php if(isset($success)): ?>
                    <div class="success"><?php echo $success; ?></div>
                <?php endif; ?>

                <div class="current-time" id="currentTime">
                    Current Time: <?php echo date('h:i A'); ?>
                </div>

                <form method="POST">
                    <div class="form-group">
                        <label for="student_id">Student ID</label>
                        <input type="text" name="student_id" id="student_id" 
                               placeholder="Enter Student ID (e.g., 2023-0970E)" 
                               required autofocus>
                    </div>

                    <div class="scan-options">
                        <div class="form-group">
                            <label for="time_period">Time Period</label>
                            <select name="time_period" id="time_period" required>
                                <option value="">Select Period</option>
                                <option value="morning">Morning</option>
                                <option value="afternoon">Afternoon</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="scan_type">Scan Type</label>
                            <select name="scan_type" id="scan_type" required>
                                <option value="">Select Type</option>
                                <option value="in">Check In</option>
                                <option value="out">Check Out</option>
                            </select>
                        </div>
                    </div>

                    <button type="submit" name="scan_attendance" class="btn btn-primary">
                        📷 Scan Attendance
                    </button>
                </form>

                <?php
                // Get today's attendance stats
                $stats_query = $conn->prepare("
                    SELECT 
                        COUNT(*) as total_scanned,
                        SUM(CASE WHEN morning_in IS NOT NULL THEN 1 ELSE 0 END) as morning_scanned,
                        SUM(CASE WHEN afternoon_in IS NOT NULL THEN 1 ELSE 0 END) as afternoon_scanned
                    FROM attendance 
                    WHERE event_id = ? AND date = CURDATE()
                ");
                $stats_query->bind_param("i", $event['id']);
                $stats_query->execute();
                $stats = $stats_query->get_result()->fetch_assoc();
                
                // Get active officers count
                $officers_query = $conn->prepare("
                    SELECT COUNT(DISTINCT officer_id) as active_officers
                    FROM officer_access_log 
                    WHERE event_id = ? AND access_time >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
                ");
                $officers_query->bind_param("i", $event['id']);
                $officers_query->execute();
                $officers_stats = $officers_query->get_result()->fetch_assoc();
                ?>

                <div class="quick-stats">
                    <div class="stat-item">
                        <div class="stat-value"><?php echo $stats['total_scanned']; ?></div>
                        <div class="stat-label">Total Scanned</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?php echo $stats['morning_scanned']; ?></div>
                        <div class="stat-label">Morning</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?php echo $stats['afternoon_scanned']; ?></div>
                        <div class="stat-label">Afternoon</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value">
                            <?php 
                            $total_students = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'student'")->fetch_assoc()['count'];
                            echo $total_students - $stats['total_scanned'];
                            ?>
                        </div>
                        <div class="stat-label">Remaining</div>
                    </div>
                    <div class="stat-item" style="background: rgba(0,123,255,0.1);">
                        <div class="stat-value" style="color: #007bff;"><?php echo $officers_stats['active_officers']; ?></div>
                        <div class="stat-label">Active Officers</div>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="event-header">
                <h1>❌ Invalid Event</h1>
                <p>The event link you used is not valid or has expired.</p>
                <?php
                $errBack = isset($_SESSION['admin_id']) ? 'admin_dashboard.php' : 'student_dashboard.php';
                ?>
                <a href="<?= $errBack ?>" class="btn btn-secondary" style="margin-top: 20px;">
                    Go to Dashboard
                </a>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Update current time every second
        function updateTime() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('en-US', { 
                hour: '2-digit', 
                minute: '2-digit',
                hour12: true 
            });
            document.getElementById('currentTime').textContent = 'Current Time: ' + timeString;
        }

        setInterval(updateTime, 1000);
        updateTime();

        // Auto-focus on student ID input
        document.getElementById('student_id').focus();

        // Handle form submission
        document.querySelector('form').addEventListener('submit', function(e) {
            const studentId = document.getElementById('student_id').value.trim();
            const timePeriod = document.getElementById('time_period').value;
            const scanType = document.getElementById('scan_type').value;

            if(!studentId || !timePeriod || !scanType) {
                e.preventDefault();
                alert('Please fill in all fields');
                return;
            }

            // Validate student ID format
            if(!/^\d{4}-\d{4}[A-Z]$/.test(studentId)) {
                e.preventDefault();
                alert('Invalid Student ID format. Example: 2023-0970E');
                return;
            }
        });

        // Clear form after successful submission
        <?php if(isset($success)): ?>
        setTimeout(function() {
            document.querySelector('form').reset();
            document.getElementById('student_id').focus();
        }, 2000);
        <?php endif; ?>
    </script>
</body>
</html>
