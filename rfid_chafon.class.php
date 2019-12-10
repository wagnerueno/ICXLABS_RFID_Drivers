<?php

/** 
 * v1.0 - primeira versão
 *          Relevant Tags - São tags que foram lidas com integração nos últimos 10 segundos para evitar que uma falha de leitura gere 
 *          instabilidade na percepção de quais tags estão presentes.
 * v1.1 - implementando timeout para limpar buffer
 *          o buffer de recepção serial é limpo se passar mais do que NO_DATA_TIMEOUT_SECS segundos sem dados recebidos
 *          essa função é implementada para evitar o travamento da rotina de split em caso de perda de alguns bytes na mensagem
 *          isso é nescessário pois o protocolo Chafon não possui uma informação de sincronismo (início de mensagem)
 *          caso a perda de um byte cause o desalinhamento do buffer a rotina split parará de funcionar em algum momento aguardando mais informações
 *          que estarão sempre desalinhadas. Uma pausa de 1 ou 2 segundo indicará ao algorítmo que os próximos dados recebidos representam um início de mensagem
*/
require_once("rfid_crc16.class.php");
require_once("rfid_serial.class.php");

class rfid{
    const CMD_INVENTORY = 0x01;
    const CMD_GETREADERINFO = 0x21;
    const CMD_GETREADERSERIAL = 0x4C;
    const CMD_SETPOWER = 0x2F;
    const CMD_SETREGION = 0x22;
    const CMD_SETSCANTIME = 0x25;
    const CMD_SETGPIO = 0x46;
    const CMD_SETBEEP = 0x40;
    const ADDR_ALL = 0xFF;
    const NO_DATA_TIMEOUT_SECS = 2;
    const SERIAL_PORT_PREFIX = "/dev/ttyUSB";
    const SPEEDS = [9600, 19200, 57600, 115200]; //v1.2 deteção de velocidade do dispositivo, quando encontrado
    const TRTYPES = [
        0x01 => "ISO18000-6B",
        0x02 => "ISO18000-6C(EPC C1G2)",
        0x03 => "ISO18000-6B/ISO18000-6B(EPC C1G2)"
    ];
    const BANDS = [
        0x00 => ['region' => 'RES', 'base' => 0, 'channelWidth' => 0, 'minChan' => 0, 'maxChan' => 0],
        0x01 => ['region' => 'CHN', 'base' => 920125, 'channelWidth' => 250, 'minChan' => 0, 'maxChan' => 19],
        0x02 => ['region' => 'US', 'base' => 902750, 'channelWidth' => 500, 'minChan' => 0, 'maxChan' => 49],
        0x03 => ['region' => 'KOR', 'base' => 917100, 'channelWidth' => 200, 'minChan' => 0, 'maxChan' => 31],
        0x10 => ['region' => 'EU', 'base' => 865100, 'channelWidth' => 200, 'minChan' => 0, 'maxChan' => 14],
        0x11 => ['region' => 'RES', 'base' => 0, 'channelWidth' => 0, 'minChan' => 0, 'maxChan' => 0],
        0x12 => ['region' => 'RES', 'base' => 0, 'channelWidth' => 0, 'minChan' => 0, 'maxChan' => 0],
        0x13 => ['region' => 'RES', 'base' => 0, 'channelWidth' => 0, 'minChan' => 0, 'maxChan' => 0],
        0x20 => ['region' => 'RES', 'base' => 0, 'channelWidth' => 0, 'minChan' => 0, 'maxChan' => 0],
        0x21 => ['region' => 'RES', 'base' => 0, 'channelWidth' => 0, 'minChan' => 0, 'maxChan' => 0],
        0x22 => ['region' => 'RES', 'base' => 0, 'channelWidth' => 0, 'minChan' => 0, 'maxChan' => 0],
        0x23 => ['region' => 'RES', 'base' => 0, 'channelWidth' => 0, 'minChan' => 0, 'maxChan' => 0],
        0x30 => ['region' => 'RES', 'base' => 0, 'channelWidth' => 0, 'minChan' => 0, 'maxChan' => 0],
        0x31 => ['region' => 'RES', 'base' => 0, 'channelWidth' => 0, 'minChan' => 0, 'maxChan' => 0],
        0x32 => ['region' => 'RES', 'base' => 0, 'channelWidth' => 0, 'minChan' => 0, 'maxChan' => 0],
        0x33 => ['region' => 'RES', 'base' => 0, 'channelWidth' => 0, 'minChan' => 0, 'maxChan' => 0]
    ];
    const REGIONS = [
        'US' => ['minfre' => 0x80, 'maxfre' => 0x31],
        'CHN' => ['minfre' => 0x40, 'maxfre' => 0x13],
        'KOR' => ['minfre' => 0xC0, 'maxfre' => 0x1F],
        'EU' => ['minfre' => 0x00, 'maxfre' => 0x4E]
    ];

