<?php
date_default_timezone_set('Asia/Manila');
session_start();
include("db.php");

// Check if admin is logged in
if(!isset($_SESSION['admin_id'])){
    header("Location: admin_login.php");
    exit();
}

$conn = new mysqli("localhost","root","","attendance");

if($conn->connect_error){
    die("Connection failed: " . $conn->connect_error);
}

$admin_id = $_SESSION['admin_id'];
$message = "";

// Ensure payment_method supports Waiver
$conn->query("ALTER TABLE payments MODIFY COLUMN payment_method ENUM('GCash','Cash','Waiver') NOT NULL DEFAULT 'Cash'");

// Helper: compute accrued penalty for a student (matches student_dashboard logic)
function computeAccrued($conn, $sid){
    $r = $conn->query("
        SELECT COALESCE(SUM(
            CASE
                WHEN e.event_type='Morning Only' THEN
                    CASE WHEN a.morning_status='Late' THEN e.late_penalty
                         WHEN a.morning_status='Absent' THEN e.absent_penalty ELSE 0 END
                WHEN e.event_type='Afternoon Only' THEN
                    CASE WHEN a.afternoon_status='Late' THEN e.late_penalty
                         WHEN a.afternoon_status='Absent' THEN e.absent_penalty ELSE 0 END
                ELSE
                    CASE
                        WHEN a.morning_status='Present' OR a.afternoon_status='Present' THEN 0
                        WHEN a.morning_status='Late'   OR a.afternoon_status='Late'   THEN e.late_penalty
                        WHEN a.morning_status='Absent' OR a.afternoon_status='Absent' THEN e.absent_penalty
                        ELSE 0
                    END
            END
        ),0) as p
        FROM attendance a
        JOIN events e ON a.event_id=e.id
        WHERE a.student_id=$sid
    ")->fetch_assoc();
    return floatval($r['p'] ?? 0);
}
function computePaid($conn, $sid){
    $r = $conn->query("SELECT COALESCE(SUM(amount),0) as p FROM payments WHERE student_id=$sid AND status='Confirmed'")->fetch_assoc();
    return floatval($r['p'] ?? 0);
}

// Handle POST actions
if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])){

    $action = $_POST['action'];

    // ── Update GCash number ───────────────────────────────────────────────
    if($action == 'update_gcash'){
        $new_gcash = trim($_POST['gcash_number']);
        if(!empty($new_gcash) && preg_match("/^[0-9]{11}$/", $new_gcash)){
            $stmt = $conn->prepare("UPDATE admin SET gcash_number = ? WHERE id = 1");
            $stmt->bind_param("s", $new_gcash);
            if($stmt->execute()){
                $message = "✅ GCash number updated successfully.";
            } else {
                $message = "Error updating GCash number.";
            }
        } else {
            $message = "Invalid GCash number format. Must be 11 digits.";
        }

    // ── Accept cash payment ───────────────────────────────────────────────
    } elseif($action == 'accept_cash'){
        $cash_sid = intval($_POST['cash_student_id']);
        $cash_amount = floatval($_POST['cash_amount']);
        $cash_date = $_POST['cash_date'];
        
        if($cash_amount > 0){
            $stmt = $conn->prepare("INSERT INTO payments (student_id, amount, payment_method, payment_date, status, admin_notes, confirmed_by, confirmed_at) VALUES (?, ?, 'Cash', ?, 'Confirmed', 'Cash payment received at counter', ?, NOW())");
            $stmt->bind_param("idsi", $cash_sid, $cash_amount, $cash_date, $admin_id);
            if($stmt->execute()){
                $message = "✅ Cash payment of ₱".number_format($cash_amount,2)." accepted successfully.";
            } else {
                $message = "Error accepting cash payment.";
            }
        } else {
            $message = "Invalid amount.";
        }

    // ── Waive penalty manually ───────────────────────────────────────────
    } elseif($action == 'waive_penalty'){
        $waive_sid = intval($_POST['waive_student_id']);
        $outstanding = max(0, computeAccrued($conn,$waive_sid) - computePaid($conn,$waive_sid));
        if($outstanding > 0){
            $today = date('Y-m-d');
            $ws = $conn->prepare("INSERT INTO payments (student_id,amount,payment_method,payment_date,status,admin_notes,confirmed_by,confirmed_at) VALUES (?,?,'Waiver',?,'Confirmed','Penalty waived by admin',?,NOW())");
            $ws->bind_param("idsi", $waive_sid, $outstanding, $today, $admin_id);
            $ws->execute();
            $message = "✅ Penalty of ₱".number_format($outstanding,2)." waived successfully.";
        } else {
            $message = "No outstanding penalty to waive.";
        }

    // ── Confirm / Reject payment ─────────────────────────────────────────
    } else {
        $payment_id  = intval($_POST['payment_id']);
        $admin_notes = trim($_POST['admin_notes'] ?? '');
        $payment     = $conn->query("SELECT * FROM payments WHERE id=$payment_id")->fetch_assoc();

        if(!$payment){
            $message = "Payment not found.";
        } else {
            $status = ($action == 'confirm') ? 'Confirmed' : 'Rejected';
            $stmt = $conn->prepare("UPDATE payments SET status=?,admin_notes=?,confirmed_by=?,confirmed_at=NOW() WHERE id=?");
            $stmt->bind_param("ssii", $status, $admin_notes, $admin_id, $payment_id);

            if($stmt->execute()){
                if($action == 'confirm'){
                    // After confirming, if there is still an outstanding balance, insert a waiver to zero it out
                    $sid         = $payment['student_id'];
                    $remaining   = max(0, computeAccrued($conn,$sid) - computePaid($conn,$sid));
                    if($remaining > 0){
                        $today = date('Y-m-d');
                        $ws2 = $conn->prepare("INSERT INTO payments (student_id,amount,payment_method,payment_date,status,admin_notes,confirmed_by,confirmed_at) VALUES (?,?,'Waiver',?,'Confirmed','Auto-cleared remaining balance on payment approval',?,NOW())");
                        $ws2->bind_param("idsi", $sid, $remaining, $today, $admin_id);
                        $ws2->execute();
                    }
                    $message = "✅ Payment confirmed and penalty cleared successfully!";
                } else {
                    $message = "Payment rejected.";
                }
            } else {
                $message = "Error updating payment status.";
            }
        }
    }
}

