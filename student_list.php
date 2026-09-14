<?php
session_start();
if(!isset($_SESSION['admin_id']) && !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: admin_login.php");
    exit;
}

$config = require __DIR__ . '/config.php';
require __DIR__ . '/db.php';

/* ── ONE-TIME MIGRATION ─────────────────────────────────────────────────── */
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS is_officer TINYINT(1) NOT NULL DEFAULT 0");

/* ── TOGGLE OFFICER ──────────────────────────────────────────────────────── */
$officerMsg = ''; $officerErr = '';
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_officer_id'])){
    $togId  = intval($_POST['toggle_officer_id']);
    $newVal = intval($_POST['officer_new_val']);
    $u = $conn->query("SELECT CONCAT(first_name,' ',last_name) as n FROM users WHERE id=$togId AND role='student' LIMIT 1")->fetch_assoc();
    if($u){
        $conn->query("UPDATE users SET is_officer=$newVal WHERE id=$togId");
        $label = $newVal ? 'promoted to Officer' : 'removed from Officer';
        $officerMsg = htmlspecialchars($u['n']) . " has been <strong>$label</strong>.";
    } else { $officerErr = "Student not found."; }
}

/* ── DELETE STUDENT ─────────────────────────────────────────────────────── */
$deleteMsg = '';
$deleteErr = '';
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_student_id'])){
    $delId = intval($_POST['delete_student_id']);
    $check = $conn->query("SELECT id, role, CONCAT(first_name,' ',last_name) as full_name FROM users WHERE id=$delId LIMIT 1")->fetch_assoc();
    if(!$check){
        $deleteErr = "Student not found.";
    } elseif($check['role'] !== 'student'){
        $deleteErr = "Cannot delete admin accounts from here.";
    } else {
        // Delete face images from disk
        $faceRes = $conn->query("SELECT face_image FROM face_data WHERE student_id=$delId");
        while($f = $faceRes->fetch_assoc()){
            if(!empty($f['face_image']) && file_exists($f['face_image'])){
                @unlink($f['face_image']);
            }
        }
        // Delete user (cascade handles attendance, face_data, payments)
        $conn->query("DELETE FROM users WHERE id=$delId");
        $deleteMsg = "Student <strong>" . htmlspecialchars($check['full_name']) . "</strong> and all connected data deleted.";
    }
}

/* ── EDIT ACADEMIC INFO ─────────────────────────────────────────────────── */
$editMsg = '';
$editErr = '';

/* ── ACCEPT CASH PAYMENT ───────────────────────────────────────────────── */
$cashMsg = '';
$cashErr = '';
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] == 'accept_cash'){
    $cash_sid = intval($_POST['cash_student_id']);
    $cash_amount = floatval($_POST['cash_amount']);
    $cash_date = $_POST['cash_date'];
    $admin_id = $_SESSION['admin_id'];
    
    if($cash_amount > 0){
        $stmt = $conn->prepare("INSERT INTO payments (student_id, amount, payment_method, payment_date, status, admin_notes, confirmed_by, confirmed_at) VALUES (?, ?, 'Cash', ?, 'Confirmed', 'Cash payment received at counter', ?, NOW())");
        $stmt->bind_param("idsi", $cash_sid, $cash_amount, $cash_date, $admin_id);
        if($stmt->execute()){
            $cashMsg = "Cash payment of ₱".number_format($cash_amount,2)." accepted successfully.";
        } else {
            $cashErr = "Error accepting cash payment.";
        }
    } else {
        $cashErr = "Invalid amount.";
    }
}

if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_academic_id'])){
    $editId = intval($_POST['edit_academic_id']);
    $newYearLevel = $_POST['edit_year_level'];
    $newSchoolYear = $_POST['edit_school_year'];
    $newSemester = $_POST['edit_semester'];
    $newSection = $_POST['edit_section'];
    
    $check = $conn->query("SELECT id, CONCAT(first_name,' ',last_name) as full_name FROM users WHERE id=$editId AND role='student' LIMIT 1")->fetch_assoc();
    if(!$check){
        $editErr = "Student not found.";
    } else {
        $stmt = $conn->prepare("UPDATE users SET year_level=?, school_year=?, current_semester=?, section=? WHERE id=?");
        $stmt->bind_param("ssssi", $newYearLevel, $newSchoolYear, $newSemester, $newSection, $editId);
        if($stmt->execute()){
            $editMsg = "Academic info updated for <strong>" . htmlspecialchars($check['full_name']) . "</strong>.";
        } else {
            $editErr = "Failed to update: " . $conn->error;
        }
    }
}

$activeTab = $_GET['tab'] ?? 'students';

/* ── STUDENT LIST filters ────────────────────────────────────────────────── */
$course  = $_GET['course']      ?? '';
$year    = $_GET['year_level']  ?? '';
$section = $_GET['section']     ?? '';

