<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="description" content="Face-In, Penalty-Out | CICS Attendance Monitoring System">
<title>Face-In, Penalty-Out | Student Portal</title>

<style>
*{
    margin:0;
    padding:0;
    box-sizing:border-box;
}

html{
    scroll-behavior:smooth;
}

body{
    min-height:100vh;
    font-family:'Segoe UI', Arial, sans-serif;
    display:flex;
    align-items:center;
    justify-content:center;
    padding:20px;
    background:
        linear-gradient(rgba(0,0,0,0.65), rgba(0,0,0,0.65)),
        url('https://wallpapers.com/images/hd/black-gold-background-a4qfu9d31oymp448.jpg')
        no-repeat center center/cover;
}

.container{
    width:100%;
    max-width:1250px;
    background:rgba(255,255,255,0.95);
    border-radius:25px;
    box-shadow:0 30px 70px rgba(0,0,0,0.5);
    display:flex;
    overflow:hidden;
    animation:fadeUp .8s ease;
}

/* LEFT SIDE (NOW WHITE) */
.left{
    flex:1;
    padding:70px;
    background:white;
    display:flex;
    flex-direction:column;
    justify-content:center;
    align-items:center;
    text-align:center;
}

/* RIGHT SIDE (NOW GOLD) */
.right{
    flex:1;
    padding:70px;
    background:linear-gradient(135deg,#fff7cc,#facc15);
    text-align:center;
    display:flex;
    flex-direction:column;
    justify-content:center;
    align-items:center;
}

.system-title{
    font-size:36px;
    font-weight:900;
    margin-bottom:15px;
    color:#1f2937;
    line-height:1.2;
}

.tagline{
    font-size:20px;
    color:#444;
    margin-bottom:30px;
}

.badge{
    font-size:16px;
    background:white;
    padding:12px 20px;
    border-radius:50px;
    width:fit-content;
    font-weight:600;
    box-shadow:0 5px 10px rgba(0,0,0,0.1);
}

.logo{
    width:150px;
    margin-bottom:25px;
    object-fit:contain;
}

.college{
    font-size:24px;
    font-weight:900;
    color:#1e293b;
    line-height:1.5;
}

.btn{
    display:inline-block;
    width:75%;
    padding:18px;
    margin-top:35px;
    font-size:20px;
    font-weight:900;
    text-decoration:none;
    text-align:center;
    border-radius:50px;
    background:#111827;
    color:white;
    box-shadow:0 12px 25px rgba(0,0,0,0.2);
    transition:0.25s;
}

.btn:hover{
    background:#000;
    transform:scale(1.06);
}

.btn:focus{
    outline:none;
    box-shadow:0 0 0 4px rgba(17,24,39,.25);
}

.btn-admin{
    background:linear-gradient(135deg,#FFD700,#FFC107);
    color:#111;
    margin-top:15px;
    box-shadow:0 12px 25px rgba(255,193,7,.35);
}

.btn-admin:hover{
    background:linear-gradient(135deg,#FFC107,#FFB300);
    transform:scale(1.06);
    box-shadow:0 16px 30px rgba(255,193,7,.55);
}

@keyframes fadeUp{
    from{
        opacity:0;
        transform:translateY(25px);
    }
    to{
        opacity:1;
        transform:translateY(0);
    }
}

@media(max-width:768px){
    body{
        padding:15px;
    }

    .container{
        flex-direction:column;
        max-width:95%;
    }

    .left,.right{
        padding:35px;
    }

    .system-title{
        font-size:26px;
    }

    .tagline{
        font-size:16px;
    }

    .btn{
        width:100%;
    }

    .college{
        font-size:20px;
    }

    .logo{
        width:120px;
    }
}
</style>
</head>

<body>

<div class="container">

    <!-- LEFT -->
    <div class="left">

        <img src="assets/images/cics_logo.png" class="logo"
        alt="CICS Logo"
        onerror="this.src='https://placehold.co/150x150?text=CICS';">

        <div class="college">
            🎓 COLLEGE OF <br>
            INFORMATION AND <br>
            COMPUTING STUDIES
        </div>

    </div>

    <!-- RIGHT -->
    <div class="right">

        <div class="system-title">🎯 Face Attendance and Penalty Monitoring System</div>
        <div class="tagline">📸 Smart Recognition · ⚡ Real-time Accountability</div>
        <div class="badge">💡 CICS | Advanced Attendance and Penalty Monitoring</div>

        <a href="STUDENT_LOGIN.PHP" class="btn">Student Login</a>
        <a href="ADMIN_LOGIN.PHP" class="btn btn-admin">Admin Login</a>

    </div>

</div>

</body>
</html>
