<?php
class Api_Google_Authenticator{
    const keyRegeneration = 30; // Interval between key regeneration
    const otpLength = 6; // Length of the Token generated
    private static $lut = array("A" => 0, "B" => 1, "C" => 2, "D" => 3, "E" => 4, "F" => 5, "G" => 6, "H" => 7, "I" => 8, "J" => 9, "K" => 10, "L" => 11, "M" => 12, "N" => 13, "O" => 14, "P" => 15, "Q" => 16, "R" => 17, "S" => 18, "T" => 19, "U" => 20, "V" => 21, "W" => 22, "X" => 23, "Y" => 24, "Z" => 25, "2" => 26, "3" => 27, "4" => 28, "5" => 29, "6" => 30, "7" => 31);

    /**
     * Generates a 16 digit secret key in base32 format
     * @return string
     */
    static function generate_secret_key($length = 16){
        $b32 = "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567";
        $s = "";
        for($i = 0; $i < $length; $i++) $s .= $b32[rand(0, 31)];
        return $s;
    }

    /**
     * Returns the current Unix Timestamp devided by the keyRegeneration period.
     * @return integer
     */
    static function get_timestamp(){
        return floor(microtime(true) / self::keyRegeneration);
    }

    /**
     * Decodes a base32 string into a binary string.
     */
    static function base32_decode($b32){
        $b32 = strtoupper($b32);
        if(!preg_match('/^[ABCDEFGHIJKLMNOPQRSTUVWXYZ234567]+$/', $b32, $match)) {
            Tool_Fnc::showMsg('非法输入');
        }
        $l = strlen($b32);
        $n = 0;
        $j = 0;
        $binary = "";
        for($i = 0; $i < $l; $i++){
            $n = $n << 5; // Move buffer left by 5 to make room
            $n = $n + self::$lut[$b32[$i]]; // Add value into buffer
            $j = $j + 5; // Keep track of number of bits in buffer
            if($j >= 8){
                $j = $j - 8;
                $binary .= chr(($n & (0xFF << $j)) >> $j);
            }
        }
        return $binary;
    }

    /**
     * Takes the secret key and the timestamp and returns the one time
     * password.
     *
     * @param binary $key - Secret key in binary form.
     * @param integer $counter - Timestamp as returned by get_timestamp.
     * @return string
     */
    static function oath_hotp($key, $counter){
        $bin_counter = pack('N*', 0) . pack('N*', $counter); // Counter must be 64-bit int
        $hash = hash_hmac('sha1', $bin_counter, $key, true);
        return str_pad(self::oath_truncate($hash), self::otpLength, '0', STR_PAD_LEFT);
    }

    /**
     * Verifys a user inputted key against the current timestamp. Checks $window
     * keys either side of the timestamp.
     *
     * @param string $b32seed
     * @param string $key - User specified key
     * @param integer $window
     * @param boolean $useTimeStamp
     * @return boolean
     */
    static function verify_key($b32seed, $key, $window = 4, $useTimeStamp = true){
        $timeStamp = self::get_timestamp();
        if($useTimeStamp !== true) $timeStamp = (int)$useTimeStamp;
        $binarySeed = self::base32_decode($b32seed);
        for($ts = $timeStamp - $window; $ts <= $timeStamp + $window; $ts++) if(self::oath_hotp($binarySeed, $ts) == $key) return true;
        return false;
    }

    /**
     * Extracts the OTP from the SHA1 hash.
     *
     * @param binary $hash
     * @return integer
     */
    static function oath_truncate($hash){
        $offset = ord($hash[19]) & 0xf;
        return (((ord($hash[$offset + 0]) & 0x7f) << 24) | ((ord($hash[$offset + 1]) & 0xff) << 16) | ((ord($hash[$offset + 2]) & 0xff) << 8) | (ord($hash[$offset + 3]) & 0xff)) % pow(10, self::otpLength);
    }

    /**
     * 通过用户ID得到双重口令
     * @param $pUid
     * @return array
     */
    static function getByUid($pUid){
        $tGA = array('secret'=>'', 'open'=>0, 'login'=>0, 'trust'=>0, 'out'=>0);
        if($tHotp = Cache_Redis::instance()->hGet('user_ga', $pUid)){
            # 处理脏数据
            $tHotp = explode(',', $tHotp);
            $i = 0;
            foreach($tGA as $k1 => $v1){
                isset($tHotp[$i]) || $tHotp[$i] = $k1=='out'? 1: 0;
                $tGA[$k1] = $tHotp[$i];
                ++$i;
            }
            # 写入缓存
            if(count($tHotp) < count($tGA)){
                Cache_Redis::instance()->hSet('user_ga', $pUid, implode(',', $tGA));
            }
        }
        return $tGA;
    }
}