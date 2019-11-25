<?php
//Usage: (Server Side) php -S 0.0.0.0:81 rfid_ms.php /var/www/html/rfid_uhf/json.txt
//       (Client Side) http://localhost:81/tags
$uri = $_SERVER["REQUEST_URI"];
if ($uri=="/tags"){
    $argv = explode(' ', getenv('SUDO_COMMAND'));
    $jsonFile = $argv[4];
    echo file_get_contents($jsonFile);
    header($_SERVER['SERVER_PROTOCOL'].' 200 OK');
    die();
}
header($_SERVER['SERVER_PROTOCOL'].' 404 NOT_FOUND');
