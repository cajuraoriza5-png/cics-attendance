<?php
$conn = new mysqli("localhost","root","","attendance");

$sql = "SELECT psgc_code,name 
FROM psgc 
WHERE geographic_level='Reg'
ORDER BY name";

$result = $conn->query($sql);

echo "<option value=''>Select Region</option>";

while($row = $result->fetch_assoc()){
echo "<option value='".$row['psgc_code']."'>".$row['name']."</option>";
}
?>