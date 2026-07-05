<?php

namespace Model;

use Core\Database;

class ChatModel
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    // 💾 Simpan pesan (user / assistant)
    public function saveMessage($data)
    {
        $query = "INSERT INTO chat_history 
            (user_id, role, content) 
            VALUES (?, ?, ?)";

        $this->db->query($query, [
            $data['user_id'],
            $data['role'],     // 'user' atau 'assistant'
            $data['content']
        ]);

        return $this->db->lastInsertId();
    }

    public function getChatHistory($userId, $limit = 20)
    {
        // Pastikan limit benar-benar integer biar aman dari SQL Injection
        $safeLimit = (int) $limit;

        // Masukin $safeLimit langsung ke query, sisakan ? cuma buat user_id
        $query = "SELECT id, role, content, created_at 
                  FROM chat_history
                  WHERE user_id = ?
                  ORDER BY created_at DESC
                  LIMIT " . $safeLimit;

        // Sekarang array parameternya cuma kirim $userId aja
        $result = $this->db->query($query, [$userId])->get();

        // 🔄 balik urutan biar dari lama → baru pas diload di UI chat
        return array_reverse($result);
    }
}
