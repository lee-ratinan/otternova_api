<?php

use Random\RandomException;

if (!function_exists("generateNanoId")) {
    /**
     * Generate NanoID for public facing PK
     * @throws RandomException
     */
    function generateNanoId(int $length = 12): string
    {
        $alphabet = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz-_';
        $max = strlen($alphabet) - 1;
        $id = '';
        $bytes = random_bytes($length);
        for ($i = 0; $i < $length; $i++) {
            // Avoid modulo bias
            $value = ord($bytes[$i]);
            while ($value > 251) {
                $value = ord(random_bytes(1));
            }
            $id .= $alphabet[$value % 64];
        }
        return $id;
    }
}