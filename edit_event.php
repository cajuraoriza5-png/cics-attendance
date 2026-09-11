<?php
/**
 * Edit Event - Enhanced Event Management
 * Allows admins to edit events with history tracking
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

$event_id = $_GET['id'] ?? '';
$event = null;

if($event_id) {
    $stmt = $conn->prepare("SELECT * FROM events WHERE id = ?");
    $stmt->bind_param("i", $event_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $event = $result->fetch_assoc();
}

if(!$event) {
    header("Location: manage_events.php");
    exit();
}

// Handle event update
if(isset($_POST['update_event'])) {
    // Get old values for history tracking
    $old_values = json_encode($event);
    
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
        $new_values = json_encode([
            'venue' => $venue,
            'event_type' => $event_type,
            'event_description' => $event_description,
            'event_history' => $event_history,
            'late_penalty' => $late_penalty,
            'absent_penalty' => $absent_penalty,
            'morning_login_start' => $morning_login_start,
            'morning_login_end' => $morning_login_end,
            'morning_logout_start' => $morning_logout_start,
            'morning_logout_end' => $morning_logout_end,
            'afternoon_login_start' => $afternoon_login_start,
            'afternoon_login_end' => $afternoon_login_end,
            'afternoon_logout_start' => $afternoon_logout_start,
            'afternoon_logout_end' => $afternoon_logout_end
        ]);
        
        $change_description = "Event details updated: venue, schedule, and/or penalties modified";
        
        $history_stmt = $conn->prepare("
            INSERT INTO event_history_log (event_id, admin_id, action_type, old_values, new_values, change_description)
            VALUES (?, ?, 'UPDATE', ?, ?, ?)
        ");
        
        $admin_id = $_SESSION['admin_id'] ?? $_SESSION['id'];
        $history_stmt->bind_param("iisss", $event_id, $admin_id, $old_values, $new_values, $change_description);
        $history_stmt->execute();
        
        header("Location: edit_event.php?id=$event_id&success=1");
        exit();
    }
}

// Handle banner upload
if(isset($_POST['upload_banner'])) {
    if(isset($_FILES['event_banner']) && $_FILES['event_banner']['error'] == UPLOAD_ERR_OK){
        $allowed_types = ['image/jpeg', 'image/png', 'image/jpg'];
        $file_info = getimagesize($_FILES['event_banner']['tmp_name']);
        
        if($file_info !== false && in_array($file_info['mime'], $allowed_types)){
            if($_FILES['event_banner']['size'] <= 5 * 1024 * 1024){ // 5MB limit
                if(!is_dir('event_banners')){
                    mkdir('event_banners', 0755, true);
                }
                
                $filename = 'banner_' . $event_id . '_' . time() . '.jpg';
                $filepath = 'event_banners/' . $filename;
                
                if(move_uploaded_file($_FILES['event_banner']['tmp_name'], $filepath)){
                    // Update database
                    $banner_stmt = $conn->prepare("UPDATE events SET event_banner = ? WHERE id = ?");
                    $banner_stmt->bind_param("si", $filename, $event_id);
                    $banner_stmt->execute();
                    
                    // Log banner change
                    $change_description = "Event banner updated: $filename";
                    $history_stmt = $conn->prepare("
                        INSERT INTO event_history_log (event_id, admin_id, action_type, change_description)
                        VALUES (?, ?, 'BANNER_UPDATE', ?)
                    ");
                    
                    $admin_id = $_SESSION['admin_id'] ?? $_SESSION['id'];
                    $history_stmt->bind_param("iis", $event_id, $admin_id, $change_description);
                    $history_stmt->execute();
                    
                    header("Location: edit_event.php?id=$event_id&banner_success=1");
                    exit();
                }
            }
        }
    }
}

// Get event history
$history_query = $conn->prepare("
    SELECT * FROM event_history_log 
    WHERE event_id = ? 
    ORDER BY change_time DESC
");
$history_query->bind_param("i", $event_id);
$history_query->execute();
$history = $history_query->get_result();

?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Edit Event - <?php echo htmlspecialchars($event['event_name']); ?></title>

<style>
*{
    margin:0;
    padding:0;
    box-sizing:border-box;
    font-family:'Segoe UI',sans-serif;
}

body{
    background: linear-gradient(135deg, rgba(0,0,0,0.95), rgba(0,0,0,0.85)), 
                url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 800"><defs><linearGradient id="gold" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" style="stop-color:%23FFD700;stop-opacity:0.1"/><stop offset="100%" style="stop-color:%23FFA500;stop-opacity:0.1"/></linearGradient></defs><rect width="1200" height="800" fill="url(%23gold)"/></svg>');
    background-attachment: fixed;
    min-height: 100vh;
    color: white;
    padding: 20px;
}

.container{
    max-width: 1200px;
    margin: auto;
}

.top{
    background: rgba(0,0,0,0.8);
    backdrop-filter: blur(20px);
    border: 2px solid rgba(255,215,0,0.3);
    padding: 20px;
    border-radius: 18px;
    display: flex;
    align-items: center;
    gap: 20px;
    box-shadow: 0 15px 35px rgba(0,0,0,0.5);
    margin-bottom: 30px;
}

.logo{
    width: 60px;
    height: 60px;
    background: linear-gradient(45deg, #FFD700, #FFA500);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    font-weight: bold;
    color: black;
}

.title{
    flex: 1;
}

.title h1{
    font-size: 32px;
    background: linear-gradient(45deg, #FFD700, #FFA500);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    font-weight: 900;
}

.title p{
    color: rgba(255,255,255,0.7);
    margin-top: 5px;
}

.back-btn{
    background: rgba(255,255,255,0.1);
    color: white;
    padding: 12px 20px;
    border-radius: 25px;
    text-decoration: none;
    border: 1px solid rgba(255,215,0,0.3);
    transition: all 0.3s ease;
}

.back-btn:hover{
    background: rgba(255,215,0,0.2);
}

.content-grid{
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 30px;
}

.card{
    background: rgba(0,0,0,0.8);
    backdrop-filter: blur(20px);
    border: 2px solid rgba(255,215,0,0.3);
    border-radius: 25px;
    padding: 40px;
    box-shadow: 0 15px 35px rgba(0,0,0,0.5);
}

.card h2{
    font-size: 28px;
    background: linear-gradient(45deg, #FFD700, #FFA500);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    font-weight: 800;
    margin-bottom: 30px;
}

.form-row{
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 25px;
    margin-bottom: 25px;
}

.form-group{
    display: flex;
    flex-direction: column;
}

.form-group label{
    font-weight: 600;
    margin-bottom: 8px;
    color: rgba(255,215,0,0.9);
    font-size: 14px;
}

.form-group input,
.form-group select,
.form-group textarea{
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
.form-group select:focus,
.form-group textarea:focus{
    border-color: rgba(255,215,0,0.6);
    box-shadow: 0 0 20px rgba(255,215,0,0.3);
}

.form-group textarea{
    resize: vertical;
    min-height: 100px;
}

.btn-group{
    display: flex;
    gap: 15px;
    justify-content: center;
    margin-top: 40px;
}

.btn{
    padding: 15px 30px;
    border: none;
    border-radius: 25px;
    font-size: 16px;
    font-weight: bold;
    cursor: pointer;
    transition: all 0.3s ease;
    text-transform: uppercase;
    letter-spacing: 1px;
}

.btn-primary{
    background: linear-gradient(45deg, #FFD700, #FFA500);
    color: black;
    box-shadow: 0 8px 25px rgba(255,215,0,0.3);
}

.btn-primary:hover{
    transform: translateY(-2px);
    box-shadow: 0 12px 35px rgba(255,215,0,0.4);
}

.btn-secondary{
    background: rgba(255,255,255,0.1);
    color: white;
    border: 2px solid rgba(255,215,0,0.3);
}

.btn-secondary:hover{
    background: rgba(255,215,0,0.2);
}

.success-message{
    background: rgba(40,167,69,0.2);
    border: 1px solid rgba(40,167,69,0.5);
    color: #51cf66;
    padding: 15px;
    border-radius: 15px;
    margin-bottom: 20px;
    text-align: center;
    font-weight: 600;
}

.history-item{
    background: rgba(255,255,255,0.05);
    border: 1px solid rgba(255,215,0,0.2);
    border-radius: 10px;
    padding: 15px;
    margin-bottom: 15px;
}

.history-time{
    color: #FFD700;
    font-size: 12px;
    margin-bottom: 5px;
}

.history-action{
    color: rgba(255,255,255,0.9);
    font-weight: 600;
    margin-bottom: 5px;
}

.history-description{
    color: rgba(255,255,255,0.7);
    font-size: 14px;
}

.event-banner{
    width: 100%;
    max-height: 200px;
    object-fit: cover;
    border-radius: 15px;
    margin-bottom: 20px;
    border: 2px solid rgba(255,215,0,0.3);
}

/* RADIO */
.radio-group{
    display:flex;
    gap:20px;
    flex-wrap:wrap;
    margin-top:10px;
}
.radio-item{
    display:flex;
    align-items:center;
    gap:8px;
    cursor:pointer;
}
.radio-item input[type="radio"]{
    width:18px;
    height:18px;
    accent-color:#FFD700;
    cursor:pointer;
}
.radio-item label{
    color:white;
    font-weight:600;
    font-size:15px;
    cursor:pointer;
}

