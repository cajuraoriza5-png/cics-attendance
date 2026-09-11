<?php
$conn = new mysqli("localhost","root","","attendance");

$province = $_GET['province'];

$sql = "SELECT psgc_code,name
FROM psgc
WHERE geographic_level IN ('City','Mun')
AND psgc_code LIKE CONCAT(SUBSTRING('$province',1,4),'%')
ORDER BY name";

$result = $conn->query($sql);

echo "<option value=''>Select Municipality</option>";

while($row = $result->fetch_assoc()){
echo "<option value='".$row['psgc_code']."' data-name='".$row['name']."'>".$row['name']."</option>";
}
?>