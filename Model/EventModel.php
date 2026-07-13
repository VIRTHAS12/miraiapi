<?php

namespace Model;

use Core\Database;

class EventModel
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    // 🆕 Simpan event baru ke DB
    public function createEvent($data)
    {
        $query = "INSERT INTO events 
            (user_id, google_event_id, title, start_time, end_time, status) 
            VALUES (?, ?, ?, ?, ?, ?)";

        $this->db->query($query, [
            $data['user_id'],
            $data['google_event_id'],
            $data['title'],
            $data['start_time'],
            $data['end_time'],
            $data['status'] ?? 'active'
        ]);

        return $this->db->lastInsertId();
    }

    // 📅 Ambil semua event milik user
    public function getUserEvents($userId)
    {
        $query = "SELECT * FROM events 
                  WHERE user_id = ? AND status = 'active'
                  ORDER BY start_time ASC";

        return $this->db->query($query, [$userId])->get();
    }

    // 🔍 FIX: Cari berdasarkan ID Primary Key lokal (Dibutuhkan CalendarController)
    public function findById($id)
    {
        $query = "SELECT * FROM events WHERE id = ? LIMIT 1";
        return $this->db->query($query, [$id])->take();
    }

    // 🔍 Cari event berdasarkan Google Event ID
    public function findByGoogleEventId($googleEventId)
    {
        $query = "SELECT * FROM events 
                  WHERE google_event_id = ? LIMIT 1";

        return $this->db->query($query, [$googleEventId])->take();
    }

    // ✏️ Update event
    public function updateEvent($id, $data)
    {
        $query = "UPDATE events SET 
        title = ?, 
        start_time = ?, 
        end_time = ?, 
        status = 'active', -- Pastikan statusnya aktif kembali jika ada update
        updated_at = NOW()
        WHERE id = ?";

        $this->db->query($query, [
            $data['title'],
            $data['start_time'],
            $data['end_time'],
            $id
        ]);

        return true;
    }
    // 🗑️ Delete event (soft delete)
    public function deleteEvent($id)
    {
        $query = "UPDATE events SET 
            status = 'cancelled', 
            updated_at = NOW()
            WHERE id = ?";

        $this->db->query($query, [$id]);

        return true;
    }

    public function getWeeklyEvents($userId, $startOfWeek, $endOfWeek)
    {
        $query = "SELECT * FROM events 
                  WHERE user_id = ? AND status = 'active' 
                  AND start_time >= ? AND start_time <= ?
                  ORDER BY start_time ASC";

        return $this->db->query($query, [$userId, $startOfWeek, $endOfWeek])->get();
    }

    public function checkClash($userId, $startTime, $endTime, $excludeEventId = null)
    {
        // Formula overlap: (Mulai_Baru < Selesai_Lama) AND (Selesai_Baru > Mulai_Lama)
        $query = "SELECT * FROM events 
                  WHERE user_id = ? 
                  AND status = 'active'
                  AND (? < end_time AND ? > start_time)";

        $params = [$userId, $startTime, $endTime];

        // Jika sedang update event, abaikan dirinya sendiri agar tidak dianggap clash
        if ($excludeEventId !== null) {
            $query .= " AND id != ?";
            $params[] = $excludeEventId;
        }

        $query .= " LIMIT 1";

        return $this->db->query($query, $params)->take();
    }
}