$sql = "
SELECT u.id, u.student_id, u.first_name, u.middle_name, u.last_name,
       u.course, u.year_level, u.section,
       u.face_registered, u.is_officer,
       COALESCE(SUM(
           CASE
               WHEN e.event_type = 'Morning Only' THEN
                   CASE WHEN a.morning_status='Late'   THEN e.late_penalty
                        WHEN a.morning_status='Absent' THEN e.absent_penalty
                        ELSE 0 END
               WHEN e.event_type = 'Afternoon Only' THEN
                   CASE WHEN a.afternoon_status='Late'   THEN e.late_penalty
                        WHEN a.afternoon_status='Absent' THEN e.absent_penalty
                        ELSE 0 END
               ELSE
                   CASE
                       WHEN a.morning_status='Present' OR a.afternoon_status='Present' THEN 0
                       WHEN a.morning_status='Late'    OR a.afternoon_status='Late'    THEN e.late_penalty
                       WHEN a.morning_status='Absent'  OR a.afternoon_status='Absent'  THEN e.absent_penalty
                       ELSE 0
                   END
           END
       ),0) AS att_penalty,
       (SELECT COALESCE(SUM(amount),0) FROM payments WHERE student_id=u.id AND status='Confirmed') AS paid,
       (SELECT status FROM payments WHERE student_id=u.id
        ORDER BY created_at DESC LIMIT 1) AS pay_status
FROM users u
LEFT JOIN attendance a ON u.id = a.student_id
LEFT JOIN events e ON a.event_id = e.id
WHERE u.role='student'
";
if($course  != '') $sql .= " AND u.course='$course'";
if($year    != '') $sql .= " AND u.year_level='$year'";
if($section != '') $sql .= " AND u.section='$section'";
$sql .= " GROUP BY u.id ORDER BY u.last_name ASC";
$students = $conn->query($sql);

/* ── REPORTS filters ─────────────────────────────────────────────────────── */
$eventFilter  = $_GET['event_id'] ?? '';
$courseFilter = $_GET['rcourse']  ?? '';
$search       = $_GET['search']   ?? '';
$showPaid     = $_GET['show_paid'] ?? '0';

$rQuery = "
SELECT a.*, u.first_name, u.last_name, u.course,
       e.event_name, e.event_date, e.event_type, e.late_penalty, e.absent_penalty,
       (SELECT COALESCE(SUM(amount),0) FROM payments WHERE student_id=u.id AND status='Confirmed') AS total_paid
FROM attendance a
JOIN users u ON a.student_id = u.id
JOIN events e ON a.event_id  = e.id
WHERE 1
";
if($eventFilter  != '') $rQuery .= " AND a.event_id='$eventFilter'";
if($courseFilter != '') $rQuery .= " AND u.course='$courseFilter'";
if($search       != '') $rQuery .= " AND (u.first_name LIKE '%$search%' OR u.last_name LIKE '%$search%')";
$rQuery .= " ORDER BY e.event_date DESC, u.last_name ASC";

$reports = $conn->query($rQuery);

// Filter out fully paid students if show_paid is 0
$filteredReports = [];
while($row = $reports->fetch_assoc()){
    $total_paid = floatval($row['total_paid'] ?? 0);
    
    // Calculate total accrued for this student
    $accruedQuery = "SELECT COALESCE(SUM(
        CASE
            WHEN e.event_type = 'Morning Only' THEN
                CASE WHEN a.morning_status='Late'   THEN e.late_penalty
                     WHEN a.morning_status='Absent' THEN e.absent_penalty
                     ELSE 0 END
            WHEN e.event_type = 'Afternoon Only' THEN
                CASE WHEN a.afternoon_status='Late'   THEN e.late_penalty
                     WHEN a.afternoon_status='Absent' THEN e.absent_penalty
                     ELSE 0 END
            ELSE
                CASE
                    WHEN a.morning_status='Present' OR a.afternoon_status='Present' THEN 0
                    WHEN a.morning_status='Late'    OR a.afternoon_status='Late'    THEN e.late_penalty
                    WHEN a.morning_status='Absent'  OR a.afternoon_status='Absent'  THEN e.absent_penalty
                    ELSE 0
                END
        END
    ),0) AS total_accrued
    FROM attendance a
    JOIN events e ON a.event_id = e.id
    WHERE a.student_id = {$row['student_id']}";
    $accruedResult = $conn->query($accruedQuery)->fetch_assoc();
    $total_accrued = floatval($accruedResult['total_accrued'] ?? 0);
    
    $row['total_accrued'] = $total_accrued;
    $row['outstanding'] = max(0, $total_accrued - $total_paid);
    
    // Only include if show_paid is 1 OR student still has outstanding penalty
    if($showPaid == '1' || $row['outstanding'] > 0){
        $filteredReports[] = $row;
    }
}

