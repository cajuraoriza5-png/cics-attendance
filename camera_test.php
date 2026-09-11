<!DOCTYPE html>
<html>
<head>
<title>Camera Test</title>
<style>
body{
background:#111;
display:flex;
justify-content:center;
align-items:center;
height:100vh;
margin:0;
}
video{
width:700px;
border:5px solid lime;
border-radius:20px;
}
</style>
</head>
<body>

<video id="video" autoplay playsinline></video>

<script>
navigator.mediaDevices.getUserMedia({
video:true,
audio:false
})
.then(function(stream){
document.getElementById("video").srcObject = stream;
})
.catch(function(err){
alert("Camera Error: " + err);
});
</script>

</body>
</html>