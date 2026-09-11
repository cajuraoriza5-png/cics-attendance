<?php
$conn = new mysqli("localhost","root","","attendance");

$region = $_GET['region'];

$sql = "SELECT psgc_code,name
FROM psgc
WHERE geographic_level='Prov'
AND psgc_code LIKE CONCAT(SUBSTRING('$region',1,2),'%')
ORDER BY name";

$result = $conn->query($sql);

echo "<option value=''>Select Province</option>";

while($row = $result->fetch_assoc()){
echo "<option value='".$row['psgc_code']."'>".$row['name']."</option>";
}
?>