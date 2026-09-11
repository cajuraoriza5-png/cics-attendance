<?php
$conn = new mysqli("localhost","root","","attendance");

if(isset($_GET['municipality'])){

$municipality = $_GET['municipality'];

$stmt = $conn->prepare("SELECT zipcode FROM phil_zipcodes WHERE municipality=? LIMIT 1");
$stmt->bind_param("s",$municipality);
$stmt->execute();

$result = $stmt->get_result();

if($row = $result->fetch_assoc()){
echo $row['zipcode'];
}else{
echo "";
}

}
?>