<?php
$conn = new mysqli("localhost","root","","attendance");

$error = "";
$success = "";

/* CHANGE THIS SECRET CODE */
$admin_secret = "CICS2026";

if($_SERVER["REQUEST_METHOD"] == "POST"){

    if($_POST['secret_key'] !== $admin_secret){
        $error = "Invalid Admin Secret Key!";
    } else {

        $username = $_POST['username'];

        $check = $conn->query("SELECT id FROM users WHERE username='$username'");
        if($check->num_rows > 0){
            $error = "Username already exists!";
        } else {

            if($_POST['password'] !== $_POST['confirm_password']){
                $error = "Passwords do not match!";
            } else {

                $first_name = $_POST['first_name'];
                $last_name = $_POST['last_name'];
                $password = password_hash($_POST['password'], PASSWORD_DEFAULT);

                $sql = "INSERT INTO users 
                        (username, first_name, last_name, password, role) 
                        VALUES 
                        ('$username','$first_name','$last_name','$password','admin')";

                if($conn->query($sql)){
                    $success = "Admin Registered Successfully!";
                } else {
                    $error = "Error: " . $conn->error;
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<title>Admin Registration</title>
<style>
body{
    background:#0b3c70 url('falcon.png') no-repeat center center;
    background-size: cover;
    font-family: Arial, sans-serif;
}

.container{
    width:400px;
    margin:80px auto;
    background:yellow;
    padding:30px;
    border-radius:10px;
    box-shadow:0 0 10px rgba(0,0,0,0.1);
}

input{
    width:100%;
    padding:10px;
    margin:10px 0;
}

button{
    width:100%;
    padding:10px;
    background:#8b0000;
    color:white;
    border:none;
    font-weight:bold;
}
.error{color:blue;}
.success{color:green;}
</style>
</head>
<body>

<div class="container">
<h2>Admin Registration</h2>

<?php if($error) echo "<div class='error'>$error</div>"; ?>
<?php if($success) echo "<div class='success'>$success</div>"; ?>

<form method="POST">

<input type="text" name="first_name" placeholder="First Name" required>
<input type="text" name="last_name" placeholder="Last Name" required>
<input type="text" name="username" placeholder="Username" required>

<input type="password" name="password" placeholder="Password" required>
<input type="password" name="confirm_password" placeholder="Confirm Password" required>

<input type="text" name="secret_key" placeholder="Admin Secret Key" required>

<button type="submit">Register Admin</button>

</form>
</div>

</body>
</html>
