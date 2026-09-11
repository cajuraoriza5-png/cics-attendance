<?php
$conn = new mysqli("localhost","root","","attendance");

$status = $_GET['status'] ?? '';

$result = $conn->query("
SELECT u.student_id,u.first_name,u.last_name,a.status
FROM attendance a
JOIN users u ON u.student_id=a.student_id
WHERE a.status='$status'
ORDER BY u.last_name ASC
");
?>

<!DOCTYPE html>
<html>
<head>
<title>Attendance List</title>
<style>
body{
font-family:Arial;
padding:30px;
background:#f4f6f9;
}

table{
width:100%;
border-collapse:collapse;
}

th,td{
padding:10px;
border-bottom:1px solid #ddd;
text-align:center;
}

th{
background:#0b3c70;
color:white;
}
</style>
</head>
<body>

<h2><?php echo $status; ?> Students</h2>

<table>

<tr>
<th>Student ID</th>
<th>Name</th>
<th>Status</th>
</tr>

<?php while($row=$result->fetch_assoc()){ ?>

<tr>

<td><?php echo $row['student_id']; ?></td>

<td><?php echo $row['last_name']." ".$row['first_name']; ?></td>

<td><?php echo $row['status']; ?></td>

</tr>

<?php } ?>

</table>

</body>
</html>