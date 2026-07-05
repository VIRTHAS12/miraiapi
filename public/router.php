<?php
// public/router.php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: *");
header("Access-Control-Allow-Headers: *");

$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
// Karena router.php sudah di dalam 'public', __DIR__ langsung merujuk ke folder public
$file = __DIR__ . $path; 

$ext = pathinfo($file, PATHINFO_EXTENSION);
$allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'css', 'js'];

// Jika request meminta file statis yang benar-benar ada di folder public/assets/
if (in_array($ext, $allowed) && file_exists($file) && !is_dir($file)) {
    // Return false memerintahkan built-in server PHP untuk menyajikan file secara asinkron (anti-macet)
    return false; 
}

// Jika request berupa endpoint API, oper ke gerbang utama index.php
require __DIR__ . '/index.php';
