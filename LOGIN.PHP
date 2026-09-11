<?php
session_start();
$conn = new mysqli("localhost","root","","attendance");

$error = "";

if($_SERVER["REQUEST_METHOD"] == "POST"){

    $username = $_POST['username'];
    $password = $_POST['password'];

    $sql = "SELECT * FROM users WHERE username='$username'";
    $result = $conn->query($sql);

    if($result && $result->num_rows > 0){

        $user = $result->fetch_assoc();

        if(password_verify($password, $user['password'])){

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
			$_SESSION['first_name'] = $user['first_name'];
            $_SESSION['role'] = $user['role'];

            if($user['role'] == 'admin'){
                header("Location: admin_dashboard.php");
            } else {
                header("Location: user_dashboard.php");
            }
            exit;

        } else {
            $error = "Incorrect password!";
        }

    } else {
        $error = "Username not found!";
    }
}
?>


<!DOCTYPE html>
<html>
<head>
<title>Welcome to NISU CICS</title>
<<style>
body{
    margin:0;
    font-family: Arial, sans-serif;
    background: url('falcon.png') no-repeat center center fixed;
    background-size: cover;
}

body::before{
    content: "";
    position: fixed;
    top:0;
    left:0;
    width:100%;
    height:100%;
    background: rgba(0,0,0,0.6);
    z-index:-1;
}

.container{
    width:550px; /* BIGGER */
    margin:100px auto;
    background:white;
    padding:40px;
    border-radius:20px;
    box-shadow:0 0 30px rgba(0,0,0,0.5);
    text-align:center;
}

h1{
    color:#0b3c70;
    margin-bottom:5px;
    font-size:28px;
}

h2{
    color:#333;
    margin-bottom:25px;
    font-size:22px;
}

input{
    width:100%;
    padding:14px;
    margin:10px 0;
    border-radius:10px;
    border:1px solid #ccc;
    font-size:16px;
}

button{
    width:100%;
    padding:14px;
    background:#ffd400;
    border:none;
    font-weight:bold;
    font-size:16px;
    cursor:pointer;
    border-radius:10px;
}

button:hover{
    background:#ffca28;
}

.logo-row{
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:20px;
}

.logo-row img{
    width:90px;
}

.error{
    color:red;
    margin-bottom:10px;
    font-weight:bold;
}

.register-link{
    margin-top:20px;
    font-size:15px;
}
</style>

</style>
</head>
<body>

<div class="container">

    <div class="logo-row">
    <img src="nisu_logo.png" alt="NISU Logo" class="logo">
    <div class="title">
        Log In <br>
        <small>CICS Events Attendance Tracking</small>
    </div>
    <img src="cics_logo.png" alt="CICS Logo" class="logo">
</div>



    <?php if(isset($error)){ echo "<div class='error'>$error</div>"; } ?>

    <form method="POST">
       <input type="text" name="username" placeholder="Username" required>
		
        <div style="position:relative;">
    <input type="password" name="password" id="password" placeholder="Password" required>
    <span onclick="togglePassword()" 
          style="position:absolute; right:15px; top:50%; transform:translateY(-50%); cursor:pointer; font-size:18px;">
        👁
    </span>
</div>


        <button type="submit">Log In</button>
    </form>

    <div class="register-link">
        No account yet? <a href="register.php">Register</a>
    </div>

</div>

</body>
</html>