@media(max-width: 768px){
    .content-grid{
        grid-template-columns: 1fr;
    }
    
    .form-row{
        grid-template-columns: 1fr;
    }
    
    .btn-group{
        flex-direction: column;
    }
}
</style>
</head>

<body>

<div class="container">

<div class="top">
    <div class="logo">✏️</div>
    <div class="title">
        <h1>Edit Event</h1>
        <p><?php echo htmlspecialchars($event['event_name']); ?> - Update event details and schedule</p>
    </div>
    <a href="event_history.php" class="back-btn">← Events</a>
</div>

<?php if(isset($_GET['success']) && $_GET['success'] == 1): ?>
    <div class="success-message">
        ✅ Event updated successfully! Changes have been logged in event history.
    </div>
<?php endif; ?>

<?php if(isset($_GET['banner_success']) && $_GET['banner_success'] == 1): ?>
    <div class="success-message">
        ✅ Event banner updated successfully!
    </div>
<?php endif; ?>

<div class="content-grid">
    <div class="card">
        <h2>📝 Event Details</h2>
        
        <?php if($event['event_banner'] && file_exists('event_banners/' . $event['event_banner'])): ?>
            <img src="event_banners/<?php echo htmlspecialchars($event['event_banner']); ?>" alt="Event Banner" class="event-banner">
        <?php endif; ?>
        
        <form method="POST">
            
            <!-- Basic Information -->
            <div class="form-row">
                <div class="form-group">
                    <label for="event_name">Event Name</label>
                    <input type="text" id="event_name" value="<?php echo htmlspecialchars($event['event_name']); ?>" readonly style="background: rgba(255,255,255,0.02);">
                </div>
                
                <div class="form-group">
                    <label for="venue">Venue *</label>
                    <input type="text" name="venue" id="venue" value="<?php echo htmlspecialchars($event['venue'] ?? ''); ?>" required placeholder="Enter event venue">
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Event Type *</label>
                    <div class="radio-group">
                        <?php $et = $event['event_type'] ?? ''; $etWhole = ($et !== 'Morning Only' && $et !== 'Afternoon Only'); ?>
                        <div class="radio-item">
                            <input type="radio" name="event_type" id="et_whole" value="Regular" <?php echo $etWhole ? 'checked' : ''; ?> onchange="toggleTimeFields()">
                            <label for="et_whole">Whole Day</label>
                        </div>
                        <div class="radio-item">
                            <input type="radio" name="event_type" id="et_morning" value="Morning Only" <?php echo $et=='Morning Only' ? 'checked' : ''; ?> onchange="toggleTimeFields()">
                            <label for="et_morning">Morning Only</label>
                        </div>
                        <div class="radio-item">
                            <input type="radio" name="event_type" id="et_afternoon" value="Afternoon Only" <?php echo $et=='Afternoon Only' ? 'checked' : ''; ?> onchange="toggleTimeFields()">
                            <label for="et_afternoon">Afternoon Only</label>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="event_description">Event Description</label>
                    <textarea name="event_description" id="event_description" placeholder="Enter event description"><?php echo htmlspecialchars($event['event_description'] ?? ''); ?></textarea>
                </div>
            </div>
            
            <!-- Date Information (Read-only) -->
            <div class="form-row">
                <div class="form-group">
                    <label for="start_date">Start Date</label>
                    <input type="date" id="start_date" value="<?php echo htmlspecialchars($event['start_date'] ?? ''); ?>" readonly style="background: rgba(255,255,255,0.02);">
                </div>
                
                <div class="form-group">
                    <label for="end_date">End Date</label>
                    <input type="date" id="end_date" value="<?php echo htmlspecialchars($event['end_date'] ?? ''); ?>" readonly style="background: rgba(255,255,255,0.02);">
                </div>
            </div>
            
            <!-- Event History -->
            <div class="form-group">
                <label for="event_history">Event History/Notes</label>
                <textarea name="event_history" id="event_history" placeholder="Enter event history or special notes"><?php echo htmlspecialchars($event['event_history'] ?? ''); ?></textarea>
            </div>
            
            <!-- Penalty Settings -->
            <div class="form-row">
                <div class="form-group">
                    <label for="late_penalty">Late Penalty (₱)</label>
                    <input type="number" name="late_penalty" id="late_penalty" value="<?php echo htmlspecialchars($event['late_penalty'] ?? 25); ?>" min="0" step="0.01">
                </div>
                
                <div class="form-group">
                    <label for="absent_penalty">Absent Penalty (₱)</label>
                    <input type="number" name="absent_penalty" id="absent_penalty" value="<?php echo htmlspecialchars($event['absent_penalty'] ?? 50); ?>" min="0" step="0.01">
                </div>
            </div>
            
            <!-- MORNING -->
            <div id="morning_section">
                <div class="form-row">
                    <div class="form-group">
                        <label for="morning_login_start">Morning Login Start</label>
                        <input type="time" name="morning_login_start" id="morning_login_start" value="<?php echo htmlspecialchars($event['morning_login_start'] ?? '07:00'); ?>">
                    </div>
                    <div class="form-group">
                        <label for="morning_login_end">Morning Login End</label>
                        <input type="time" name="morning_login_end" id="morning_login_end" value="<?php echo htmlspecialchars($event['morning_login_end'] ?? '07:30'); ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="morning_late_time">Morning Late After <small style="font-weight:normal;opacity:.6;">(optional)</small></label>
                        <input type="time" name="morning_late_time" id="morning_late_time" value="<?php echo htmlspecialchars($event['morning_late_time'] ?? ''); ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="morning_logout_start">Morning Logout Start</label>
                        <input type="time" name="morning_logout_start" id="morning_logout_start" value="<?php echo htmlspecialchars($event['morning_logout_start'] ?? '11:30'); ?>">
                    </div>
                    <div class="form-group">
                        <label for="morning_logout_end">Morning Logout End</label>
                        <input type="time" name="morning_logout_end" id="morning_logout_end" value="<?php echo htmlspecialchars($event['morning_logout_end'] ?? '12:00'); ?>">
                    </div>
                </div>
            </div>

            <!-- AFTERNOON -->
            <div id="afternoon_section">
                <div class="form-row">
                    <div class="form-group">
                        <label for="afternoon_login_start">Afternoon Login Start</label>
                        <input type="time" name="afternoon_login_start" id="afternoon_login_start" value="<?php echo htmlspecialchars($event['afternoon_login_start'] ?? '13:00'); ?>">
                    </div>
                    <div class="form-group">
                        <label for="afternoon_login_end">Afternoon Login End</label>
                        <input type="time" name="afternoon_login_end" id="afternoon_login_end" value="<?php echo htmlspecialchars($event['afternoon_login_end'] ?? '13:30'); ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="afternoon_late_time">Afternoon Late After <small style="font-weight:normal;opacity:.6;">(optional)</small></label>
                        <input type="time" name="afternoon_late_time" id="afternoon_late_time" value="<?php echo htmlspecialchars($event['afternoon_late_time'] ?? ''); ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="afternoon_logout_start">Afternoon Logout Start</label>
                        <input type="time" name="afternoon_logout_start" id="afternoon_logout_start" value="<?php echo htmlspecialchars($event['afternoon_logout_start'] ?? '16:30'); ?>">
                    </div>
                    <div class="form-group">
                        <label for="afternoon_logout_end">Afternoon Logout End</label>
                        <input type="time" name="afternoon_logout_end" id="afternoon_logout_end" value="<?php echo htmlspecialchars($event['afternoon_logout_end'] ?? '17:00'); ?>">
                    </div>
                </div>
            </div>
            
            <!-- Form Buttons -->
            <div class="btn-group">
                <button type="submit" name="update_event" class="btn btn-primary">Update Event</button>
                <a href="event_history.php" class="btn btn-secondary">Cancel</a>
            </div>
            
        </form>
    </div>
    
    <div>
        <!-- Banner Upload -->
        <div class="card" style="margin-bottom: 30px;">
            <h2>📷 Event Banner</h2>
            <form method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label>Upload New Banner</label>
                    <input type="file" name="event_banner" accept="image/jpeg,image/jpg,image/png" style="padding: 10px;">
                    <small style="color: rgba(255,255,255,0.7);">JPG, PNG - Max 5MB</small>
                </div>
                <div class="btn-group">
                    <button type="submit" name="upload_banner" class="btn btn-primary">Upload Banner</button>
                </div>
            </form>
        </div>
        
        <!-- Event History -->
        <div class="card">
            <h2>📜 Event History</h2>
            
            <?php if($history->num_rows > 0): ?>
                <?php while($log = $history->fetch_assoc()): ?>
                    <div class="history-item">
                        <div class="history-time"><?php echo date('M d, Y h:i A', strtotime($log['change_time'])); ?></div>
                        <div class="history-action"><?php echo htmlspecialchars($log['action_type']); ?></div>
                        <div class="history-description"><?php echo htmlspecialchars($log['change_description']); ?></div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <p style="color: rgba(255,255,255,0.7); text-align: center; padding: 20px;">No history records found for this event.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

</div>

<script>
function toggleTimeFields(){
    const checked = document.querySelector('input[name="event_type"]:checked');
    const eventType = checked ? checked.value : 'Whole Day';
    const morningSection = document.getElementById('morning_section');
    const afternoonSection = document.getElementById('afternoon_section');

    morningSection.style.display = 'block';
    afternoonSection.style.display = 'block';

    if(eventType === 'Morning Only'){
        morningSection.style.display = 'block';
        afternoonSection.style.display = 'none';
    }
    else if(eventType === 'Afternoon Only'){
        morningSection.style.display = 'none';
        afternoonSection.style.display = 'block';
    }
}
// Run on page load to match current event type
document.addEventListener('DOMContentLoaded', toggleTimeFields);
</script>

</body>
</html>
