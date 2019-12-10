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
require_once("rfid_serial.class.php");

class rfid_fi{
    const CMD_INVENTORY = 0x89;//cmd_real_time_inventory
    const CMD_GETFWVERSION = 0x72;
    const CMD_SETPOWER = 0x66; //usando cmd_set_temporary_output_power (0x66) para não consumir a vida útil da memória flash
    const CMD_SETGPIO = 0x80;
    const CMD_SETBEEP = 0x7A;
    const ADDR_ALL = 0xFF;
    const NO_DATA_TIMEOUT_SECS = 2;
    const SERIAL_PORT_PREFIX = "/dev/ttyUSB";

    var $serial = NULL;
    var $buffer = NULL;
    var $pertinenceTime = 10; //número de segundos para a classe esquecer as tags.
    var $relevantTags = NULL;
    var $cb_CMD_INVENTORY = NULL;
    var $cb_relevantTags = NULL;
    var $lastReadTS = NULL; //v1.1 implementação de timeout para limpeza de buffer
    var $deviceFound = false; //v1.1 flag de deteção de dispositivo 
    var $serialPort = NULL;

    function __construct(){
        if ($this->autoOpen()){ //v1.1 deteção automática de portas
            echo "Device found at $this->serialPort\n";
        } else {
            echo "RFID Reader not found!";
            die(1);
        };
        $this->relevantTags = array();
    }
    function autoOpen(){ //v1.1 implementada deteção automática de porta serial com base no retorno de getReaderInfo
        for($i=9;$i>=0;$i--){
            $dev = rfid_fi::SERIAL_PORT_PREFIX.$i;
            $or = @$this->openSerial($dev);
            if ($or) {
                $this->serialPort = $dev;
                return true;
            }
        }
        return false;
    }
    function openSerial($dev){ //v1.1 função openSerial chamada indiretamente para permitir o autoOpen. Retorna presença de letura de tags .
        $this->serial = new phpSerial();
        if (!$this->serial->deviceSet($dev)) return NULL;
        $this->serial->setRaw();
        $this->serial->confBaudRate(115200);
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
//        echo "Sending: (".strlen($cmd).") ".bin2hex($cmd)."\n";
        $this->serial->sendMessage($cmd);
    }
    function poolRX(){
        $data = $this->serial->readPort();
        if ($data){
//            echo "Received: (".strlen($data).") ".bin2hex($data)."\n"; //para depurar a comunicação
            $this->lastReadTS = time();//v1.1 - implementando timeout para limpar buffer
            $this->buffer.=$data;
        } else {
            if ((time()-$this->lastReadTS) > rfid_fi::NO_DATA_TIMEOUT_SECS){//v1.1 - implementando timeout para limpar buffer
                $this->buffer = NULL;//v1.1 - implementando timeout para limpar buffer
            }
        }

        $this->splitBuffer();
    }
    function splitBuffer(){
        for($i=0; $i<10; $i++){ //faz loop por até 10x para tratar as mensagens. Isso permite o tratamento de 10 mensagens antes de escapar da função
            if (!$this->buffer) break; //se buffer acabou, para o loop
            $header = ord($this->buffer[0]);
            $len = ord($this->buffer[1]);
            $message = substr($this->buffer, 0, $len+1);
            if ($message) $this->parseMessage($message);
            $this->buffer = substr($this->buffer, $len+2);
//            echo "New Buffer: (".strlen($this->buffer).") ".bin2hex($this->buffer)."\n"; //para depuração do buffer
            if (!$this->buffer) break; //sai do loop se as mensagens acabaram
            if (strlen($this->buffer)<ord($this->buffer[1])) break; //sai do loop se o tamanho do buffer é menor do que o comprimento da próxima mensagem (mensagem incompleta)
        }
    }
    function parseMessage($msg){
        $len = strlen($msg);
        $header = ord($msg[0]);
        $size = ord($msg[1]);
        $address = ord($msg[2]);
        $command = ord($msg[3]);
        $data = substr($msg, 4, $len-4);
        $cs = ord($msg[$len-1]);
        switch($command){
            case rfid_fi::CMD_INVENTORY:
                $this->recv_CMD_INVENTORY($data);
            break;
            case rfid_fi::CMD_GETFWVERSION:
                $this->recv_CMD_GETFWVERSION($data);
            break;
            case rfid_fi::CMD_SETPOWER:
                $this->recv_CMD_SETPOWER($data);
            break;
            case rfid_fi::CMD_SETGPIO:
                $this->recv_CMD_SETGPIO($data);
            break;
            case rfid_fi::CMD_SETBEEP:
                $this->recv_CMD_SETBEEP($data);
            break;
        }
    }
    function recv_CMD_INVENTORY($data){
        $tags = array();
        $tagSize = 12;
        $antFreq = ord($data[0]);
        if ($antFreq){
            $PC = substr($data, 1, 2);
            $EPC = substr($data, 3, $tagSize);
            $rssi = ord($data[$tagSize+3]);
            $time = time();
            $tagHex = bin2hex($EPC);
            $tags[$tagHex] = array('tag'=>$tagHex, 'rssi'=>$rssi, 'ts'=>$time);
            //echo "[".date("y/m/d H:i:s",$time)."] Tag:$tagHex | RSSI:$rssi | Timestamp:$time\n";
        }
        $this->updateRelevant($tags);
        if ($this->cb_CMD_INVENTORY) ($this->cb_CMD_INVENTORY)($this, $tags);
    }
    function recv_CMD_GETFWVERSION($data){
        $this->deviceFound = true; //v1.1 set deviceFound pois um dispositivo Chafon respondeu ao comando
        //echo "CMD_GETFWVERSION\n";
    }
    function recv_CMD_GETREADERSERIAL($data){
        //echo "CMD_GETREADERSERIAL\n";
    }
    function recv_CMD_SETPOWER($data){
        //echo "CMD_SETPOWER\n";
    }
    function recv_CMD_SETSCANTIME($data){
        //echo "CMD_SETSCANTIME\n";
    }
    function recv_CMD_SETGPIO($data){
        //echo "CMD_SETGPIO\n";
    }
    function recv_CMD_SETBEEP($data){
        //echo "CMD_SETBEEP\n";
    }
    function getReaderInfo($address = 0x01){
        $cmd = rfid_fi::getReaderInfo_CMD($address);
        $this->sendCMD($cmd);
    }
    function setGPIO($state = 3, $address = 0x01){ 
        // $state = 0 -> GPIO1_ON, GPIO2_OFF 
        // $state = 1 -> GPIO1_ON GPIO2_OFF 
        // $state = 2 -> GPIO1_OFF GPIO2_ON
        // $state = 3 -> GPIO1_ON GPIO2_ON 
        $cmd = rfid_fi::getSetGPIO_CMD($state, $address);
        $this->sendCMD($cmd);
    }
    function setBeep($state = 1, $address = 0x01){ 
        // $state = 1 -> beep on 
        // $state = 0 -> beep off
        $cmd = rfid_fi::getSetBeep_CMD($state, $address);
        $this->sendCMD($cmd);
    }
    function setPower($power = 26, $address = 0x01){ //power 0~33 (dBm)
        $cmd = rfid_fi::getSetPower_CMD($power, $address);
        $this->sendCMD($cmd);
    }
    function inventory($qvalue = 0x04, $session = 0x00, $address = 0x01){
        $cmd = rfid_fi::getInventory_CMD($qvalue, $session, $address);
        $this->sendCMD($cmd);
    }
    static function checkSum($msg){
        $cs = 0;
        for ($i=0;$i<strlen($msg);$i++) $cs += ord($msg[$i]);
        $cs = ~($cs & 0xFF) + 1;
        return chr($cs);
    }
    static function addHeadLenCS($address, $cmd){ //wrap the message with header, address, length and checksum
        $cmd = chr(0xa0).chr(strlen($cmd)+2).chr($address).$cmd;
        $cmd = $cmd.rfid_fi::checkSum($cmd);
        return $cmd;
    }
    static function getReaderInfo_CMD($address = 0x01){
        $cmd = chr(rfid_fi::CMD_GETFWVERSION);
        $cmd = rfid_fi::addHeadLenCS($address, $cmd);
        return $cmd;
    }
    static function getInventory_CMD($qvalue = 0x04, $session = 0x00, $address = 0x01){
        $cmd = chr(rfid_fi::CMD_INVENTORY).chr($qvalue).chr($session);
        $cmd = rfid_fi::addHeadLenCS($address, $cmd);
        return $cmd;
    }
    static function getSetPower_CMD($power, $address = 0x01){ //power 0~30 (dBm)
        $cmd = chr(rfid_fi::CMD_SETPOWER).chr($power);
        $cmd = rfid_fi::addHeadLenCS($address, $cmd);
        return $cmd;
    }
    static function getSetScanTime_CMD($timeX100ms, $address = 0x01){
        $cmd = chr(rfid_fi::CMD_SETSCANTIME).chr($timeX100ms);
        $cmd = rfid_fi::addHeadLenCS($address, $cmd);
        return $cmd;
    }
    static function getSetGPIO_CMD($state, $address = 0x01){
        $cmd = chr(rfid_fi::CMD_SETGPIO).chr($state);
        $cmd = rfid_fi::addHeadLenCS($address, $cmd);
        return $cmd;
    }
    static function getSetBeep_CMD($state, $address = 0x01){
        $cmd = chr(rfid_fi::CMD_SETBEEP).chr($state);
        $cmd = rfid_fi::addHeadLenCS($address, $cmd);
        return $cmd;
    }
    static function getReaderSerialNumber_CMD($address = 0x01){
        $cmd = chr(rfid_fi::CMD_GETREADERSERIAL);
        $cmd = rfid_fi::addHeadLenCS($address, $cmd);
        return $cmd;
    }


}