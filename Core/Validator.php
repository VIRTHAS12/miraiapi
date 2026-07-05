<?php

namespace Core;

class Validator
{
    public static function check($value, $min = 5, $max = 255)
    {
        $value = trim($value);
        return strlen($value) >= $min && strlen($value) <= $max;
    }
    public static function email($value)
    {
        return filter_var($value, FILTER_VALIDATE_EMAIL);
    }
}
