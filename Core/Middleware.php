<?php

namespace Core;

use Model\UserModel;
use Core\Database;

class Middleware
{
    // Property static untuk menyimpan cache data user aktif
    public static $user = null; 

    public static function Userget()
    {
        // 1. Jika data user sudah pernah ditarik sebelumnya dalam request ini, langsung balikin
        if (self::$user !== null) {
            return self::$user;
        }

        // 2. Ambil token Bearer dari HTTP Header
        $token = getBearerToken();

        if (!$token) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'Token tidak ditemukan']);
            exit;
        }

        // 3. Decode token JWT internal
        $decoded = JWT::get($token);

        if (!$decoded || !isset($decoded['id'])) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'Token tidak valid atau kedaluwarsa']);
            exit;
        }

        // 4. FIX UTAMA: Tarik data user utuh dari DB biar access_token Google-nya kebawa!
        $config = require BASE_PATH . 'config.php';
        $db = new Database($config);
        $userModel = new UserModel($db);

        // Ambil data real-time berdasarkan ID dari payload JWT
        $realUser = $userModel->getUserById($decoded['id']);

        if (!$realUser) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'User tidak terdaftar di sistem']);
            exit;
        }

        // 5. Simpan ke property static (cache) dan kembalikan array datanya
        self::$user = $realUser;
        return self::$user; 
    }

    // Fungsi helper tambahan jika lu butuh validasi gate tanpa return data di bridge file API
    public static function auth()
    {
        self::Userget();
    }
}
