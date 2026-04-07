<?php

namespace modules\paya\variables;

class PayaVariable
{
    public function getAuthKey($toBeHashed, $password, $salt, $iv)
    {
        $encryptHash = hash_pbkdf2('sha1', $password, $salt, 1500, 32, true);
        $encrypted = openssl_encrypt($toBeHashed, 'aes-256-cbc', $encryptHash, 0, $iv);

        return $encrypted;
    }

    public function getNonces()
    {
        $iv = openssl_random_pseudo_bytes(16);
        $salt = base64_encode(bin2hex($iv));

        return [
            'iv' => $iv,
            'salt' => $salt,
        ];
    }

    public function vars()
    {
        $merchant = [
            'ID' => '832214257270',
            'KEY' => 'C7U7D7H8B8F6',
        ];
        $developer = [
            'ID' => '3AUlEb5Y8Aw6O9MNydARnc1nRtkXGAeI',
            'KEY' => 'rrvHwB3EensEuAFC',
        ];

        $merchantTest = [
            'ID' => '173859436515',
            'KEY' => 'P1J2V8P2Q3D8',
        ];
        $sandbox = [
            'ID' => '4YzuYC5BAfVvBeHF7B1oJ0b3r5swPxhC',
            'KEY' => 'lVvsfKLCLitZ4U9A',
        ];

        $request = [
            'environment' => 'cert',
            'amount' => '1.00',
            'preAuth' => 'false',
        ];

        $req = [
            'merchantId' => $merchant['ID'],
            'merchantKey' => $merchant['KEY'],
            'requestType' => 'payment',
            'orderNumber' => 'Invoice' . rand(0, 1000),
            'amount' => $request['amount'],
            'preAuth' => $request['preAuth'],
        ];

        return [
            'req' => $req,
            'dev' => $developer,
            'sandbox' => $sandbox,
        ];
    }
}
