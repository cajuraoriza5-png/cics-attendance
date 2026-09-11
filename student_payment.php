<?php
date_default_timezone_set('Asia/Manila');
session_start();
include("db.php");

// Check if student is logged in
if(!isset($_SESSION['student_id'])){
    header("Location: student_login.php");
    exit();
}

$conn = new mysqli("localhost","root","","attendance");

if($conn->connect_error){
    die("Connection failed: " . $conn->connect_error);
}

$student_id = $_SESSION['student_id'];
$error = "";
$success = "";

// Get student information and current penalties (live calc from attendance - confirmed payments)
$student = $conn->query("SELECT first_name, last_name FROM users WHERE id='$student_id'")->fetch_assoc();

// Get GCash number from admin
$admin = $conn->query("SELECT gcash_number FROM admin LIMIT 1")->fetch_assoc();
$gcash_number = $admin['gcash_number'] ?? '';
$accruedQ = $conn->query("
    SELECT COALESCE(SUM(
        CASE
            WHEN e.event_type = 'Morning Only' THEN
                CASE WHEN a.morning_status   = 'Late'   THEN e.late_penalty
                     WHEN a.morning_status   = 'Absent' THEN e.absent_penalty
                     ELSE 0 END
            WHEN e.event_type = 'Afternoon Only' THEN
                CASE WHEN a.afternoon_status = 'Late'   THEN e.late_penalty
                     WHEN a.afternoon_status = 'Absent' THEN e.absent_penalty
                     ELSE 0 END
            ELSE
                CASE
                    WHEN a.morning_status = 'Present' OR a.afternoon_status = 'Present' THEN 0
                    WHEN a.morning_status = 'Late'    OR a.afternoon_status = 'Late'    THEN e.late_penalty
                    WHEN a.morning_status = 'Absent'  OR a.afternoon_status = 'Absent'  THEN e.absent_penalty
                    ELSE 0
                END
        END
    ), 0) as p
    FROM attendance a
    JOIN events e ON a.event_id = e.id
    WHERE a.student_id='$student_id'
")->fetch_assoc();
$paidQ    = $conn->query("SELECT COALESCE(SUM(amount),0) as p FROM payments WHERE student_id='$student_id' AND status='Confirmed'")->fetch_assoc();
$livePenalty = max(0.0, floatval($accruedQ['p'] ?? 0) - floatval($paidQ['p'] ?? 0));

// Get payment history
$payment_history = $conn->query("
    SELECT p.*, u.first_name, u.last_name 
    FROM payments p 
    JOIN users u ON p.confirmed_by = u.id 
    WHERE p.student_id = '$student_id'
    ORDER BY p.created_at DESC
");

// Handle payment submission
if($_SERVER["REQUEST_METHOD"] == "POST"){
    
    $amount = floatval($_POST['amount']);
    $payment_method = $_POST['payment_method'];
    $gcash_reference = trim($_POST['gcash_reference'] ?? '');
    $payment_date = $_POST['payment_date'];
    
    // Validation
    if($amount <= 0){
        $error = "Amount must be greater than 0.";
    }
    elseif($amount > $livePenalty){
        $error = "Amount cannot exceed total penalty of ₱" . number_format($livePenalty, 2);
    }
    elseif($payment_method == 'GCash' && empty($gcash_reference)){
        $error = "GCash reference number is required.";
    }
    elseif($payment_method == 'GCash' && !preg_match("/^[0-9]{10,13}$/", $gcash_reference)){
        $error = "Invalid GCash reference number format.";
    }
    elseif(empty($payment_date)){
        $error = "Payment date is required.";
    }
    else{
        // Handle receipt upload
        $receipt_image = null;
        if(isset($_FILES['receipt_image']) && $_FILES['receipt_image']['error'] == UPLOAD_ERR_OK){
            
            $allowed_types = ['image/jpeg', 'image/png', 'image/jpg'];
            $file_info = getimagesize($_FILES['receipt_image']['tmp_name']);
            
            if($file_info === false || !in_array($file_info['mime'], $allowed_types)){
                $error = "Invalid receipt image format. Only JPG, JPEG, and PNG are allowed.";
            }
            elseif($_FILES['receipt_image']['size'] > 5 * 1024 * 1024){ // 5MB limit
                $error = "Receipt image size must be less than 5MB.";
            }
            else{
                // Create receipts directory if it doesn't exist
                if(!is_dir('receipts')){
                    mkdir('receipts', 0755, true);
                }
                
                // Generate unique filename
                $filename = 'receipt_' . $student_id . '_' . time() . '.jpg';
                $filepath = 'receipts/' . $filename;
                
                if(move_uploaded_file($_FILES['receipt_image']['tmp_name'], $filepath)){
                    $receipt_image = $filename;
                } else {
                    $error = "Failed to upload receipt image.";
                }
            }
        }
        
        if(empty($error)){
            // Insert payment record
            $stmt = $conn->prepare("
                INSERT INTO payments(student_id, amount, payment_method, gcash_reference, receipt_image, payment_date, status)
                VALUES(?, ?, ?, ?, ?, ?, 'Pending')
            ");
            
            $stmt->bind_param("idssss", $student_id, $amount, $payment_method, $gcash_reference, $receipt_image, $payment_date);
            
            if($stmt->execute()){
                $success = "Payment submitted successfully! Please wait for admin confirmation.";
            } else {
                $error = "Failed to submit payment. Please try again.";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Payment Upload - CICS Attendance System</title>
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
            max-width: 800px;
            margin: 40px auto;
            padding: 0 20px;
        }

        .penalty-card {
            background: rgba(0,0,0,0.8);
            backdrop-filter: blur(20px);
            border: 2px solid rgba(255,215,0,0.3);
            border-radius: 25px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.5);
        }

        .penalty-amount {
            font-size: 48px;
            font-weight: 900;
            background: linear-gradient(45deg, #ff6b6b, #dc3545);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            text-align: center;
            margin: 20px 0;
        }

        .payment-form {
            background: rgba(0,0,0,0.8);
            backdrop-filter: blur(20px);
            border: 2px solid rgba(255,215,0,0.3);
            border-radius: 25px;
            padding: 30px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.5);
        }

        .form-group {
            margin-bottom: 25px;
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

        .form-group select option {
            background: #1a1a1a;
            color: white;
        }

        .file-upload {
            position: relative;
            display: inline-block;
            width: 100%;
        }

        .file-upload input[type="file"] {
            position: absolute;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }

        .file-upload-label {
            display: block;
            padding: 15px;
            border: 2px dashed rgba(255,215,0,0.4);
            border-radius: 15px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            background: rgba(255,255,255,0.05);
        }

        .file-upload-label:hover {
            border-color: rgba(255,215,0,0.8);
            background: rgba(255,215,0,0.1);
        }

        .btn {
            display: inline-block;
            padding: 15px 30px;
            border: none;
            border-radius: 25px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            text-decoration: none;
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
            margin-left: 10px;
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
        }

        .success {
            background: rgba(40,167,69,0.2);
            border: 1px solid rgba(40,167,69,0.5);
            color: #51cf66;
            padding: 15px;
            border-radius: 15px;
            margin-bottom: 20px;
            font-weight: 600;
        }

        .history-table {
            background: rgba(0,0,0,0.8);
            backdrop-filter: blur(20px);
            border: 2px solid rgba(255,215,0,0.3);
            border-radius: 25px;
            padding: 30px;
            margin-top: 30px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.5);
        }

        .history-table h3 {
            font-size: 24px;
            margin-bottom: 20px;
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

        /* TABLE SCROLL */
        .table-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .history-table table { min-width: 520px; }

        @media(max-width:768px){
            .back-link { position:static; display:inline-block; margin:15px 0 0 15px; }
            .header { padding:15px 20px; flex-direction:column; align-items:flex-start; gap:10px; }
            .header h2 { font-size:22px; }
            .logout { align-self:flex-end; padding:10px 18px; }
            .container { padding:0 15px; margin:20px auto; }
            .penalty-card, .payment-form, .history-table { padding:20px; border-radius:18px; }
            .penalty-amount { font-size:36px; }
            .history-table { overflow-x: auto; }
            .form-group input, .form-group select { padding:12px; font-size:15px; }
            .btn { padding:13px 20px; font-size:14px; }
        }

        @media(max-width:480px){
            .penalty-amount { font-size:28px; }
            .header h2 { font-size:18px; }
            .penalty-card, .payment-form, .history-table { padding:15px; }
        }
    </style>
</head>
<body>
    <a href="student_dashboard.php" class="back-link">← Back to Dashboard</a>
    
    <div class="header">
        <h2>💳 Payment Upload</h2>
        <a href="logout.php" class="logout">Logout</a>
    </div>

    <div class="container">
        <?php if($error): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>

        <?php if($success): ?>
            <div class="success"><?php echo $success; ?></div>
        <?php endif; ?>

        <div class="penalty-card">
            <h3>Current Penalty Status</h3>
            <p>Hello, <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></p>
            <div class="penalty-amount">₱<?php echo number_format($livePenalty, 2); ?></div>
            <p style="text-align: center; color: rgba(255,255,255,0.7);">
                <?php if($livePenalty > 0): ?>
                    Please submit your payment below to clear your penalties.
                <?php else: ?>
                    Great! You have no outstanding penalties.
                <?php endif; ?>
            </p>
        </div>

        <?php if($livePenalty > 0): ?>
        <div class="payment-form">
            <h3 style="margin-bottom: 25px; background: linear-gradient(45deg, #FFD700, #FFA500); -webkit-background-clip: text; -webkit-text-fill-color: transparent; font-weight: 800;">Submit Payment</h3>
            
            <form method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="amount">Payment Amount (₱)</label>
                    <input type="number" name="amount" id="amount" step="0.01" min="0.01" max="<?php echo $livePenalty; ?>" value="<?php echo $livePenalty; ?>" required>
                </div>

                <div class="form-group">
                    <label for="payment_method">Payment Method</label>
                    <select name="payment_method" id="payment_method" required onchange="toggleGCashField()">
                        <option value="">Select Payment Method</option>
                        <option value="GCash">GCash</option>
                        <option value="Cash">Cash</option>
                    </select>
                </div>

                <?php if($gcash_number): ?>
                <div class="form-group" style="background: rgba(255,215,0,0.1); padding: 15px; border-radius: 15px; border: 1px solid rgba(255,215,0,0.3);">
                    <label style="color: #FFD700; margin-bottom: 5px;">GCash Number for Payment:</label>
                    <div style="font-size: 24px; font-weight: bold; color: white; letter-spacing: 2px;">
                        <?php echo htmlspecialchars($gcash_number); ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="form-group" id="gcash_field" style="display: none;">
                    <label for="gcash_reference">GCash Reference Number</label>
                    <input type="text" name="gcash_reference" id="gcash_reference" placeholder="Enter 10-13 digit reference number">
                </div>

                <div class="form-group">
                    <label for="payment_date">Payment Date</label>
                    <input type="date" name="payment_date" id="payment_date" value="<?php echo date('Y-m-d'); ?>" required>
                </div>

                <div class="form-group">
                    <label>Upload Receipt (Optional)</label>
                    <div class="file-upload">
                        <input type="file" name="receipt_image" id="receipt_image" accept="image/jpeg,image/jpg,image/png">
                        <label for="receipt_image" class="file-upload-label">
                            📷 Click to upload receipt image (JPG, PNG - Max 5MB)
                        </label>
                    </div>
                </div>

                <div style="text-align: center; margin-top: 30px;">
                    <button type="submit" class="btn btn-primary">Submit Payment</button>
                    <a href="student_dashboard.php" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <div class="history-table">
            <h3>Payment History</h3>
            <?php if($payment_history->num_rows > 0): ?>
                <div class="table-scroll"><table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Amount</th>
                            <th>Method</th>
                            <th>Reference</th>
                            <th>Status</th>
                            <th>Confirmed By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($payment = $payment_history->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo date('M d, Y', strtotime($payment['created_at'])); ?></td>
                            <td>₱<?php echo number_format($payment['amount'], 2); ?></td>
                            <td><?php echo htmlspecialchars($payment['payment_method']); ?></td>
                            <td><?php echo $payment['gcash_reference'] ? htmlspecialchars($payment['gcash_reference']) : '-'; ?></td>
                            <td>
                                <span class="status-<?php echo strtolower($payment['status']); ?>">
                                    <?php echo $payment['status']; ?>
                                </span>
                            </td>
                            <td>
                                <?php 
                                if($payment['confirmed_by']) {
                                    echo htmlspecialchars($payment['first_name'] . ' ' . $payment['last_name']);
                                } else {
                                    echo '-';
                                }
                                ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table></div>
            <?php else: ?>
                <p style="text-align: center; color: rgba(255,255,255,0.6);">No payment history found.</p>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function toggleGCashField() {
            const paymentMethod = document.getElementById('payment_method').value;
            const gcashField = document.getElementById('gcash_field');
            
            if(paymentMethod === 'GCash') {
                gcashField.style.display = 'block';
                document.getElementById('gcash_reference').required = true;
            } else {
                gcashField.style.display = 'none';
                document.getElementById('gcash_reference').required = false;
                document.getElementById('gcash_reference').value = '';
            }
        }

        // Update file upload label when file is selected
        document.getElementById('receipt_image').addEventListener('change', function(e) {
            const fileName = e.target.files[0]?.name || '';
            const label = document.querySelector('.file-upload-label');
            if(fileName) {
                label.textContent = '📄 ' + fileName;
            } else {
                label.textContent = '📷 Click to upload receipt image (JPG, PNG - Max 5MB)';
            }
        });
    </script>
</body>
</html>
