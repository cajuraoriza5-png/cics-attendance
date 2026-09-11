<?php
date_default_timezone_set('Asia/Manila');
session_start();

if(!isset($_SESSION['admin_id'])){
header("Location: admin_login.php");
exit;
}

$conn = new mysqli(
"localhost",
"root",
"",
"attendance"
);

if($conn->connect_error){
die("Connection failed: ".$conn->connect_error);
}

/* DELETE EVENT */

if(isset($_GET['delete'])){

$id = intval($_GET['delete']);

$conn->query("
DELETE FROM events
WHERE id='$id'
");

header("Location:event_history.php");
exit;

}

/* EVENTS */

$events = $conn->query("
SELECT *
FROM events
ORDER BY created_at DESC
");

?>

<!DOCTYPE html>
<html>

<head>

<title>Event History</title>

<meta charset="UTF-8">
<meta name="viewport"
content="width=device-width, initial-scale=1.0">

<style>

*{
margin:0;
padding:0;
box-sizing:border-box;
font-family:'Segoe UI';
}

body{
background:
linear-gradient(
135deg,
rgba(0,0,0,.95),
rgba(0,0,0,.85)
);
color:white;
min-height:100vh;
padding:30px;
}

.container{
max-width:1400px;
margin:auto;
}

.card{
background:rgba(0,0,0,.8);
border:2px solid rgba(255,215,0,.3);
border-radius:25px;
padding:30px;
backdrop-filter:blur(20px);
}

.title{
font-size:32px;
font-weight:900;
margin-bottom:30px;
background:linear-gradient(
45deg,
#FFD700,
#FFA500
);
-webkit-background-clip:text;
-webkit-text-fill-color:transparent;
}

.table-scroll{
overflow-x:auto;
}

table{
width:100%;
border-collapse:collapse;
min-width:900px;
}

th{
background:rgba(255,215,0,.1);
padding:15px;
text-align:left;
color:#FFD700;
border:1px solid rgba(255,215,0,.2);
}

td{
padding:15px;
border:1px solid rgba(255,215,0,.08);
}

tr:hover{
background:rgba(255,215,0,.05);
}

.action-btn{
padding:8px 14px;
border-radius:12px;
text-decoration:none;
font-size:13px;
font-weight:700;
display:inline-block;
margin-right:8px;
}

.edit-btn{
background:#FFD700;
color:black;
}

.delete-btn{
background:#dc3545;
color:white;
}

.back-btn{
display:inline-block;
margin-top:20px;
padding:12px 20px;
border-radius:15px;
background:rgba(255,255,255,.08);
color:white;
text-decoration:none;
font-weight:700;
}

@media(max-width:768px){

body{
padding:15px;
}

.card{
padding:20px;
}

.title{
font-size:24px;
}

}

</style>

</head>

<body>

<div class="container">

<div class="card">

<h1 class="title">

🕘 Event History

</h1>

<div class="table-scroll">

<table>

<thead>

<tr>

<th>Event</th>
<th>Type</th>
<th>Venue</th>
<th>Start Date</th>
<th>End Date</th>
<th>Actions</th>

</tr>

</thead>

<tbody>

<?php while($row =
$events->fetch_assoc()){ ?>

<tr>

<td>

<?php
echo htmlspecialchars(
$row['event_name']
);
?>

</td>

<td>

<?php
echo htmlspecialchars(
$row['event_type']
);
?>

</td>

<td>

<?php
echo htmlspecialchars(
$row['venue']
);
?>

</td>

<td>

<?php
echo date(
'M d, Y',
strtotime($row['start_date'])
);
?>

</td>

<td>

<?php
echo date(
'M d, Y',
strtotime($row['end_date'])
);
?>

</td>

<td>

<a
href="edit_event.php?id=<?php echo $row['id']; ?>"
class="action-btn edit-btn">

✏ Edit

</a>

<a
href="event_history.php?delete=<?php echo $row['id']; ?>"
onclick="return confirm('Delete this event?')"
class="action-btn delete-btn">

🗑 Delete

</a>

</td>

</tr>

<?php } ?>

</tbody>

</table>

</div>

<a
href="admin_dashboard.php"
class="back-btn">

← Back to Dashboard

</a>

</div>

</div>

</body>
</html>