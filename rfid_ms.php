<?php
$JSON_FILE = "/usr/share/rfid/tags.json";
$uri = $_SERVER["REQUEST_URI"];
if ($uri=="/tags"){
    echo file_get_contents($JSON_FILE);
    header($_SERVER['SERVER_PROTOCOL'].' 200 OK');
    die();
}
header($_SERVER['SERVER_PROTOCOL'].' 404 NOT_FOUND');
