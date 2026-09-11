<?php
session_start();
if(!isset($_SESSION['student_id'])){
    header("Location: student_login.php");
    exit;
}
?>

<!DOCTYPE html>
<html>
<head>
<title>Student Dashboard</title>

<style>
*{
margin:0;
padding:0;
box-sizing:border-box;
font-family:Segoe UI;
}

body{
background:linear-gradient(135deg,#fff8dc,#ffe89a,#fffdf5);
min-height:100vh;
}

/* HEADER */
.header{
background:linear-gradient(90deg,#7a5c00,#d4af37,#ffd700);
padding:20px 40px;
display:flex;
justify-content:space-between;
align-items:center;
color:white;
box-shadow:0 8px 20px rgba(0,0,0,.15);
}

.header h2{
font-size:28px;
}

.logout{
background:white;
color:#8b6508;
padding:12px 25px;
border-radius:40px;
text-decoration:none;
font-weight:bold;
}

/* MAIN */
.container{
padding:40px;
display:grid;
grid-template-columns:repeat(auto-fit,minmax(320px,1fr));
gap:25px;
}

/* CARD */
.card{
background:white;
padding:30px;
border-radius:20px;
box-shadow:0 10px 25px rgba(0,0,0,.08);
transition:.3s;
border-top:6px solid #ffd700;
}

.card:hover{
transform:translateY(-5px);
}

.card h3{
font-size:26px;
color:#8b6508;
margin-bottom:20px;
}

.item{
font-size:22px;
margin:15px 0;
font-weight:600;
}

/* COLORS */
.green{color:#1e9c42;}
.red{color:#d62828;}
.orange{color:#ff9800;}
.blue{color:#0077cc;}

/* BUTTON */
.btn{
display:block;
width:100%;
padding:14px;
margin-top:15px;
border:none;
border-radius:40px;
font-size:18px;
font-weight:bold;
cursor:pointer;
background:linear-gradient(90deg,#ffd700,#ffbf00);
}

.btn:hover{
opacity:.9;
}

.footer{
text-align:center;
padding:20px;
font-size:16px;
color:#666;
}
</style>
</head>

<body>

<div class="header">
<h2>🎓 Welcome, <?php echo $_SESSION['student_name']; ?></h2>
<a href="logout.php" class="logout">Logout</a>
</div>

<div class="container">

<div class="card">
<h3>📊 Attendance Summary</h3>
<div class="item green">✅ Present: 0</div>
<div class="item red">❌ Absent: 0</div>
<div class="item orange">⏰ Late: 0</div>
</div>

<div class="card">
<h3>💰 Penalty</h3>
<div class="item green">✔ No Penalties</div>
<button class="btn">Pay via GCash</button>
<button class="btn">Pay via Cash</button>
</div>

<div class="card">
<h3>📅 Events</h3>
<div class="item blue">No upcoming events</div>
</div>

</div>

<div class="footer">
CICS Face-In Penalty-Out System © 2026
</div>

</body>
</html>