/* summary stats */
$totalPresent = $totalLate = $totalAbsent = 0;
$temp = $conn->query($rQuery);
while($r = $temp->fetch_assoc()){
    $ms = $r['morning_status'] ?? '';
    $as = $r['afternoon_status'] ?? '';
    if($ms=='Present' || $as=='Present'){
        $totalPresent++;
    }
    elseif($ms=='Late' || $as=='Late'){
        $totalLate++;
    }
    else{
        $totalAbsent++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Students & Reports</title>
<script src="https://cdn.jsdelivr.net/npm/xlsx/dist/xlsx.full.min.js"></script>
<style>
*{margin:0;padding:0;box-sizing:border-box;font-family:'Segoe UI',sans-serif;}
html,body{overflow-x:hidden;}
body{background:linear-gradient(135deg,rgba(0,0,0,.96),rgba(0,0,0,.88));color:white;min-height:100vh;}

/* HEADER */
.page-header{
    background:rgba(0,0,0,.85);padding:18px 28px;
    display:flex;justify-content:space-between;align-items:center;
    border-bottom:2px solid rgba(255,215,0,.2);backdrop-filter:blur(20px);
    flex-wrap:wrap;gap:12px;
}
.page-header h1{
    font-size:26px;font-weight:900;
    background:linear-gradient(45deg,#FFD700,#FFA500);
    -webkit-background-clip:text;-webkit-text-fill-color:transparent;
}
.back-link{
    padding:10px 18px;background:rgba(255,255,255,.08);
    border:1px solid rgba(255,215,0,.3);color:white;
    text-decoration:none;border-radius:12px;font-weight:600;
    transition:.2s;font-size:14px;
}
.back-link:hover{background:rgba(255,215,0,.15);}

/* TABS */
.tabs{display:flex;gap:6px;padding:20px 28px 0;}
.tab-btn{
    padding:11px 26px;border:none;border-radius:12px 12px 0 0;
    font-weight:800;font-size:15px;cursor:pointer;transition:.2s;
    background:rgba(255,255,255,.06);color:rgba(255,255,255,.6);
    border-bottom:2px solid transparent;
}
.tab-btn.active{
    background:rgba(255,215,0,.12);color:#FFD700;
    border-bottom:2px solid #FFD700;
}
.tab-btn:hover:not(.active){background:rgba(255,255,255,.1);color:white;}

/* CONTENT */
.tab-content{display:none;padding:0 28px 32px;}
.tab-content.active{display:block;}

/* FILTER BAR */
.filter-bar{
    background:rgba(255,255,255,.04);border:1px solid rgba(255,215,0,.15);
    border-radius:16px;padding:18px 20px;margin:18px 0;
    display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;
}
.filter-bar select,.filter-bar input{
    padding:10px 14px;background:rgba(255,255,255,.07);
    border:1px solid rgba(255,215,0,.25);border-radius:10px;
    color:white;font-size:14px;outline:none;
}
.filter-bar select option{background:#111;color:white;}
.filter-bar select:focus,.filter-bar input:focus{border-color:#FFD700;}
.filter-bar input::placeholder{color:rgba(255,255,255,.4);}
.btn-filter{
    padding:10px 20px;background:linear-gradient(45deg,#FFD700,#FFA500);
    color:#111;border:none;border-radius:10px;font-weight:800;
    cursor:pointer;transition:.2s;font-size:14px;
}
.btn-filter:hover{opacity:.9;transform:translateY(-1px);}
.btn-export{
    padding:10px 18px;background:rgba(255,255,255,.08);
    border:1px solid rgba(255,215,0,.3);color:#FFD700;
    border-radius:10px;font-weight:700;cursor:pointer;transition:.2s;font-size:14px;
}
.btn-export:hover{background:rgba(255,215,0,.15);}

/* STATS ROW */
.stats-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:14px;margin-bottom:18px;}
.stat-card{
    background:rgba(255,255,255,.05);border:1px solid rgba(255,215,0,.15);
    border-radius:14px;padding:16px;text-align:center;
}
.stat-num{font-size:30px;font-weight:900;background:linear-gradient(45deg,#FFD700,#FFA500);-webkit-background-clip:text;-webkit-text-fill-color:transparent;}
.stat-label{font-size:12px;color:rgba(255,255,255,.6);margin-top:4px;text-transform:uppercase;letter-spacing:.5px;}
.stat-present .stat-num{background:linear-gradient(45deg,#51cf66,#37b24d);-webkit-background-clip:text;-webkit-text-fill-color:transparent;}
.stat-late    .stat-num{background:linear-gradient(45deg,#ffa500,#e67e00);-webkit-background-clip:text;-webkit-text-fill-color:transparent;}
.stat-absent  .stat-num{background:linear-gradient(45deg,#ff6b6b,#dc3545);-webkit-background-clip:text;-webkit-text-fill-color:transparent;}

/* TABLE */
.table-wrap{overflow:auto;border-radius:14px;border:1px solid rgba(255,215,0,.12);}
table{width:100%;border-collapse:collapse;min-width:700px;}
thead tr{background:rgba(255,215,0,.1);}
th{padding:13px 14px;font-size:13px;font-weight:800;color:#FFD700;text-align:center;white-space:nowrap;}
td{padding:13px 14px;font-size:13px;text-align:center;border-bottom:1px solid rgba(255,255,255,.05);}
tbody tr:hover{background:rgba(255,215,0,.04);}

/* BADGES */
.badge{padding:5px 10px;border-radius:20px;font-size:11px;font-weight:800;display:inline-block;}
.b-present{background:rgba(81,207,102,.15);color:#51cf66;}
.b-late{background:rgba(255,165,0,.15);color:#ffa500;}
.b-absent{background:rgba(255,107,107,.15);color:#ff6b6b;}
.b-reg{background:rgba(116,192,252,.15);color:#74c0fc;}
.b-pending{background:rgba(255,165,0,.15);color:#ffa500;}
.b-confirmed{background:rgba(81,207,102,.15);color:#51cf66;}
.b-rejected{background:rgba(255,107,107,.15);color:#ff6b6b;}

.penalty-red{color:#ff6b6b;font-weight:800;}
.penalty-green{color:#51cf66;font-weight:800;}
.empty{padding:40px;text-align:center;color:rgba(255,255,255,.4);font-size:16px;}

/* OFFICER BUTTON */
.btn-officer{
    padding:5px 10px;border-radius:8px;font-size:11px;font-weight:800;
    cursor:pointer;transition:all .2s;border:none;
}
.btn-officer.make{
    background:rgba(255,215,0,.15);color:#FFD700;
    border:1px solid rgba(255,215,0,.4);
}
.btn-officer.make:hover{background:rgba(255,215,0,.3);}
.btn-officer.revoke{
    background:rgba(255,165,0,.15);color:#ffa500;
    border:1px solid rgba(255,165,0,.4);
}
.btn-officer.revoke:hover{background:rgba(255,165,0,.3);}
.b-officer{background:rgba(255,215,0,.18);color:#FFD700;}

/* DELETE BUTTON */
.btn-delete{
    background:rgba(255,107,107,.2);
    color:#ff6b6b;
    border:1px solid rgba(255,107,107,.4);
    padding:5px 12px;
    border-radius:8px;
    font-size:12px;
    font-weight:700;
    cursor:pointer;
    transition:all .2s;
}
.btn-delete:hover{
    background:rgba(255,107,107,.35);
    transform:translateY(-1px);
}

/* ALERTS */
.alert{
    margin:16px 22px;
    padding:14px 18px;
    border-radius:12px;
    font-size:14px;
    font-weight:600;
}
.alert-success{
    background:rgba(81,207,102,.12);
    color:#51cf66;
    border:1px solid rgba(81,207,102,.25);
}
.alert-error{
    background:rgba(255,107,107,.12);
    color:#ff6b6b;
    border:1px solid rgba(255,107,107,.25);
}

/* MOBILE */
@media(max-width:768px){
    .page-header{padding:12px 16px;flex-direction:column;gap:10px;}
    .page-header h1{font-size:20px;}
    .back-link{width:100%;text-align:center;padding:10px;}
    .tabs{padding:12px 16px 0;}
    .tab-content{padding:0 12px 20px;}
    .tab-btn{padding:8px 14px;font-size:12px;}
    .filter-bar{grid-template-columns:1fr;gap:10px;padding:14px;}
    .filter-bar select,.filter-bar input{padding:8px 12px;font-size:13px;}
    .btn-filter,.btn-export{padding:8px 14px;font-size:13px;width:100%;}
    .stats-row{grid-template-columns:1fr 1fr;gap:10px;}
    .stat-card{padding:12px;}
    .stat-num{font-size:24px;}
    .table-wrap{overflow-x:auto;}
    table{min-width:600px;font-size:12px;}
    th,td{padding:8px 6px;}
    .badge{font-size:10px;padding:3px 8px;}
    .btn-officer,.btn-delete{padding:4px 8px;font-size:10px;}
    .alert{margin:12px;padding:10px 14px;font-size:13px;}
}
</style>
</head>
<body>

<div class="page-header">
    <h1>🎓 Students &amp; Reports</h1>
    <a href="admin_dashboard.php" class="back-link">← Dashboard</a>
</div>

<div class="tabs">
    <button class="tab-btn <?= $activeTab==='students'?'active':'' ?>" onclick="switchTab('students')">👥 Student List</button>
    <button class="tab-btn <?= $activeTab==='reports' ?'active':'' ?>" onclick="switchTab('reports')">📊 Attendance Reports</button>
</div>

<!-- ═══════════════════════════════════════ STUDENTS TAB ═══════════════ -->
<div class="tab-content <?= $activeTab==='students'?'active':'' ?>" id="tab-students">

<form method="GET" class="filter-bar">
    <input type="hidden" name="tab" value="students">
    <select name="course">
        <option value="">All Courses</option>
        <option value="BSCS" <?= $course=="BSCS"?"selected":"" ?>>BSCS</option>
        <option value="BSIT" <?= $course=="BSIT"?"selected":"" ?>>BSIT</option>
        <option value="BLIS" <?= $course=="BLIS"?"selected":"" ?>>BLIS</option>
    </select>
    <select name="year_level">
        <option value="">All Years</option>
        <option value="1st Year" <?= $year=="1st Year"?"selected":"" ?>>1st Year</option>
        <option value="2nd Year" <?= $year=="2nd Year"?"selected":"" ?>>2nd Year</option>
        <option value="3rd Year" <?= $year=="3rd Year"?"selected":"" ?>>3rd Year</option>
        <option value="4th Year" <?= $year=="4th Year"?"selected":"" ?>>4th Year</option>
    </select>
    <select name="section">
        <option value="">All Sections</option>
        <option value="A" <?= $section=="A"?"selected":"" ?>>A</option>
        <option value="B" <?= $section=="B"?"selected":"" ?>>B</option>
        <option value="C" <?= $section=="C"?"selected":"" ?>>C</option>
        <option value="D" <?= $section=="D"?"selected":"" ?>>D</option>
    </select>
    <button type="submit" class="btn-filter">🔍 Filter</button>
    <button type="button" class="btn-export" onclick="exportStudents()">📥 Export</button>
</form>

<div class="table-wrap">
<table id="studentTable">
<thead>
<tr>
    <th>Student ID</th><th>Name</th><th>Course</th><th>Year</th>
    <th>Section</th><th>Face</th><th>Role</th><th>Penalty</th><th>Payment</th><th>Action</th>
</tr>
</thead>
<tbody>
<?php if($students->num_rows > 0): ?>
    <?php while($row = $students->fetch_assoc()): ?>
    <tr>
        <td><?= htmlspecialchars($row['student_id']) ?></td>
        <td style="text-align:left;"><?= htmlspecialchars(trim($row['last_name'].', '.$row['first_name'].' '.$row['middle_name'])) ?></td>
        <td><?= htmlspecialchars($row['course']) ?></td>
        <?php $s_pen = max(0, floatval($row['att_penalty'] ?? 0) - floatval($row['paid'] ?? 0)); ?>
        <td><?= htmlspecialchars($row['year_level']) ?></td>
        <td><?= htmlspecialchars($row['section']) ?></td>
        <td><span class="badge <?= $row['face_registered']?'b-reg':'b-absent' ?>"><?= $row['face_registered']?'✓ Registered':'Pending' ?></span></td>
        <td>
            <?php if($row['is_officer']): ?>
                <span class="badge b-officer">🔑 Officer</span>
            <?php else: ?>
                <span style="color:rgba(255,255,255,.3);font-size:12px;">Student</span>
            <?php endif; ?>
        </td>
        <td class="<?= $s_pen>0?'penalty-red':'penalty-green' ?>">₱<?= number_format($s_pen,2) ?></td>
        <td>
            <?php
            $ps = $row['pay_status'] ?? '';
            if($ps==='Pending')   echo '<span class="badge b-pending">⏳ Pending</span>';
            elseif($ps==='Confirmed') echo '<span class="badge b-confirmed">✓ Paid</span>';
            elseif($ps==='Rejected')  echo '<span class="badge b-rejected">✗ Rejected</span>';
            else echo '<span style="color:rgba(255,255,255,.4);font-size:12px;">—</span>';
            ?>
        </td>
        <td>
            <form method="POST" style="display:inline;margin-right:4px;">
                <input type="hidden" name="toggle_officer_id" value="<?= $row['id'] ?>">
                <input type="hidden" name="officer_new_val" value="<?= $row['is_officer']?0:1 ?>">
                <?php if($row['is_officer']): ?>
                    <button type="submit" class="btn-officer revoke" title="Remove officer role">✕ Officer</button>
                <?php else: ?>
                    <button type="submit" class="btn-officer make" title="Make officer">🔑 Officer</button>
                <?php endif; ?>
            </form>
            <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this student and ALL their data? This cannot be undone.');">
                <input type="hidden" name="delete_student_id" value="<?= $row['id'] ?>">
                <button type="submit" class="btn-delete" title="Delete student">Delete</button>
            </form>
        </td>
    </tr>
    <?php endwhile; ?>
<?php else: ?>
    <tr><td colspan="10" class="empty">No students found.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>

<?php if($deleteMsg):  ?><div class="alert alert-success"><?= $deleteMsg ?></div><?php endif; ?>
<?php if($deleteErr):  ?><div class="alert alert-error"><?= $deleteErr ?></div><?php endif; ?>
<?php if($officerMsg): ?><div class="alert alert-success"><?= $officerMsg ?></div><?php endif; ?>
<?php if($officerErr): ?><div class="alert alert-error"><?= $officerErr ?></div><?php endif; ?>
<?php if($editMsg): ?><div class="alert alert-success"><?= $editMsg ?></div><?php endif; ?>
<?php if($editErr): ?><div class="alert alert-error"><?= $editErr ?></div><?php endif; ?>
<?php if($cashMsg): ?><div class="alert alert-success"><?= $cashMsg ?></div><?php endif; ?>
<?php if($cashErr): ?><div class="alert alert-error"><?= $cashErr ?></div><?php endif; ?>

<!-- ═══════════════════════════════════════ REPORTS TAB ════════════════ -->
<div class="tab-content <?= $activeTab==='reports'?'active':'' ?>" id="tab-reports">

<form method="GET" class="filter-bar">
    <input type="hidden" name="tab" value="reports">
    <select name="event_id">
        <option value="">All Events</option>
        <?php
        $evs = $conn->query("SELECT id,event_name,event_date FROM events ORDER BY event_date DESC");
        while($e = $evs->fetch_assoc()):
        ?>
        <option value="<?= $e['id'] ?>" <?= $eventFilter==$e['id']?"selected":"" ?>>
            <?= htmlspecialchars($e['event_name']).' ('.$e['event_date'].')' ?>
        </option>
        <?php endwhile; ?>
    </select>
    <select name="rcourse">
        <option value="">All Courses</option>
        <option value="BSCS" <?= $courseFilter=="BSCS"?"selected":"" ?>>BSCS</option>
        <option value="BSIT" <?= $courseFilter=="BSIT"?"selected":"" ?>>BSIT</option>
        <option value="BLIS" <?= $courseFilter=="BLIS"?"selected":"" ?>>BLIS</option>
    </select>
    <input type="text" name="search" placeholder="Search student…" value="<?= htmlspecialchars($search) ?>">
    <label style="display:flex;align-items:center;gap:6px;color:white;font-size:13px;cursor:pointer;">
        <input type="checkbox" name="show_paid" value="1" <?= $showPaid=='1'?'checked':'' ?>>
        Show Paid
    </label>
    <button type="submit" class="btn-filter">🔍 Filter</button>
    <button type="button" class="btn-export" onclick="exportReports()">📥 Export Excel</button>
    <button type="button" class="btn-export" onclick="window.print()">🖨 Print</button>
</form>

<div class="stats-row">
    <div class="stat-card stat-present"><div class="stat-num"><?= $totalPresent ?></div><div class="stat-label">Present</div></div>
    <div class="stat-card stat-late">   <div class="stat-num"><?= $totalLate ?></div>   <div class="stat-label">Late</div></div>
    <div class="stat-card stat-absent"> <div class="stat-num"><?= $totalAbsent ?></div> <div class="stat-label">Absent</div></div>
    <div class="stat-card"><div class="stat-num"><?= $totalPresent+$totalLate+$totalAbsent ?></div><div class="stat-label">Total Records</div></div>
</div>

<div class="table-wrap">
<?php if(count($filteredReports) == 0): ?>
    <div class="empty">📭 No records found.</div>
<?php else: ?>
<table id="reportTable">
<thead>
<tr>
    <th>Event</th><th>Date</th><th>Student Name</th><th>Course</th><th>Status</th><th>Penalty (₱)</th><th>Outstanding (₱)</th><th>Action</th>
</tr>
</thead>
<tbody>
<?php foreach($filteredReports as $row):
    $ms = $row['morning_status'] ?? '';
    $as = $row['afternoon_status'] ?? '';
    if($ms=='Present' || $as=='Present'){
        $status = 'Present';
    }
    elseif($ms=='Late' || $as=='Late'){
        $status = 'Late';
    }
    else{
        $status = 'Absent';
    }
    // Compute penalty from per-session statuses (Whole Day = single charge)
    $late_p   = floatval($row['late_penalty']   ?? 0);
    $absent_p = floatval($row['absent_penalty'] ?? 0);
    $et = $row['event_type'] ?? '';
    if($et === 'Morning Only'){
        $penalty = ($ms==='Late') ? $late_p : (($ms==='Absent') ? $absent_p : 0);
    } elseif($et === 'Afternoon Only'){
        $penalty = ($as==='Late') ? $late_p : (($as==='Absent') ? $absent_p : 0);
    } else {
        if($ms==='Present' || $as==='Present')   $penalty = 0;
        elseif($ms==='Late'   || $as==='Late')   $penalty = $late_p;
        elseif($ms==='Absent' || $as==='Absent') $penalty = $absent_p;
        else                                      $penalty = 0;
    }
    $cls = 'b-present';
    if($status=='Late')   $cls = 'b-late';
    if($status=='Absent') $cls = 'b-absent';
    $outstanding = $row['outstanding'] ?? 0;
?>
<tr>
    <td style="text-align:left;"><?= htmlspecialchars($row['event_name']) ?></td>
    <td><?= $row['event_date'] ?></td>
    <td style="text-align:left;"><?= htmlspecialchars($row['first_name'].' '.$row['last_name']) ?></td>
    <td><?= htmlspecialchars($row['course']) ?></td>
    <td><span class="badge <?= $cls ?>"><?= $status ?></span></td>
    <td class="<?= $penalty>0?'penalty-red':'penalty-green' ?>">₱<?= number_format($penalty,2) ?></td>
    <td class="<?= $outstanding>0?'penalty-red':'penalty-green' ?>">₱<?= number_format($outstanding,2) ?></td>
    <td>
        <?php if($outstanding > 0): ?>
            <button class="btn-filter" style="padding:5px 10px;font-size:11px;" onclick="acceptCashPayment(<?= $row['student_id'] ?>, <?= $outstanding ?>, '<?= htmlspecialchars($row['first_name'].' '.$row['last_name']) ?>')">💵 Pay Cash</button>
        <?php else: ?>
            <span class="badge b-confirmed">✓ Paid</span>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</div>
</div>

<script>
function switchTab(tab){
    document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(c=>c.classList.remove('active'));
    document.getElementById('tab-'+tab).classList.add('active');
    document.querySelectorAll('.tab-btn').forEach(b=>{
        if(b.textContent.toLowerCase().includes(tab==='students'?'student':'report'))
            b.classList.add('active');
    });
}
function exportStudents(){
    var wb = XLSX.utils.table_to_book(document.getElementById('studentTable'),{sheet:'Students'});
    XLSX.writeFile(wb,'student_list.xlsx');
}
function exportReports(){
    var t = document.getElementById('reportTable');
    if(!t){alert('No data to export.');return;}
    var wb = XLSX.utils.table_to_book(t,{sheet:'Attendance Report'});
    XLSX.writeFile(wb,'attendance_report.xlsx');
}

function openEditModal(id, yearLevel, schoolYear, semester, section){
    document.getElementById('edit_student_id').value = id;
    document.getElementById('edit_year_level').value = yearLevel;
    document.getElementById('edit_school_year').value = schoolYear;
    document.getElementById('edit_semester').value = semester;
    document.getElementById('edit_section').value = section;
    document.getElementById('editModal').style.display = 'flex';
}

function closeEditModal(){
    document.getElementById('editModal').style.display = 'none';
}

function acceptCashPayment(studentId, outstandingAmount, studentName){
    document.getElementById('cash_student_id').value = studentId;
    document.getElementById('cash_student_name').textContent = studentName;
    document.getElementById('cash_amount').value = outstandingAmount;
    document.getElementById('cashModal').style.display = 'flex';
}

function closeCashModal(){
    document.getElementById('cashModal').style.display = 'none';
    document.getElementById('cash_amount').value = '';
}

// Close modals when clicking outside
window.onclick = function(event){
    const editModal = document.getElementById('editModal');
    const cashModal = document.getElementById('cashModal');
    if(event.target == editModal) closeEditModal();
    if(event.target == cashModal) closeCashModal();
}
</script>

<!-- Edit Academic Modal -->
<div id="editModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.7);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:#1a1a1a;border:1px solid rgba(255,215,0,.3);border-radius:16px;padding:24px;width:90%;max-width:450px;">
        <h3 style="color:#FFD700;font-size:20px;font-weight:800;margin-bottom:18px;">✏ Edit Academic Info</h3>
        <form method="POST">
            <input type="hidden" name="edit_academic_id" id="edit_student_id">
            
            <div style="margin-bottom:14px;">
                <label style="display:block;color:#FFD700;font-size:13px;font-weight:700;margin-bottom:6px;">Year Level</label>
                <select name="edit_year_level" id="edit_year_level" style="width:100%;padding:10px;background:#111;border:1px solid rgba(255,215,0,.25);border-radius:8px;color:white;">
                    <option value="1st Year">1st Year</option>
                    <option value="2nd Year">2nd Year</option>
                    <option value="3rd Year">3rd Year</option>
                    <option value="4th Year">4th Year</option>
                </select>
            </div>
            
            <div style="margin-bottom:14px;">
                <label style="display:block;color:#FFD700;font-size:13px;font-weight:700;margin-bottom:6px;">School Year</label>
                <select name="edit_school_year" id="edit_school_year" style="width:100%;padding:10px;background:#111;border:1px solid rgba(255,215,0,.25);border-radius:8px;color:white;">
                    <option value="">Select School Year</option>
                    <?php
                    $currentYear = date('Y');
                    for($i = -2; $i < 5; $i++){
                        $sy = ($currentYear + $i) . '-' . ($currentYear + $i + 1);
                        echo "<option value=\"$sy\">$sy</option>";
                    }
                    ?>
                </select>
            </div>
            
            <div style="margin-bottom:14px;">
                <label style="display:block;color:#FFD700;font-size:13px;font-weight:700;margin-bottom:6px;">Semester</label>
                <select name="edit_semester" id="edit_semester" style="width:100%;padding:10px;background:#111;border:1px solid rgba(255,215,0,.25);border-radius:8px;color:white;">
                    <option value="1st Semester">1st Semester</option>
                    <option value="2nd Semester">2nd Semester</option>
                    <option value="Summer">Summer</option>
                </select>
            </div>
            
            <div style="margin-bottom:18px;">
                <label style="display:block;color:#FFD700;font-size:13px;font-weight:700;margin-bottom:6px;">Section</label>
                <input type="text" name="edit_section" id="edit_section" style="width:100%;padding:10px;background:#111;border:1px solid rgba(255,215,0,.25);border-radius:8px;color:white;" placeholder="e.g., A, B, C">
            </div>
            
            <div style="display:flex;gap:10px;">
                <button type="submit" style="flex:1;padding:12px;background:linear-gradient(45deg,#FFD700,#FFA500);color:#111;border:none;border-radius:10px;font-weight:800;cursor:pointer;">Save Changes</button>
                <button type="button" onclick="closeEditModal()" style="flex:1;padding:12px;background:rgba(255,255,255,.08);color:white;border:1px solid rgba(255,215,0,.3);border-radius:10px;font-weight:700;cursor:pointer;">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Cash Payment Modal -->
<div id="cashModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.7);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:#1a1a1a;border:1px solid rgba(255,215,0,.3);border-radius:16px;padding:24px;width:90%;max-width:400px;">
        <span onclick="closeCashModal()" style="position:absolute;right:20px;top:20px;font-size:28px;cursor:pointer;font-weight:bold;color:#666;">&times;</span>
        <h3 style="color:#FFD700;font-size:20px;font-weight:800;margin-bottom:18px;">💵 Accept Cash Payment</h3>
        <form method="POST">
            <input type="hidden" name="action" value="accept_cash">
            <input type="hidden" name="cash_student_id" id="cash_student_id">
            
            <div style="margin-bottom:14px;">
                <label style="display:block;color:#FFD700;font-size:13px;font-weight:700;margin-bottom:6px;">Student</label>
                <div id="cash_student_name" style="color:white;font-weight:bold;"></div>
            </div>
            
            <div style="margin-bottom:14px;">
                <label for="cash_amount" style="display:block;color:#FFD700;font-size:13px;font-weight:700;margin-bottom:6px;">Amount (₱)</label>
                <input type="number" name="cash_amount" id="cash_amount" step="0.01" min="0.01" required style="width:100%;padding:10px;background:#111;border:1px solid rgba(255,215,0,.25);border-radius:8px;color:white;">
            </div>
            
            <div style="margin-bottom:18px;">
                <label for="cash_date" style="display:block;color:#FFD700;font-size:13px;font-weight:700;margin-bottom:6px;">Payment Date</label>
                <input type="date" name="cash_date" id="cash_date" value="<?= date('Y-m-d') ?>" required style="width:100%;padding:10px;background:#111;border:1px solid rgba(255,215,0,.25);border-radius:8px;color:white;">
            </div>
            
            <div style="display:flex;gap:10px;">
                <button type="submit" style="flex:1;padding:12px;background:linear-gradient(45deg,#FFD700,#FFA500);color:#111;border:none;border-radius:10px;font-weight:800;cursor:pointer;">Accept Payment</button>
                <button type="button" onclick="closeCashModal()" style="flex:1;padding:12px;background:rgba(255,255,255,.08);color:white;border:1px solid rgba(255,215,0,.3);border-radius:10px;font-weight:700;cursor:pointer;">Cancel</button>
            </div>
        </form>
    </div>
</div>

</body>
</html>