// Get pending payments
$pending_payments = $conn->query("
    SELECT p.*, u.first_name, u.last_name, u.student_id as student_number, u.total_penalty
    FROM payments p
    JOIN users u ON p.student_id = u.id
    WHERE p.status = 'Pending'
    ORDER BY p.created_at DESC
");

// Get all payments history
$all_payments = $conn->query("
    SELECT p.*, u.first_name, u.last_name, u.student_id as student_number,
           admin.first_name as admin_first, admin.last_name as admin_last
    FROM payments p
    JOIN users u ON p.student_id = u.id
    LEFT JOIN users admin ON p.confirmed_by = admin.id
    ORDER BY p.created_at DESC
");

// Get current GCash number
$gcash_info = $conn->query("SELECT gcash_number FROM admin LIMIT 1")->fetch_assoc();
$current_gcash = $gcash_info['gcash_number'] ?? '';

// Get statistics
$stats = $conn->query("
    SELECT 
        COUNT(*) as total_payments,
        SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'Confirmed' THEN 1 ELSE 0 END) as confirmed,
        SUM(CASE WHEN status = 'Rejected' THEN 1 ELSE 0 END) as rejected,
        SUM(CASE WHEN status = 'Confirmed' THEN amount ELSE 0 END) as total_collected
    FROM payments
")->fetch_assoc();

// Get students with outstanding penalties (live calc matching student_dashboard)
$outstanding_students = $conn->query("
    SELECT u.id, u.first_name, u.last_name, u.student_id as student_number,
           u.course, u.year_level, u.section,
           COALESCE(SUM(
               CASE
                   WHEN e.event_type='Morning Only' THEN
                       CASE WHEN a.morning_status='Late' THEN e.late_penalty
                            WHEN a.morning_status='Absent' THEN e.absent_penalty ELSE 0 END
                   WHEN e.event_type='Afternoon Only' THEN
                       CASE WHEN a.afternoon_status='Late' THEN e.late_penalty
                            WHEN a.afternoon_status='Absent' THEN e.absent_penalty ELSE 0 END
                   ELSE
                       CASE
                           WHEN a.morning_status='Present' OR a.afternoon_status='Present' THEN 0
                           WHEN a.morning_status='Late'   OR a.afternoon_status='Late'   THEN e.late_penalty
                           WHEN a.morning_status='Absent' OR a.afternoon_status='Absent' THEN e.absent_penalty
                           ELSE 0
                       END
               END
           ),0) as att_penalty,
           (SELECT COALESCE(SUM(amount),0) FROM payments WHERE student_id=u.id AND status='Confirmed') as paid,
           (SELECT status FROM payments WHERE student_id=u.id ORDER BY created_at DESC LIMIT 1) AS last_pay_status
    FROM users u
    LEFT JOIN attendance a ON u.id = a.student_id
    LEFT JOIN events e ON a.event_id = e.id
    WHERE u.role='student'
    GROUP BY u.id
    HAVING (att_penalty - paid) > 0
    ORDER BY (att_penalty - paid) DESC
");
?>

<!DOCTYPE html>
<html>
<head>
    <title>Payment Management - CICS Attendance System</title>
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
        }

        .header {
            background: rgba(0,0,0,0.8);
            backdrop-filter: blur(20px);
            border-bottom: 2px solid rgba(255,215,0,0.3);
            padding: 25px 50px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
        }

        .header h2 {
            font-size: 32px;
            background: linear-gradient(45deg, #FFD700, #FFA500);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            font-weight: 900;
        }

        .logout {
            background: linear-gradient(45deg, #dc3545, #c82333);
            color: white;
            padding: 12px 25px;
            border-radius: 25px;
            text-decoration: none;
            font-weight: bold;
            transition: all 0.3s ease;
        }

        .logout:hover {
            transform: translateY(-2px);
        }

        .container {
            max-width: 1200px;
            margin: 40px auto;
            padding: 0 20px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 25px;
            margin-bottom: 40px;
        }

        .stat-card {
            background: rgba(0,0,0,0.8);
            backdrop-filter: blur(20px);
            border: 2px solid rgba(255,215,0,0.3);
            border-radius: 25px;
            padding: 25px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.5);
            text-align: center;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            border-color: rgba(255,215,0,0.6);
        }

        .stat-value {
            font-size: 36px;
            font-weight: 900;
            background: linear-gradient(45deg, #FFD700, #FFA500);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 10px;
        }

        .stat-label {
            color: rgba(255,255,255,0.8);
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .section {
            background: rgba(0,0,0,0.8);
            backdrop-filter: blur(20px);
            border: 2px solid rgba(255,215,0,0.3);
            border-radius: 25px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.5);
        }

        .section h3 {
            font-size: 24px;
            margin-bottom: 25px;
            background: linear-gradient(45deg, #FFD700, #FFA500);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            font-weight: 800;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            background: rgba(255,215,0,0.1);
            border: 1px solid rgba(255,215,0,0.3);
            padding: 12px;
            text-align: left;
            font-weight: 700;
            color: rgba(255,215,0,0.9);
        }

        td {
            border: 1px solid rgba(255,215,0,0.1);
            padding: 12px;
            color: rgba(255,255,255,0.8);
        }

        .status-pending {
            color: #ffa500;
            font-weight: 600;
        }

        .status-confirmed {
            color: #51cf66;
            font-weight: 600;
        }

        .status-rejected {
            color: #ff6b6b;
            font-weight: 600;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
        }

        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.3s ease;
            text-transform: uppercase;
        }

        .btn-confirm {
            background: linear-gradient(45deg, #28a745, #20c997);
            color: white;
        }

        .btn-confirm:hover {
            transform: translateY(-2px);
        }

        .btn-reject {
            background: linear-gradient(45deg, #dc3545, #c82333);
            color: white;
        }

        .btn-reject:hover {
            transform: translateY(-2px);
        }

        .btn-view {
            background: linear-gradient(45deg, #007bff, #0056b3);
            color: white;
        }

        .receipt-preview {
            max-width: 200px;
            max-height: 150px;
            border-radius: 10px;
            cursor: pointer;
            transition: transform 0.3s ease;
        }

        .receipt-preview:hover {
            transform: scale(1.05);
        }

        .message {
            padding: 15px;
            border-radius: 15px;
            margin-bottom: 20px;
            font-weight: 600;
        }

        .message.success {
            background: rgba(40,167,69,0.2);
            border: 1px solid rgba(40,167,69,0.5);
            color: #51cf66;
        }

        .message.error {
            background: rgba(220,53,69,0.2);
            border: 1px solid rgba(220,53,69,0.5);
            color: #ff6b6b;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.8);
            backdrop-filter: blur(10px);
        }

        .modal-content {
            background: rgba(0,0,0,0.9);
            border: 2px solid rgba(255,215,0,0.3);
            border-radius: 25px;
            padding: 30px;
            max-width: 500px;
            margin: 10% auto;
            position: relative;
        }

        .close {
            position: absolute;
            right: 20px;
            top: 20px;
            font-size: 28px;
            font-weight: bold;
            color: rgba(255,215,0,0.8);
            cursor: pointer;
        }

        .close:hover {
            color: #FFD700;
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

        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid rgba(255,215,0,0.2);
            border-radius: 15px;
            font-size: 14px;
            background: rgba(255,255,255,0.05);
            color: white;
            outline: none;
            resize: vertical;
            min-height: 80px;
        }

        .form-group textarea:focus {
            border-color: rgba(255,215,0,0.6);
            box-shadow: 0 0 20px rgba(255,215,0,0.3);
        }

        .back-link {
            position: absolute;
            top: 25px;
            left: 50px;
            background: rgba(255,255,255,0.1);
            color: white;
            padding: 10px 20px;
            border-radius: 20px;
            text-decoration: none;
            font-weight: 600;
            border: 1px solid rgba(255,215,0,0.3);
            transition: all 0.3s ease;
        }

        .back-link:hover {
            background: rgba(255,215,0,0.2);
        }

        .empty-state {
            text-align: center;
            padding: 40px;
            color: rgba(255,255,255,0.6);
        }

        /* TABLE SCROLL WRAPPER */
        .table-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        table { min-width: 640px; }

        @media(max-width:768px){
            .back-link { position:static; display:inline-block; margin:15px 0 0 15px; }
            .header { padding:15px 20px; flex-direction:column; align-items:flex-start; gap:10px; }
            .header h2 { font-size:22px; }
            .logout { align-self:flex-end; padding:10px 18px; }
            .container { padding:0 12px; margin:16px auto; }
            .stats-grid { grid-template-columns:repeat(2,1fr); gap:12px; margin-bottom:20px; }
            .stat-card { padding:18px 14px; border-radius:18px; }
            .stat-value { font-size:26px; }
            .stat-label { font-size:12px; }
            .section { padding:18px 14px; border-radius:18px; }
            .section h3 { font-size:19px; margin-bottom:15px; }
            .action-buttons { flex-direction:column; gap:6px; }
            .btn { font-size:11px; padding:7px 12px; text-align:center; }
            .receipt-preview { max-width:120px; max-height:90px; }
            .modal-content { margin:8% 12px; padding:20px; border-radius:18px; }
        }

        @media(max-width:480px){
            .stats-grid { grid-template-columns:1fr 1fr; gap:8px; }
            .stat-value { font-size:22px; }
            .header h2 { font-size:18px; }
            .section { padding:14px 10px; }
            table { min-width:560px; }
        }
    </style>
</head>
<body>
    <a href="admin_dashboard.php" class="back-link">← Back to Dashboard</a>
    
    <div class="header">
        <h2>💳 Payment Management</h2>
        <a href="logout.php" class="logout">Logout</a>
    </div>

    <div class="container">
        <?php if($message): ?>
            <div class="message <?php echo strpos($message, 'successfully') !== false ? 'success' : 'error'; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <!-- GCash Settings -->
        <div class="section">
            <h3>📱 GCash Settings</h3>
            <form method="POST">
                <input type="hidden" name="action" value="update_gcash">
                <div class="form-group">
                    <label for="gcash_number">Current GCash Number</label>
                    <input type="text" name="gcash_number" id="gcash_number" 
                           value="<?php echo htmlspecialchars($current_gcash); ?>" 
                           placeholder="09XXXXXXXXX" maxlength="11" 
                           style="width:100%; padding:12px; border:2px solid rgba(255,215,0,0.2); border-radius:15px; font-size:16px; background:rgba(255,255,255,0.05); color:white; outline:none;">
                </div>
                <button type="submit" class="btn btn-confirm">Update GCash Number</button>
            </form>
        </div>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['total_payments']; ?></div>
                <div class="stat-label">Total Payments</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['pending']; ?></div>
                <div class="stat-label">Pending</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['confirmed']; ?></div>
                <div class="stat-label">Confirmed</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">₱<?php echo number_format($stats['total_collected'], 2); ?></div>
                <div class="stat-label">Total Collected</div>
            </div>
        </div>

        <!-- Pending Payments -->
        <div class="section">
            <h3>⏳ Pending Payments <span style="font-size:16px;background:rgba(255,165,0,0.2);border:1px solid rgba(255,165,0,0.4);color:#ffa500;padding:3px 12px;border-radius:20px;margin-left:8px;"><?php echo $stats['pending']; ?></span></h3>
            <?php if($pending_payments->num_rows > 0): ?>
                <div class="table-scroll"><table>
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Student ID</th>
                            <th>Amount</th>
                            <th>Method</th>
                            <th>Reference</th>
                            <th>Date</th>
                            <th>Receipt</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($payment = $pending_payments->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($payment['first_name'] . ' ' . $payment['last_name']); ?></td>
                            <td><?php echo htmlspecialchars($payment['student_number']); ?></td>
                            <td>₱<?php echo number_format($payment['amount'], 2); ?></td>
                            <td><?php echo htmlspecialchars($payment['payment_method']); ?></td>
                            <td><?php echo $payment['gcash_reference'] ? htmlspecialchars($payment['gcash_reference']) : '-'; ?></td>
                            <td><?php echo date('M d, Y', strtotime($payment['payment_date'])); ?></td>
                            <td>
                                <?php if($payment['receipt_image']): ?>
                                    <img src="receipts/<?php echo htmlspecialchars($payment['receipt_image']); ?>" 
                                         alt="Receipt" class="receipt-preview" 
                                         onclick="viewReceipt('receipts/<?php echo htmlspecialchars($payment['receipt_image']); ?>')">
                                <?php else: ?>
                                    No receipt
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <button class="btn btn-confirm" onclick="confirmPayment(<?php echo $payment['id']; ?>)">Confirm</button>
                                    <button class="btn btn-reject" onclick="rejectPayment(<?php echo $payment['id']; ?>)">Reject</button>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table></div>
            <?php else: ?>
                <div class="empty-state">
                    <p>📋 No pending payments found.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Outstanding Penalties (no payment submitted yet) -->
        <div class="section">
            <h3>🔴 Outstanding Penalties — No Payment Submitted</h3>
            <?php if($outstanding_students && $outstanding_students->num_rows > 0): ?>
                <div class="table-scroll"><table>
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Student ID</th>
                            <th>Course</th>
                            <th>Year / Section</th>
                            <th>Outstanding Penalty</th>
                            <th>Last Payment</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($s = $outstanding_students->fetch_assoc()):
                            $s_outstanding = max(0, floatval($s['att_penalty'] ?? 0) - floatval($s['paid'] ?? 0));
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($s['first_name'].' '.$s['last_name']); ?></td>
                            <td><?php echo htmlspecialchars($s['student_number']); ?></td>
                            <td><?php echo htmlspecialchars($s['course']); ?></td>
                            <td><?php echo htmlspecialchars($s['year_level'].' / '.$s['section']); ?></td>
                            <td style="color:#ff6b6b;font-weight:800;">₱<?php echo number_format($s_outstanding,2); ?></td>
                            <td>
                                <?php
                                $lps = $s['last_pay_status'] ?? '';
                                if($lps==='Pending') echo '<span style="color:#ffa500;font-weight:700;">⏳ Pending</span>';
                                elseif($lps==='Rejected') echo '<span style="color:#ff6b6b;font-weight:700;">❌ Rejected</span>';
                                else echo '<span style="color:rgba(255,255,255,.4);">None</span>';
                                ?>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <button class="btn btn-confirm" onclick="acceptCashPayment(<?php echo $s['id']; ?>, <?php echo $s_outstanding; ?>, '<?php echo htmlspecialchars($s['first_name'] . ' ' . $s['last_name']); ?>')" style="padding:5px 14px;font-size:13px;">💵 Accept Cash</button>
                                    <form method="POST" onsubmit="return confirm('Waive ₱<?php echo number_format($s_outstanding,2); ?> penalty for <?php echo htmlspecialchars($s['first_name'] . ' ' . $s['last_name']); ?>?');">
                                        <input type="hidden" name="action" value="waive_penalty">
                                        <input type="hidden" name="waive_student_id" value="<?php echo $s['id']; ?>">
                                        <button type="submit" class="btn btn-reject" style="padding:5px 14px;font-size:13px;">🗑️ Waive</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table></div>
            <?php else: ?>
                <div class="empty-state"><p>✅ All students with penalties have submitted payment.</p></div>
            <?php endif; ?>
        </div>

        <!-- All Payments History -->
        <div class="section">
            <h3>📊 Payment History <span style="font-size:16px;background:rgba(255,215,0,0.1);border:1px solid rgba(255,215,0,0.3);color:#FFD700;padding:3px 12px;border-radius:20px;margin-left:8px;"><?php echo $stats['total_payments']; ?></span></h3>
            <?php if($all_payments->num_rows > 0): ?>
                <div class="table-scroll"><table>
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Amount</th>
                            <th>Method</th>
                            <th>Status</th>
                            <th>Payment Date</th>
                            <th>Submitted</th>
                            <th>Confirmed By</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($payment = $all_payments->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($payment['first_name'] . ' ' . $payment['last_name']); ?></td>
                            <td>₱<?php echo number_format($payment['amount'], 2); ?></td>
                            <td><?php echo htmlspecialchars($payment['payment_method']); ?></td>
                            <td>
                                <span class="status-<?php echo strtolower($payment['status']); ?>">
                                    <?php echo $payment['status']; ?>
                                </span>
                            </td>
                            <td><?php echo date('M d, Y', strtotime($payment['payment_date'])); ?></td>
                            <td><?php echo date('M d, Y H:i', strtotime($payment['created_at'])); ?></td>
                            <td>
                                <?php 
                                if($payment['confirmed_by']) {
                                    echo htmlspecialchars($payment['admin_first'] . ' ' . $payment['admin_last']);
                                } else {
                                    echo '-';
                                }
                                ?>
                            </td>
                            <td><?php echo $payment['admin_notes'] ? htmlspecialchars(substr($payment['admin_notes'], 0, 50)) . '...' : '-'; ?></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table></div>
            <?php else: ?>
                <div class="empty-state">
                    <p>📋 No payment history found.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Confirmation Modal -->
    <div id="confirmModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal()">&times;</span>
            <h3 id="modalTitle">Confirm Payment</h3>
            <form id="confirmForm" method="POST">
                <input type="hidden" name="payment_id" id="payment_id">
                <input type="hidden" name="action" id="action">
                
                <div class="form-group">
                    <label for="admin_notes">Admin Notes (Optional)</label>
                    <textarea name="admin_notes" id="admin_notes" placeholder="Add any notes about this payment..."></textarea>
                </div>
                
                <div style="text-align: center; margin-top: 20px;">
                    <button type="submit" class="btn btn-confirm" id="submitBtn">Confirm</button>
                    <button type="button" class="btn btn-reject" onclick="closeModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Cash Payment Modal -->
    <div id="cashModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeCashModal()">&times;</span>
            <h3>💵 Accept Cash Payment</h3>
            <form id="cashForm" method="POST">
                <input type="hidden" name="action" value="accept_cash">
                <input type="hidden" name="cash_student_id" id="cash_student_id">
                
                <div class="form-group">
                    <label>Student</label>
                    <div id="cash_student_name" style="color: white; font-weight: bold;"></div>
                </div>
                
                <div class="form-group">
                    <label for="cash_amount">Amount (₱)</label>
                    <input type="number" name="cash_amount" id="cash_amount" step="0.01" min="0.01" required
                           style="width:100%; padding:12px; border:2px solid rgba(255,215,0,0.2); border-radius:15px; font-size:16px; background:rgba(255,255,255,0.05); color:white; outline:none;">
                </div>
                
                <div class="form-group">
                    <label for="cash_date">Payment Date</label>
                    <input type="date" name="cash_date" id="cash_date" value="<?php echo date('Y-m-d'); ?>" required
                           style="width:100%; padding:12px; border:2px solid rgba(255,215,0,0.2); border-radius:15px; font-size:16px; background:rgba(255,255,255,0.05); color:white; outline:none;">
                </div>
                
                <div style="text-align: center; margin-top: 20px;">
                    <button type="submit" class="btn btn-confirm">Accept Payment</button>
                    <button type="button" class="btn btn-reject" onclick="closeCashModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

<!-- Receipt Preview Modal -->
    <div id="receiptModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeReceiptModal()">&times;</span>
            <h3>Receipt Preview</h3>
            <img id="receiptImage" style="width: 100%; border-radius: 10px;">
        </div>
    </div>

    <script>
        function acceptCashPayment(studentId, outstandingAmount, studentName) {
            document.getElementById('cash_student_id').value = studentId;
            document.getElementById('cash_student_name').textContent = studentName;
            document.getElementById('cash_amount').value = outstandingAmount;
            document.getElementById('cashModal').style.display = 'block';
        }

        function closeCashModal() {
            document.getElementById('cashModal').style.display = 'none';
            document.getElementById('cash_amount').value = '';
        }

        function confirmPayment(paymentId) {
            document.getElementById('modalTitle').textContent = 'Confirm Payment';
            document.getElementById('payment_id').value = paymentId;
            document.getElementById('action').value = 'confirm';
            document.getElementById('submitBtn').textContent = 'Confirm Payment';
            document.getElementById('submitBtn').className = 'btn btn-confirm';
            document.getElementById('confirmModal').style.display = 'block';
        }

        function rejectPayment(paymentId) {
            document.getElementById('modalTitle').textContent = 'Reject Payment';
            document.getElementById('payment_id').value = paymentId;
            document.getElementById('action').value = 'reject';
            document.getElementById('submitBtn').textContent = 'Reject Payment';
            document.getElementById('submitBtn').className = 'btn btn-reject';
            document.getElementById('confirmModal').style.display = 'block';
        }

        function closeModal() {
            document.getElementById('confirmModal').style.display = 'none';
            document.getElementById('admin_notes').value = '';
        }

        function viewReceipt(imagePath) {
            document.getElementById('receiptImage').src = imagePath;
            document.getElementById('receiptModal').style.display = 'block';
        }

        function closeReceiptModal() {
            document.getElementById('receiptModal').style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const confirmModal = document.getElementById('confirmModal');
            const receiptModal = document.getElementById('receiptModal');
            const cashModal = document.getElementById('cashModal');
            
            if (event.target == confirmModal) {
                closeModal();
            }
            if (event.target == receiptModal) {
                closeReceiptModal();
            }
            if (event.target == cashModal) {
                closeCashModal();
            }
        }
    </script>
</body>
</html>
