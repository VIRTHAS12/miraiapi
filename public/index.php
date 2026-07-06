<?php
// public/index.php
date_default_timezone_set('Asia/Jakarta');
const BASE_PATH = __DIR__ . '/../';

require BASE_PATH . '/vendor/autoload.php';

use Dotenv\Dotenv;
use Core\JWT;
use Core\Router;

// Amankan header CORS global paling atas
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization, ngrok-skip-browser-warning");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS");
header("Content-Type: application/json");

// ✅ Tangani preflight request (OPTIONS) buat Firefox / Axios mobile
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ✅ Load .env dan function helper
require BASE_PATH . 'function.php';
$dotenv = Dotenv::createUnsafeImmutable(BASE_PATH);
$dotenv->safeLoad();

// ✅ Inisialisasi JWT
$key = $_ENV['ACCESS_TOKEN_SECRET'];
JWT::init($key);

// ✅ Routing
$router = new Router();

// Panggil routes.php sebagai fungsi yang menerima $router
$routes = require BASE_PATH . 'routes.php';
$routes($router);

// Jalankan routing berdasarkan URL & method
$url = parse_url($_SERVER['REQUEST_URI'])['path'];
$method = $_SERVER['REQUEST_METHOD'];

// 🛡️ AMANKAN HANDLER GAMBAR (Biar Firefox & Emulator lancar)
if (preg_match('/^\/assets\//', $url)) {
    $filePath = rtrim(BASE_PATH, '/') . '/public' . $url;
    if (file_exists($filePath) && !is_dir($filePath)) {
        header_remove("Access-Control-Allow-Origin");
        header_remove("Cache-Control");
        header_remove("Content-Type");

        header("Access-Control-Allow-Origin: *");
        header("Access-Control-Allow-Methods: GET, OPTIONS");
        header("Access-Control-Allow-Headers: Content-Type, Authorization");

        header("Content-Type: " . mime_content_type($filePath));
        header("Content-Length: " . filesize($filePath));
        header("Cache-Control: public, max-age=86400");

        if (ob_get_length()) {
            ob_clean();
        }
        flush();

        readfile($filePath);
        exit;
    } else {
        http_response_code(404);
        echo json_encode(["status" => "Error", "message" => "File gambar tidak ditemukan, brok."]);
        exit;
    }
}

// ✅ SEGERA JALANKAN ROUTING UTAMA DI SINI (Dipastikan berjalan stabil untuk method DELETE)
$router->route($url, $method);
