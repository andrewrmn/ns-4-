<?php
/**
 * Paya plugin for Craft CMS 3.x
 *
 * Checkout with Paya via Payments JS
 *
 * @link      andrewross.co
 * @copyright Copyright (c) 2020 Andrew Ross Co.
 */

namespace neuroscience\paya\variables;

use neuroscience\paya\Paya;

use Craft;

/**
 * @author    Andrew Ross Co.
 * @package   Paya
 * @since     1.0.0
 */
class PayaVariable
{


    // Auth Key Gen
    public function getAuthKey($toBeHashed, $password, $salt, $iv){
        $encryptHash = hash_pbkdf2("sha1", $password, $salt, 1500, 32, true);
        $encrypted = openssl_encrypt($toBeHashed, "aes-256-cbc", $encryptHash, 0, $iv);
        return $encrypted;
    }

    // Salt & IV Gen
    public function getNonces(){
        $iv = openssl_random_pseudo_bytes(16);
        $salt = base64_encode(bin2hex($iv));
        return [
            "iv" => $iv,
            "salt" => $salt
        ];
    }


    public function vars()
    {
        // NS Merchant ID & KEY
        $merchant = [
            "ID" => "832214257270",
            "KEY" => "C7U7D7H8B8F6"
        ];
        // Production API Keys
        $developer = [
            "ID" => "3AUlEb5Y8Aw6O9MNydARnc1nRtkXGAeI",
            "KEY" => "rrvHwB3EensEuAFC"
        ];


        // Sandbox API Keys
        $merchantTest = [
            "ID" => "173859436515",
            "KEY" => "P1J2V8P2Q3D8"
        ];
        $sandbox = [
            "ID" => "4YzuYC5BAfVvBeHF7B1oJ0b3r5swPxhC",
            "KEY" => "lVvsfKLCLitZ4U9A"
        ];

        $request = [
            "environment" => "cert",
            "amount" => "1.00", // use 5.00 to simulate a decline
            "preAuth" => "false"
        ];

        $req = [
            "merchantId" => $merchant['ID'],
            "merchantKey" => $merchant['KEY'], // don't include the Merchant Key in the JavaScript initialization!
            "requestType" => "payment",
            "orderNumber" => "Invoice" . rand(0, 1000),
            "amount" => $request['amount'],
            "preAuth" => $request['preAuth']
        ];

        $vars = [
            "req" => $req,
            "dev" => $developer,
            "sandbox" => $sandbox
        ];

        return $vars;
    }
}
