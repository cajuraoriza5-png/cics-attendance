<video id="video" autoplay width="500"></video>

<script>
navigator.mediaDevices.getUserMedia({ video: true })
.then(stream => {
    document.getElementById('video').srcObject = stream;
})
.catch(err => {
    alert("ERROR: " + err.message);
});
</script>