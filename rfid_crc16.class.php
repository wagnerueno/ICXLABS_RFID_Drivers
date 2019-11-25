<?php
class CRC16{
    static function calculate($buffer) {
        $result = 0xFFFF;
        if ( ($length = strlen($buffer)) > 0) {
            for ($offset = 0; $offset < $length; $offset++) {
                $result ^= ord($buffer[$offset]);
                for ($bitwise = 0; $bitwise < 8; $bitwise++) {
                    $lowBit = $result & 0x0001;
                    $result >>= 1;
                    if ($lowBit) $result ^= 0x8408;
                }
            }
        }
        $result &= 0xFFFF;
        return $result;
    }

    static function appendCRC($buffer){
        $result = CRC16::calculate($buffer);
        $MSB = ($result & 0xFF00) >> 8;
        $LSB = $result & 0xFF;
        return $buffer.chr($LSB).chr($MSB);
    }
    
}
