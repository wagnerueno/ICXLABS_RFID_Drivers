<?php

$JSON_FILE = "/usr/share/rfid/tags.json";

require("rfid_chafon.class.php");
$outputFile = $JSON_FILE;
$rfid = new rfid();
$rfid->cb_CMD_INVENTORY = "inventory"; //associa callback que é chamada a cada comando de leitura
$rfid->cb_relevantTags = "relevant"; //associa callback que é chamada a cada alteração nos RFIDs presentes
$rfid->getReaderSerialNumber();
$rfid->setBeep(0);
$rfid->setScanTime(3);//min 3=300ms, max 255=25.5s
$rfid->setPower(26);//max 30dBm
$rfid->setRegion('US');
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