    var $serial = NULL;
    var $buffer = NULL;
    var $pertinenceTime = 10; //número de segundos para a classe esquecer as tags.
    var $relevantTags = NULL;
    var $cb_CMD_INVENTORY = NULL;
    var $cb_relevantTags = NULL;
    var $lastReadTS = NULL; //v1.1 implementação de timeout para limpeza de buffer
    var $deviceFound = false; //v1.1 flag de deteção de dispositivo Chafon
    var $serialPort = NULL;
    var $speed = NULL;
    var $deviceInfo = NULL;
    var $serialNr = NULL;

    function __construct(){
        if ($this->autoOpen()){ //v1.1 deteção automática de portas
            echo "Device found at ".$this->serialPort." @".$this->speed."bps\n";
            echo "Reader Version: ".$this->deviceInfo['version']." | ";
            echo "Power: +".$this->deviceInfo['power']."dBm | ";
            echo "Beep Enabled: ".$this->deviceInfo['beepen']."\n";        
        } else {
            echo "RFID Reader not found!";
            die(1);
        };
        $this->relevantTags = array();
    }
    function autoOpen(){ //v1.1 implementada deteção automática de porta serial com base no retorno de getReaderInfo
        for($i=9;$i>=0;$i--){
            foreach(rfid::SPEEDS as $speed){ //v1.2 deteção de velocidade do dispositivo, quando encontrado
                $dev = rfid::SERIAL_PORT_PREFIX.$i;
                $or = @$this->openSerial($dev, $speed);
                if ($or) {
                    $this->serialPort = $dev;
                    $this->speed = $speed;
                    return true;
                }    
            }
        }
        return false;
    }
    function openSerial($dev, $speed){ //v1.1 função openSerial chamada indiretamente para permitir o autoOpen. Retorna presença de letiro de tags Chafon.
        $this->serial = new phpSerial();
        if (!$this->serial->deviceSet($dev)) return NULL;
        $this->serial->setRaw();
        $this->serial->confBaudRate($speed);
        $this->serial->confParity("none");
        $this->serial->confCharacterLength(8);
        $this->serial->confStopBits(1);
        $this->serial->confFlowControl("none");
        $this->serial->deviceOpen();
        $this->getReaderInfo(); //v1.1 solicita getreaderInfo para leitor e aguarda resposta
        sleep(.1);
        $this->poolRX(); //v1.1 em caso de resposta poolRX deteta e seta deviceFound
        return $this->deviceFound;
    }
    function updateRelevant($tags=NULL){
        $ts = time();
        $changed = false;
        if ($tags) foreach($tags as $id=>$newTag) $this->relevantTags[$id] = $newTag;
        foreach($this->relevantTags as $id=>$tag){
            if ($ts-$tag['ts']>$this->pertinenceTime){
                unset($this->relevantTags[$id]);
                $changed = true;
            };
        }
        if (($tags||$changed)&&$this->cb_relevantTags) ($this->cb_relevantTags)($this, $this->relevantTags); //notifica via callback apenas se houve mudança
    }
    function sendCMD($cmd){
        $this->serial->sendMessage($cmd);
    }
    function poolRX(){
        $data = $this->serial->readPort();
        if ($data){
            $this->lastReadTS = time();//v1.1 - implementando timeout para limpar buffer
            $this->buffer.=$data;
        } else {
            if ((time()-$this->lastReadTS) > rfid::NO_DATA_TIMEOUT_SECS){//v1.1 - implementando timeout para limpar buffer
                $this->buffer = NULL;//v1.1 - implementando timeout para limpar buffer
            }
        }

        $this->splitBuffer();
    }
    function splitBuffer(){
        for($i=0; $i<10; $i++){ //faz loop por até 10x para tratar as mensagens. Isso permite o tratamento de 10 mensagens antes de escapar da função
            $len = ord($this->buffer[0]);
            $message = substr($this->buffer, 0, $len+1);
            $this->parseMessage($message);
            $this->buffer = substr($this->buffer, $len+1);
            if (!$this->buffer) break; //sai do loop se as mensagens acabaram
            if (strlen($this->buffer)<ord($this->buffer[0])) break; //sai do loop se o tamanho do buffer é mensor do que o comprimento da próxima mensagem (mensagem incompleta)
        }
    }
    function parseMessage($msg){
        if (!$msg) return; //v1.2 evita mensagens de erro quando o buffer está vazio
        $len = strlen($msg);
        $size = ord($msg[0]);
        $address = ord($msg[1]);
        $command = ord($msg[2]);
        $status = ord($msg[3]);
        $lsb = ord($msg[$len-2]);
        $msb = ord($msg[$len-1]);
        $data = substr($msg, 4, $len-6);
        switch($command){
            case rfid::CMD_INVENTORY:
                $this->recv_CMD_INVENTORY($data);
            break;
            case rfid::CMD_GETREADERINFO:
                $this->recv_CMD_GETREADERINFO($data);
            break;
            case rfid::CMD_GETREADERSERIAL:
                $this->recv_CMD_GETREADERSERIAL($data);
            break;
            case rfid::CMD_SETPOWER:
                $this->recv_CMD_SETPOWER($status);
            break;
            case rfid::CMD_SETSCANTIME:
                $this->recv_CMD_SETSCANTIME($status);
            break;
            case rfid::CMD_SETGPIO:
                $this->recv_CMD_SETGPIO($status);
            break;
            case rfid::CMD_SETBEEP:
                $this->recv_CMD_SETBEEP($status);
            break;
        }
    }
    function recv_CMD_INVENTORY($data){
        $len = strlen($data);
        $ant = ord($data[0]);
        $ntags = ord($data[1]);
        $tags = array();
        if (!$ntags){
            $this->updateRelevant();
            return $tags;
        }
        $rtags = $ntags; //rtags é o contador de tags restantes para a leitura, começa pela quantidade de tags e decresce a cada interação
        $shift = 2; //o tamanho da tag fica sempre no endereço 2
        //echo "----- Nova leitura -----\n";
        while ($rtags--){
            $tagSize = ord($data[$shift]);
            $tag = substr($data, $shift+1, $tagSize);
            $rssi = ord($data[$shift+$tagSize+1]);
            $time = time();
            $tagHex = bin2hex($tag);
            $tags[$tagHex] = array('tag'=>$tagHex, 'rssi'=>$rssi, 'ts'=>$time);
            //echo "[".date("y/m/d H:i:s",$time)."] Tag:$tagHex | RSSI:$rssi | Timestamp:$time\n";
            $shift += $tagSize+2;
        }
        //echo "------------------------\n";
        $this->updateRelevant($tags);
        if ($this->cb_CMD_INVENTORY) ($this->cb_CMD_INVENTORY)($this, $tags);
    }
    function recv_CMD_GETREADERINFO($data){
        $this->deviceInfo = rfid::readerInfoToArray($data);
        $this->deviceFound = true; //v1.1 set deviceFound pois um dispositivo Chafon respondeu ao comando
    }
    function recv_CMD_GETREADERSERIAL($data){
        $sn = ord($data[0])*256^3;
        $sn .= ord($data[1])*256^2;
        $sn .= ord($data[2])*256;
        $sn .= ord($data[3]);
        echo "Reader Serial Number: $sn\n";
        $this->serialNr = $sn;
    }
    function recv_CMD_SETPOWER($status){
        if ($status==0) {
            echo "Set Power OK!\n";
        } else {
            echo "Set Power Failed!\n";
        }
        $this->getReaderInfo(0xff);
    }
    function recv_CMD_SETSCANTIME($status){
        if ($status==0) {
            echo "Set Scan Time OK!\n";
        } else {
            echo "Set Scan Time Failed!\n";
        }
        $this->getReaderInfo(0xff);
    }
    function recv_CMD_SETGPIO($status){
        if ($status==0) {
            echo "Set GPIO OK!\n";
        } else {
            echo "Set GPIO Failed!\n";
        }
        $this->getReaderInfo(0xff);
    }
    function recv_CMD_SETBEEP($status){
        if ($status==0) {
            echo "Set Beep OK!\n";
        } else {
            echo "Set Beep Failed!\n";
        }
        $this->getReaderInfo(0xff);
    }
    function getReaderInfo($address = 0x00){
        $cmd = rfid::getReaderInfo_CMD($address);
        $this->sendCMD($cmd);
    }
    function readerInfoToArray($data){
        if (strlen($data)!=12) return NULL;
        $v1 = ord($data[0]);
        $v2 = ord($data[1]);
        $version = "$v1.$v2";
        $type = ord($data[2]);
        $trType = ord($data[3]);
        $ttype = rfid::TRTYPES[$trType];
        $maxfre = ord($data[4]);
        $minfre = ord($data[5]);
        $maxband = ($maxfre & 0xC0) >> 2;
        $minband = ($minfre & 0xC0) >> 6;
        $maxchan = ($maxfre & 0x3F);
        $minchan = ($minfre & 0x3F);
        $band = $maxband + $minband;
        $minfreq = rfid::BANDS[$band]['base']+$minchan*rfid::BANDS[$band]['channelWidth'];
        $maxfreq = rfid::BANDS[$band]['base']+$maxchan*rfid::BANDS[$band]['channelWidth'];
        $power = ord($data[6]);
        $beepen = ord($data[9]);
        echo "\n------------- ReaderInfo --------------\n";
        echo "| Version: $version\n";
        echo "| Type: 0x".dechex($type)."\n";
        echo "| Tag Type: $ttype\n";
        echo "| Region: ".rfid::BANDS[$band]['region']."\n";
        echo "| MinChan: $minchan ($minfreq kHz)\n";
        echo "| MaxChan: $maxchan ($maxfreq kHz)\n";
        echo "| Transmit Power: +$power dBm\n";
        echo "| Beep Mode: ".($beepen?"Active":"Silent")."\n";
        echo "---------------------------------------\n";
        return [
            'version' => $version,
            'type' => $type,
            'trType' => $trType,
            'dmaxfre' => dechex(ord($data[4])),
            'dminfre' => dechex(ord($data[5])),
            'minchan' => $minchan,
            'maxchan' => $maxchan,
            'minfreq' => $minfreq,
            'maxfreq' => $maxfreq,
            'region' => rfid::BANDS[$band]['region'],
            'power' => $power,
            'scntm' => ord($data[7])*100,
            'ant' => ord($data[8]),
            'beepen' => $beepen,
            'reserved' => ord($data[10]),
            'chant' => ord($data[11])
        ];
        
    }
    function setGPIO($state = 3, $address = 0x00){ 
        // $state = 0 -> GPIO1_ON, GPIO2_OFF 
        // $state = 1 -> GPIO1_ON GPIO2_OFF 
        // $state = 2 -> GPIO1_OFF GPIO2_ON
        // $state = 3 -> GPIO1_ON GPIO2_ON 
        $cmd = rfid::getSetGPIO_CMD($state, $address);
        $this->sendCMD($cmd);
    }
    function setBeep($state = 1, $address = 0x00){ 
        // $state = 1 -> beep on 
        // $state = 0 -> beep off
        $cmd = rfid::getSetBeep_CMD($state, $address);
        $this->sendCMD($cmd);
    }
    function setPower($power = 26, $address = 0x00){ //power 0~30 (dBm)
        $cmd = rfid::getSetPower_CMD($power, $address);
        $this->sendCMD($cmd);
    }
    function setRegion($region = 'US', $address = 0x00){
        $cmd = rfid::getSetRegion_CMD(rfid::REGIONS[$region]['minfre'], rfid::REGIONS[$region]['maxfre'], $address);
        $this->sendCMD($cmd);
    }
    function getReaderSerialNumber($address = 0x00){
        $cmd = rfid::getReaderSerialNumber_CMD($address);
        $this->sendCMD($cmd);
    }
    function setScanTime($timeX100ms = 3, $address = 0x00){ //scan time 3~255 (x100ms)
        $cmd = rfid::getSetScanTime_CMD($timeX100ms, $address);
        $this->sendCMD($cmd);
    }
    function inventory($qvalue = 0x04, $session = 0x00, $address = 0x00){
        $cmd = rfid::getInventory_CMD($qvalue, $session, $address);
        $this->sendCMD($cmd);
    }
    static function addLenCRC($address, $cmd){ //wrap the message with address, length and CRC16
        $cmd = chr($address).$cmd;
        $cmd = chr(strlen($cmd)+2).$cmd;
        $cmd = CRC16::appendCRC($cmd);
        return $cmd;
    }
    static function getReaderInfo_CMD($address = 0x00){
        $cmd = chr(rfid::CMD_GETREADERINFO);
        $cmd = rfid::addLenCRC($address, $cmd);
        return $cmd;
    }
    static function getInventory_CMD($qvalue = 0x04, $session = 0x00, $address = 0x00){
        $cmd = chr(rfid::CMD_INVENTORY).chr($qvalue).chr($session);
        $cmd = rfid::addLenCRC($address, $cmd);
        return $cmd;
    }
    static function getSetPower_CMD($power, $address = 0x00){ //power 0~30 (dBm)
        $cmd = chr(rfid::CMD_SETPOWER).chr($power);
        $cmd = rfid::addLenCRC($address, $cmd);
        return $cmd;
    }
    static function getSetRegion_CMD($minfre = 0x80, $maxfre = 0x31, $address = 0x00){
        $cmd = chr(rfid::CMD_SETREGION).chr($maxfre).chr($minfre);
        $cmd = rfid::addLenCRC($address, $cmd);
        return $cmd;
    }
    static function getSetScanTime_CMD($timeX100ms, $address = 0x00){
        $cmd = chr(rfid::CMD_SETSCANTIME).chr($timeX100ms);
        $cmd = rfid::addLenCRC($address, $cmd);
        return $cmd;
    }
    static function getSetGPIO_CMD($state, $address = 0x00){
        $cmd = chr(rfid::CMD_SETGPIO).chr($state);
        $cmd = rfid::addLenCRC($address, $cmd);
        return $cmd;
    }
    static function getSetBeep_CMD($state, $address = 0x00){
        $cmd = chr(rfid::CMD_SETBEEP).chr($state);
        $cmd = rfid::addLenCRC($address, $cmd);
        return $cmd;
    }
    static function getReaderSerialNumber_CMD($address = 0x00){
        $cmd = chr(rfid::CMD_GETREADERSERIAL);
        $cmd = rfid::addLenCRC($address, $cmd);
        return $cmd;
    }


}