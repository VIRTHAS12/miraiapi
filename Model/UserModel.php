<?php

namespace Model;

use Core\Database;

class UserModel
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    // 🔍 Cari user berdasarkan Google ID
    public function findByGoogleId($googleId)
    {
        $query = "SELECT * FROM users WHERE google_id = ? LIMIT 1";
        return $this->db->query($query, [$googleId])->take();
    }

    // 🔍 Cari user berdasarkan email
    public function findByEmail($email)
    {
        $query = "SELECT * FROM users WHERE email = ? LIMIT 1";
        return $this->db->query($query, [$email])->take();
    }

    // 🆕 Buat user baru (login pertama via Google)
    public function createUser($data)
    {
        $query = "INSERT INTO users 
            (name, email, google_id, access_token, refresh_token, token_expiry) 
            VALUES (?, ?, ?, ?, ?, ?)";

        $this->db->query($query, [
            $data['name'],
            $data['email'],
            $data['google_id'],
            $data['access_token'],
            $data['refresh_token'],
            $data['token_expiry']
        ]);

        return $this->db->lastInsertId();
    }

    // 🔄 Update token Google (kalau login ulang / refresh token)
    public function updateToken($userId, $data)
    {
        $query = "UPDATE users SET 
            access_token = ?, 
            refresh_token = ?, 
            token_expiry = ?
            WHERE id = ?";

        $this->db->query($query, [
            $data['access_token'],
            $data['refresh_token'],
            $data['token_expiry'],
            $userId
        ]);

        return true;
    }

    // 👤 Ambil user berdasarkan ID (buat JWT auth)
    public function getUserById($id)
    {
        $query = "SELECT * FROM users WHERE id = ? LIMIT 1";
        return $this->db->query($query, [$id])->take();
    }
}
