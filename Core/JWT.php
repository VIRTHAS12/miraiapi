<?php

namespace Core;

use Firebase\JWT\JWT as FirebaseJWT;
use Firebase\JWT\Key;


class JWT
{
    private static $secret;

    public static function init($secret)
    {
        self::$secret = $secret;
    }

    public static function put($payload, $expMinutes = 60)
    {
        $payload['iat'] = time();
        $payload['exp'] = time() + ($expMinutes * 60);

        return FirebaseJWT::encode($payload, self::$secret, 'HS256');
    }

    // Simulasi "get" -> decode token
    public static function get($token)
    {
        try {
            $decoded = FirebaseJWT::decode($token, new Key(self::$secret, 'HS256'));
            return (array) $decoded;
        } catch (\Exception $e) {
            return null;
        }
    }
}
