<?php

//Usage: php rfid_reader.php /var/www/html/rfid_uhf/json.txt

require("rfid_chafon.class.php");
$outputFile = $argv[1];
$rfid = new rfid();
$rfid->cb_CMD_INVENTORY = "inventory"; //associa callback que é chamada a cada comando de leitura
$rfid->cb_relevantTags = "relevant"; //associa callback que é chamada a cada alteração nos RFIDs presentes
$rfid->getReaderSerialNumber(0xff);
$rfid->getReaderInfo(0xff);
$rfid->setGPIO(3);
$rfid->setBeep(0);
$rfid->setScanTime(3);//min 3=300ms, max 255=25.5s
$rfid->setPower(30);//max 30dBm
$rfid->pertinenceTime=10; //define o tempo de pertinencia da etiqueta. Ela é removida do array quando expirar esse tempo
while (true){
    $rfid->inventory();
    $rfid->poolRX();
    sleep(.1);
}
function inventory($rfid, $tags){
    //print_r($tags);
}
function relevant($rfid, $tags){
    writeJson($rfid->relevantTags);
}
function writeJson($tags){
    global $outputFile;
    $json = json_encode($tags);
    $jsonFile = fopen($outputFile, "wa+");
    fwrite($jsonFile, $json);
    fflush($jsonFile);
    fclose($jsonFile);       
}