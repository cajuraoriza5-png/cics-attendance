<?php
session_start();
$conn = new mysqli("localhost","root","","attendance");

if($conn->connect_error){
    die("Connection failed: " . $conn->connect_error);
}

// MARK AS PAID
if(isset($_GET['pay'])){
    $id = $_GET['pay'];

    // SET TOTAL PENALTY TO 0
    $conn->query("
    UPDATE users 
    SET total_penalty = 0,
        penalty_status='Paid'
    WHERE id='$id'
    ");

    header("Location: payments.php");
    exit();
}

// GET STUDENTS WITH PENALTY
$students = $conn->query("
SELECT * FROM users 
WHERE role='student' 
ORDER BY total_penalty DESC
");
?>

<!DOCTYPE html>
<html>
<head>
<title>Payments</title>
<style>
body{font-family:'Segoe UI';background:#eef2f7;}
.container{
    width:900px;margin:30px auto;
    background:white;padding:20px;border-radius:15px;
}
table{width:100%;border-collapse:collapse;}
th,td{padding:10px;border-bottom:1px solid #ddd;text-align:center;}
th{background:#2a5298;color:white;}
.btn{
    padding:6px 10px;
    background:#28a745;
    color:white;
    border:none;
    border-radius:5px;
    cursor:pointer;
}
.unpaid{color:red;font-weight:bold;}
.paid{color:green;font-weight:bold;}
</style>
</head>

<body>

<div class="container">
<h2>💳 Payment Management</h2>

<table>
<tr>
<th>ID</th>
<th>Name</th>
<th>Total Penalty</th>
<th>Status</th>
<th>Action</th>
</tr>

<?php while($s = $students->fetch_assoc()){ ?>
<tr>
<td><?php echo $s['id']; ?></td>
<td><?php echo $s['first_name']." ".$s['last_name']; ?></td>
<td>₱<?php echo number_format($s['total_penalty'],2); ?></td>

<td class="<?php echo $s['penalty_status']=='Paid'?'paid':'unpaid'; ?>">
<?php echo $s['penalty_status']; ?>
</td>

<td>
<?php if($s['total_penalty'] > 0){ ?>
<a href="?pay=<?php echo $s['id']; ?>">
<button class="btn">Mark as Paid</button>
</a>
<?php } else { echo "-"; } ?>
</td>

</tr>
<?php } ?>

</table>
</div>

</body>
</html>