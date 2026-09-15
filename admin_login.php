<?php
session_start();

$conn = new mysqli("localhost","root","","attendance");

if($conn->connect_error){
    die("Database Connection Failed!");
}

$error = "";

if($_SERVER["REQUEST_METHOD"] == "POST"){

    $username = trim($_POST['username']);
    $password = $_POST['password'];

    $stmt = $conn->prepare("
        SELECT *
        FROM admin
        WHERE username=?
        LIMIT 1
    ");

    $stmt->bind_param("s",$username);
    $stmt->execute();

    $result = $stmt->get_result();

    if($result->num_rows > 0){

        $admin = $result->fetch_assoc();

        if(password_verify($password,$admin['password'])){

            unset($_SESSION['student_id'], $_SESSION['student_name']);
            $_SESSION['admin_id']   = $admin['id'];
            $_SESSION['admin_name'] = $admin['full_name'];
            $_SESSION['role']       = $admin['role'];

            header("Location: admin_dashboard.php");
            exit;

        }else{
            $error = "Incorrect password!";
        }

    }else{
        $error = "Admin account not found!";
    }

}
?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport"
content="width=device-width, initial-scale=1.0">

<title>Admin Login</title>

<style>

*{
margin:0;
padding:0;
box-sizing:border-box;
}

body{
font-family:'Segoe UI',sans-serif;
min-height:100vh;
display:flex;
justify-content:center;
align-items:center;
padding:20px;
background:#020617;
position:relative;
overflow:hidden;
}

/* LARGE FALCON BACKGROUND */

body::before{
content:'';
position:fixed;
inset:0;

background:
linear-gradient(
rgba(0,0,0,.18),
rgba(0,0,0,.18)
),

url('https://static.vecteezy.com/system/resources/previews/008/440/615/original/falcon-mascot-logo-free-vector.jpg')
no-repeat center center;

background-size:950px;

opacity:.38;

z-index:-1;

filter:
brightness(1.4)
contrast(1.15)
blur(1px);

transform:scale(1.15);

}

/* CONTAINER */

.container{
width:720px;
max-width:100%;
padding:42px;
border-radius:24px;

/* GLASS EFFECT */

background:
linear-gradient(
145deg,
rgba(255,255,255,.05),
rgba(255,248,216,.04),
rgba(255,242,168,.03)
);

backdrop-filter:blur(5px);
-webkit-backdrop-filter:blur(5px);

border:1px solid rgba(255,255,255,.12);

box-shadow:
0 20px 55px rgba(0,0,0,.35),
0 0 18px rgba(255,215,0,.04);

animation:fadeUp .7s ease;
}

/* HEADER */

.page-header{
display:flex;
align-items:center;
justify-content:center;
gap:18px;
margin-bottom:28px;
}

.logo{
width:78px;
height:78px;
object-fit:contain;
}

.title{
font-size:24px;
font-weight:900;
color:white;
line-height:1.3;
text-transform:uppercase;
text-shadow:0 2px 10px rgba(0,0,0,.4);
}

h2{
text-align:center;
font-size:34px;
color:white;
margin-bottom:25px;
text-shadow:0 2px 10px rgba(0,0,0,.4);
}

/* ERROR */

.error{
background:rgba(255,0,0,.15);
border:1px solid rgba(255,0,0,.25);
color:#ffb3b3;
padding:13px;
border-radius:12px;
margin-bottom:18px;
text-align:center;
font-weight:700;
backdrop-filter:blur(8px);
}

/* FORM */

.group{
margin-bottom:18px;
}

.group label{
display:block;
font-weight:800;
color:white;
margin-bottom:8px;
text-shadow:0 2px 8px rgba(0,0,0,.35);
}

/* INPUT */

input{
width:100%;
padding:15px 16px;
border:none;
border-radius:14px;
font-size:16px;

background:rgba(255,255,255,.10);

color:white;

backdrop-filter:blur(4px);

box-shadow:0 4px 12px rgba(0,0,0,.10);

outline:none;
transition:.25s;

border:1px solid rgba(255,255,255,.08);
}

input::placeholder{
color:rgba(255,255,255,.75);
}

input:focus{
transform:scale(1.01);

box-shadow:
0 0 0 3px rgba(255,215,0,.45);
}

/* PASSWORD */

.password-wrap{
position:relative;
}

.password-wrap span{
position:absolute;
right:14px;
top:50%;
transform:translateY(-50%);
cursor:pointer;
font-size:18px;
}

/* LINKS */

.links{
display:flex;
justify-content:flex-end;
margin:5px 0 18px;
}

.links a{
text-decoration:none;
color:#FFD700;
font-weight:700;
font-size:14px;
}

.links a:hover{
text-decoration:underline;
}

/* BUTTONS */

.btn{
width:100%;
padding:15px;
border:none;
border-radius:14px;
font-size:18px;
font-weight:900;
cursor:pointer;
transition:.25s;
}

.login-btn{
background:
linear-gradient(
135deg,
#FFD700,
#FFC107
);

color:#111;

box-shadow:
0 8px 18px rgba(255,193,7,.35);
}

.login-btn:hover{
transform:translateY(-2px);

box-shadow:
0 12px 22px rgba(255,193,7,.55);
}

.back-btn{
margin-top:15px;
background:rgba(0,0,0,.75);
color:#fff;
border:1px solid rgba(255,255,255,.12);
backdrop-filter:blur(10px);
}

.back-btn:hover{
background:rgba(0,0,0,.9);
transform:translateY(-2px);
}

/* FOOTER */

.footer{
margin-top:18px;
text-align:center;
font-size:14px;
color:rgba(255,255,255,.85);
font-weight:600;
text-shadow:0 2px 8px rgba(0,0,0,.3);
}

/* ANIMATION */

@keyframes fadeUp{

from{
opacity:0;
transform:translateY(20px);
}

to{
opacity:1;
transform:translateY(0);
}

}

/* TABLET */

@media(max-width:768px){

.container{
padding:28px;
}

.page-header{
flex-direction:column;
text-align:center;
gap:10px;
}

.title{
font-size:18px;
}

h2{
font-size:28px;
}

.logo{
width:65px;
height:65px;
}

body::before{
background-size:1000px;
}

}

/* MOBILE */

@media(max-width:480px){

body{
padding:12px;
}

.container{
padding:22px 18px;
border-radius:18px;
}

.title{
font-size:15px;
}

h2{
font-size:24px;
}

.logo{
width:55px;
height:55px;
}

input{
padding:13px 14px;
font-size:15px;
}

.btn{
font-size:16px;
padding:13px;
}

body::before{
background-size:700px;
opacity:.28;
}

}

</style>

<script>

function togglePassword(){

let field =
document.getElementById(
"password"
);

field.type =
field.type === "password"
? "text"
: "password";

}

</script>

</head>

<body>

<div class="container">

<!-- HEADER -->

<div class="page-header">

<img
src="assets/images/cics_logo.png"
class="logo"

onerror="
this.src='https://placehold.co/80x80?text=CICS';
">

<div class="title">

College of Information and Computing Studies

</div>

</div>

<!-- TITLE -->

<h2>

Admin Login

</h2>

<!-- ERROR -->

<?php if($error!=""){ ?>

<div class="error">

<?php echo $error; ?>

</div>

<?php } ?>

<!-- FORM -->

<form method="POST">

<div class="group">

<label>

👤 Username

</label>

<input
type="text"
name="username"
placeholder="Enter Username"
required>

</div>

<div class="group">

<label>

🔒 Password

</label>

<div class="password-wrap">

<input
type="password"
name="password"
id="password"
placeholder="Enter Password"
required>

<span
onclick="togglePassword()">

👁️

</span>

</div>

</div>

<div class="links">

<a href="#">

Forgot Password?

</a>

</div>

<button
type="submit"
class="btn login-btn">

Login Now

</button>

<button
type="button"
onclick="window.location='index.php'"
class="btn back-btn">

⬅ Back

</button>

</form>

<!-- FOOTER -->

<div class="footer">

Face Attendance and Penalty Monitoring System

</div>

</div>

</body>
</html>