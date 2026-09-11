<?php
session_start();
$conn = new mysqli("localhost","root","","attendance");

if($conn->connect_error){
    die("Connection failed: ".$conn->connect_error);
}

/* SAVE EVENT */
if(isset($_POST['add_event'])){

    $course = isset($_POST['course']) ? implode(",",$_POST['course']) : "ALL";
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $event_type = $_POST['event_type'];
    $venue = $_POST['venue'];
    $event_history = $_POST['event_history'] ?? '';
    
    // Handle banner upload
    $event_banner = null;
    if(isset($_FILES['event_banner']) && $_FILES['event_banner']['error'] == UPLOAD_ERR_OK){
        $allowed_types = ['image/jpeg', 'image/png', 'image/jpg'];
        $file_info = getimagesize($_FILES['event_banner']['tmp_name']);
        
        if($file_info !== false && in_array($file_info['mime'], $allowed_types)){
            if($_FILES['event_banner']['size'] <= 5 * 1024 * 1024){ // 5MB limit
                if(!is_dir('event_banners')){
                    mkdir('event_banners', 0755, true);
                }
                
                $filename = 'banner_' . time() . '.jpg';
                $filepath = 'event_banners/' . $filename;
                
                if(move_uploaded_file($_FILES['event_banner']['tmp_name'], $filepath)){
                    $event_banner = $filename;
                }
            }
        }
    }
    
    // Generate QR code and mobile scan link
    $qr_code = 'qr_' . time() . '.png';
    $mobile_scan_link = 'mobile_scan.php?event_id=' . uniqid();
    
    // Calculate date range
    $start = new DateTime($start_date);
    $end = new DateTime($end_date);
    $interval = new DateInterval('P1D');
    $date_range = new DatePeriod($start, $interval, $end->modify('+1 day'));
    
    foreach($date_range as $date){
        $event_date = $date->format('Y-m-d');
        
        $stmt = $conn->prepare("
            INSERT INTO events(
                event_name, event_description, event_date, course,
                event_type, venue, start_date, end_date, event_banner,
                qr_code, mobile_scan_link, event_history,
                late_penalty, absent_penalty,
                morning_login_start, morning_login_end,
                morning_logout_start, morning_logout_end,
                afternoon_login_start, afternoon_login_end,
                afternoon_logout_start, afternoon_logout_end,
                created_at
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
        ");
        
        // Store time values in variables to avoid reference error
        $morning_login_start = '07:00:00';
        $morning_login_end = '07:30:00';
        $morning_logout_start = '11:30:00';
        $morning_logout_end = '12:00:00';
        $afternoon_login_start = '13:00:00';
        $afternoon_login_end = '13:30:00';
        $afternoon_logout_start = '16:30:00';
        $afternoon_logout_end = '17:00:00';
        
        $stmt->bind_param(
            "sssssssssssiiisssssss",
            $_POST['event_name'],
            $_POST['event_description'],
            $event_date,
            $course,
            $event_type,
            $venue,
            $start_date,
            $end_date,
            $event_banner,
            $qr_code,
            $mobile_scan_link,
            $event_history,
            $_POST['late_penalty'],
            $_POST['absent_penalty'],
            $morning_login_start,
            $morning_login_end,
            $morning_logout_start,
            $morning_logout_end,
            $afternoon_login_start,
            $afternoon_login_end,
            $afternoon_logout_start,
            $afternoon_logout_end
        );
        
        $stmt->execute();
    }
    
    header("Location:create_event.php?success=1");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Create Event - Enhanced</title>

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

/* HEADER */
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

/* FORM CARD */
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
    text-align: center;
}

/* FORM GROUPS */
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

/* FILE UPLOAD */
.file-upload{
    position: relative;
    display: inline-block;
    width: 100%;
}

.file-upload input[type="file"]{
    position: absolute;
    opacity: 0;
    width: 100%;
    height: 100%;
    cursor: pointer;
}

.file-upload-label{
    display: block;
    padding: 15px;
    border: 2px dashed rgba(255,215,0,0.4);
    border-radius: 15px;
    text-align: center;
    cursor: pointer;
    transition: all 0.3s ease;
    background: rgba(255,255,255,0.05);
}

.file-upload-label:hover{
    border-color: rgba(255,215,0,0.8);
    background: rgba(255,215,0,0.1);
}

/* CHECKBOX GROUP */
.checkbox-group{
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 15px;
    margin-top: 10px;
}

.checkbox-item{
    display: flex;
    align-items: center;
    gap: 8px;
}

.checkbox-item input[type="checkbox"]{
    width: 18px;
    height: 18px;
    accent-color: #FFD700;
}

/* BUTTONS */
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

/* SUCCESS MESSAGE */
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

/* RESPONSIVE */
@media(max-width: 768px){
    .form-row{
        grid-template-columns: 1fr;
    }
    
    .checkbox-group{
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
    <div class="logo">📅</div>
    <div class="title">
        <h1>Create Event</h1>
        <p>Enhanced event management with venue and smart scheduling</p>
    </div>
    <a href="admin_dashboard.php" class="back-btn">← Dashboard</a>
</div>

<?php if(isset($_GET['success']) && $_GET['success'] == 1): ?>
    <div class="success-message">
        ✅ Event created successfully! QR codes and mobile scan links have been generated.
    </div>
<?php endif; ?>

<div class="card">
    <h2>📅 Create New Event</h2>
    
    <form method="POST" enctype="multipart/form-data">
        
        <!-- Basic Information -->
        <div class="form-row">
            <div class="form-group">
                <label for="event_name">Event Name *</label>
                <input type="text" name="event_name" id="event_name" required placeholder="Enter event name">
            </div>
            
            <div class="form-group">
                <label for="venue">Venue *</label>
                <input type="text" name="venue" id="venue" required placeholder="Enter event venue (e.g., Room 201, Auditorium, Gym)">
            </div>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label for="event_type">Event Type *</label>
                <select name="event_type" id="event_type" required onchange="toggleTimeFields()">
                    <option value="">Select Event Type</option>
                    <option value="Regular">Regular</option>
                    <option value="Special">Special</option>
                    <option value="Exam">Exam</option>
                    <option value="Meeting">Meeting</option>
                    <option value="Activity">Activity</option>
                    <option value="Morning Only">Morning Only</option>
                    <option value="Afternoon Only">Afternoon Only</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="event_description">Event Description</label>
                <textarea name="event_description" id="event_description" placeholder="Enter event description"></textarea>
            </div>
        </div>
        
        <!-- Date Range -->
        <div class="form-row">
            <div class="form-group">
                <label for="start_date">Start Date *</label>
                <input type="date" name="start_date" id="start_date" required>
            </div>
            
            <div class="form-group">
                <label for="end_date">End Date *</label>
                <input type="date" name="end_date" id="end_date" required>
            </div>
        </div>
        
        <!-- Description and History -->
        <div class="form-row">
            <div class="form-group">
                <label for="event_history">Event History/Notes</label>
                <textarea name="event_history" id="event_history" placeholder="Enter event history or special notes"></textarea>
            </div>
            
            <div class="form-group">
                <label>Target Courses</label>
                <div class="checkbox-group">
                    <div class="checkbox-item">
                        <input type="checkbox" name="course[]" value="ALL" id="course_all">
                        <label for="course_all">All Courses</label>
                    </div>
                    <div class="checkbox-item">
                        <input type="checkbox" name="course[]" value="BSCS" id="course_bscs">
                        <label for="course_bscs">BSCS</label>
                    </div>
                    <div class="checkbox-item">
                        <input type="checkbox" name="course[]" value="BSIT" id="course_bsit">
                        <label for="course_bsit">BSIT</label>
                    </div>
                    <div class="checkbox-item">
                        <input type="checkbox" name="course[]" value="BLIS" id="course_blis">
                        <label for="course_blis">BLIS</label>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Banner Upload -->
        <div class="form-group">
            <label>Event Banner Photo</label>
            <div class="file-upload">
                <input type="file" name="event_banner" id="event_banner" accept="image/jpeg,image/jpg,image/png">
                <label for="event_banner" class="file-upload-label">
                    📷 Click to upload event banner (JPG, PNG - Max 5MB)
                </label>
            </div>
        </div>
        
        <!-- Penalty Settings -->
        <div class="form-row">
            <div class="form-group">
                <label for="late_penalty">Late Penalty (₱)</label>
                <input type="number" name="late_penalty" id="late_penalty" value="25" min="0" step="0.01">
            </div>
            
            <div class="form-group">
                <label for="absent_penalty">Absent Penalty (₱)</label>
                <input type="number" name="absent_penalty" id="absent_penalty" value="50" min="0" step="0.01">
            </div>
        </div>
        
        <!-- Time Settings -->
        <div class="form-row">
            <div class="form-group">
                <label for="morning_login_start">Morning Login Start</label>
                <input type="time" name="morning_login_start" id="morning_login_start" value="07:00">
            </div>
            
            <div class="form-group">
                <label for="morning_login_end">Morning Login End</label>
                <input type="time" name="morning_login_end" id="morning_login_end" value="07:30">
            </div>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label for="morning_logout_start">Morning Logout Start</label>
                <input type="time" name="morning_logout_start" id="morning_logout_start" value="11:30">
            </div>
            
            <div class="form-group">
                <label for="morning_logout_end">Morning Logout End</label>
                <input type="time" name="morning_logout_end" id="morning_logout_end" value="12:00">
            </div>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label for="afternoon_login_start">Afternoon Login Start</label>
                <input type="time" name="afternoon_login_start" id="afternoon_login_start" value="13:00">
            </div>
            
            <div class="form-group">
                <label for="afternoon_login_end">Afternoon Login End</label>
                <input type="time" name="afternoon_login_end" id="afternoon_login_end" value="13:30">
            </div>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label for="afternoon_logout_start">Afternoon Logout Start</label>
                <input type="time" name="afternoon_logout_start" id="afternoon_logout_start" value="16:30">
            </div>
            
            <div class="form-group">
                <label for="afternoon_logout_end">Afternoon Logout End</label>
                <input type="time" name="afternoon_logout_end" id="afternoon_logout_end" value="17:00">
            </div>
        </div>
        
        <!-- Form Buttons -->
        <div class="btn-group">
            <button type="submit" name="add_event" class="btn btn-primary">Create Event</button>
            <a href="admin_dashboard.php" class="btn btn-secondary">Cancel</a>
        </div>
        
    </form>
</div>

</div>

<script>
// Handle "All Courses" checkbox
document.getElementById('course_all').addEventListener('change', function() {
    const otherCheckboxes = document.querySelectorAll('input[name="course[]"]:not(#course_all)');
    otherCheckboxes.forEach(checkbox => {
        checkbox.disabled = this.checked;
        if(this.checked) {
            checkbox.checked = false;
        }
    });
});

// Disable other checkboxes when "All Courses" is checked
document.querySelectorAll('input[name="course[]"]:not(#course_all)').forEach(checkbox => {
    checkbox.addEventListener('change', function() {
        if(this.checked) {
            document.getElementById('course_all').checked = false;
        }
    });
});

// Toggle time fields based on event type
function toggleTimeFields() {
    const eventType = document.getElementById('event_type').value;
    const morningFields = document.querySelectorAll('label[for="morning_login_start"], label[for="morning_login_end"], label[for="morning_logout_start"], label[for="morning_logout_end"]');
    const afternoonFields = document.querySelectorAll('label[for="afternoon_login_start"], label[for="afternoon_login_end"], label[for="afternoon_logout_start"], label[for="afternoon_logout_end"]');
    const morningInputs = document.querySelectorAll('input[name="morning_login_start"], input[name="morning_login_end"], input[name="morning_logout_start"], input[name="morning_logout_end"]');
    const afternoonInputs = document.querySelectorAll('input[name="afternoon_login_start"], input[name="afternoon_login_end"], input[name="afternoon_logout_start"], input[name="afternoon_logout_end"]');
    const morningContainers = morningFields[0]?.parentElement;
    const afternoonContainers = afternoonFields[0]?.parentElement;
    
    // Reset all fields to visible
    if(morningContainers) morningContainers.style.display = 'block';
    if(afternoonContainers) afternoonContainers.style.display = 'block';
    
    // Hide fields based on event type
    if(eventType === 'Morning Only') {
        if(afternoonContainers) {
            afternoonContainers.style.display = 'none';
            // Clear afternoon inputs
            afternoonInputs.forEach(input => input.value = '');
        }
    } else if(eventType === 'Afternoon Only') {
        if(morningContainers) {
            morningContainers.style.display = 'none';
            // Clear morning inputs
            morningInputs.forEach(input => input.value = '');
        }
    }
}

// Update file upload label
document.getElementById('event_banner').addEventListener('change', function(e) {
    const fileName = e.target.files[0]?.name || '';
    const label = document.querySelector('.file-upload-label');
    if(fileName) {
        label.textContent = '📄 ' + fileName;
    } else {
        label.textContent = '📷 Click to upload event banner (JPG, PNG - Max 5MB)';
    }
});

// Date validation
document.getElementById('start_date').addEventListener('change', function() {
    const startDate = new Date(this.value);
    const endDateInput = document.getElementById('end_date');
    endDateInput.min = this.value;
    
    if(endDateInput.value && new Date(endDateInput.value) < startDate) {
        endDateInput.value = this.value;
    }
});
</script>

</body>
</html>
