<?php

$municipality = $_GET['municipality'];

$url = "https://psgc.gitlab.io/api/cities-municipalities/";

$data = json_decode(file_get_contents($url), true);

foreach ($data as $city) {

    if(strtolower($city['name']) == strtolower($municipality)){
        echo $city['zipCode'];
        exit;
    }

}

echo "";

?>