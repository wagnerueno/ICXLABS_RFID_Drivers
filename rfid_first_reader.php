<?php

//Usage: systemctl start rfid-reader.service

$JSON_FILE = "/usr/share/rfid/tags.json";

require("rfid_first.class.php");
$outputFile = $JSON_FILE;
$rfid = new rfid_fi();
$rfid->cb_CMD_INVENTORY = "inventory"; //associa callback que é chamada a cada comando de leitura
$rfid->cb_relevantTags = "relevant"; //associa callback que é chamada a cada alteração nos RFIDs presentes
$rfid->getReaderInfo(0xff);
$rfid->setGPIO(3);
$rfid->setBeep(0);
$rfid->setPower(33);//max 33dBm
$rfid->pertinenceTime=10; //define o tempo de pertinencia da etiqueta. Ela é removida do array quando expirar esse tempo
while (true){
    $rfid->inventory();
    $rfid->poolRX();
    sleep(.3);
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