<?php
namespace Core {
    // Fungsi bawaan lu yang lama biarkan tetap di dalam block namespace Core
    function dd($value)
    {
        echo "<pre>";
        var_dump($value);
        echo "</pre>";
        die();
    }
    
    function url($uri)
    {
        return $_SERVER['REQUEST_URI'] === $uri;
    }

    function authorize($condition, $status)
    {
        if (!$condition) {
            abort($status);
        }
    }

    function view($path, $attribut = [])
    {
        extract($attribut);
        require \base_path('views/' . $path); // Panggil base_path global pakai backslash
    }

    function abort($value)
    {
        http_response_code($value);
        require \base_path("views/{$value}.php");
        die();
    }

    function getBearerToken()
    {
        $headers = getallheaders();

        if (isset($headers['Authorization'])) {
            return trim(str_replace('Bearer', '', $headers['Authorization']));
        }

        if (isset($headers['authorization'])) {
            return trim(str_replace('Bearer', '', $headers['authorization']));
        }

        return null;
    }
}

namespace {

    function base_path($path)
    {
        return BASE_PATH . $path;
    }

    function response($status, $message, $data = null, $code = 200) {
        header_remove("Access-Control-Allow-Origin");
        header("Access-Control-Allow-Origin: *");
        header("Content-Type: application/json; charset=utf-8");
        http_response_code($code);
        
        echo json_encode([
            "status" => $status,
            "message" => $message,
            "data" => $data
        ]);
        exit();
    }

    function jsonInput() {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        return $data ?? [];
    }
}
