<?php
$conn = new mysqli("localhost","root","","attendance");

$municipality = $_GET['municipality'];

$sql = "SELECT name
FROM psgc
WHERE geographic_level='Bgy'
AND psgc_code LIKE CONCAT(SUBSTRING('$municipality',1,6),'%')
ORDER BY name";

$result = $conn->query($sql);

echo "<option value=''>Select Barangay</option>";

while($row = $result->fetch_assoc()){
echo "<option value='".$row['name']."'>".$row['name']."</option>";
}
?>