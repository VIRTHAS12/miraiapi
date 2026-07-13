<?php

namespace Controllers;

use Model\UserModel;
use Model\EventModel;
use Core\Database;

class CalendarController
{
    private $userModel;
    private $eventModel;

    public function __construct()
    {
        $config = require BASE_PATH . 'config.php';
        $db = new Database($config);

        $this->userModel = new UserModel($db);
        $this->eventModel = new EventModel($db);
    }

    // 🔐 Ambil access token valid (auto refresh kalau expired)
    private function getValidAccessToken($user)
    {
        if (strtotime($user['token_expiry']) > time()) {
            return $user['access_token'];
        }

        $ch = curl_init("https://oauth2.googleapis.com/token");

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'client_id' => $_ENV['GOOGLE_CLIENT_ID'],
                'client_secret' => $_ENV['GOOGLE_CLIENT_SECRET'],
                'refresh_token' => $user['refresh_token'],
                'grant_type' => 'refresh_token'
            ])
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        // 🔥 FIX BUG: Mengganti $apiResponse menjadi $response agar log error tidak crash
        if ($httpCode !== 200) {
            file_put_contents('debug_google.log', "OAuth Refresh Error | Code: $httpCode | Response: " . $response . PHP_EOL, FILE_APPEND);
        }
        curl_close($ch);

        $data = json_decode($response, true);

        if (!isset($data['access_token'])) {
            throw new \Exception("Gagal refresh token Google OAuth");
        }

        $expiry = date('Y-m-d H:i:s', time() + $data['expires_in']);

        $this->userModel->updateToken($user['id'], [
            'access_token' => $data['access_token'],
            'refresh_token' => $user['refresh_token'],
            'token_expiry' => $expiry
        ]);

        return $data['access_token'];
    }

    // 📅 Endpoint internal khusus buat nanganin insert kalender dari data hasil ekstraksi Chat AI
    public function createEventFromAI($user, $title, $start, $end)
    {
        // 🛑 STEP 1: Format dulu waktunya ke format lokal SQL
        $startTimeLocal = date('Y-m-d H:i:s', strtotime($start));
        $endTimeLocal = date('Y-m-d H:i:s', strtotime($end));

        // 🛑 STEP 2: Jalankan Pengecekan Clash Sebelum Create
        $clashEvent = $this->eventModel->checkClash($user['id'], $startTimeLocal, $endTimeLocal);
        if ($clashEvent) {
            return [
                'status' => 'clash',
                'message' => "Jadwal tabrakan dengan kegiatan '" . $clashEvent['title'] . "', brok!",
                'raw' => $clashEvent
            ];
        }

        try {
            $accessToken = $this->getValidAccessToken($user);
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage(), 'raw' => null];
        }

        // 🔥 FIX TIMEZONE: Mengunci zona waktu ke Asia/Jakarta agar tidak lari ke GMT+00
        $eventData = [
            'summary' => $title,
            'start' => [
                'dateTime' => date('c', strtotime($start)),
                'timeZone' => 'Asia/Jakarta'
            ],
            'end' => [
                'dateTime' => date('c', strtotime($end)),
                'timeZone' => 'Asia/Jakarta'
            ]
        ];

        $ch = curl_init("https://www.googleapis.com/calendar/v3/calendars/primary/events");

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer $accessToken",
                "Content-Type: application/json"
            ],
            CURLOPT_POSTFIELDS => json_encode($eventData)
        ]);

        $response = curl_exec($ch);
        $result = json_decode($response, true);
        curl_close($ch);

        if (!isset($result['id'])) {
            return [
                'status' => 'error',
                'message' => 'Gagal membuat jadwal di Google Calendar API',
                'raw' => $result
            ];
        }

        // Simpan ke DB lokal menggunakan format datetime standar SQL (Lokal WIB)
        $this->eventModel->createEvent([
            'user_id' => $user['id'],
            'google_event_id' => $result['id'],
            'title' => $title,
            'start_time' => $startTimeLocal,
            'end_time' => $endTimeLocal
        ]);

        return [
            'status' => 'success',
            'raw' => $result
        ];
    }

    // 📅 CREATE EVENT (Via Form Input manual biasa dari frontend Expo)
    public function createEvent()
    {
        $user = \Core\Middleware::Userget();
        $input = jsonInput();

        $title = $input['title'] ?? '';
        $start = $input['start'] ?? '';
        $end = $input['end'] ?? '';

        if (!$title || !$start || !$end) {
            return response('error', 'Data tidak lengkap', null, 400);
        }

        $eventResponse = $this->createEventFromAI($user, $title, $start, $end);

        // Jika terdeteksi clash, kirim status 409 Conflict agar mobile app tahu ada tabrakan
        if ($eventResponse['status'] === 'clash') {
            return response('error', $eventResponse['message'], $eventResponse['raw'], 409);
        }

        if ($eventResponse['status'] === 'error') {
            return response('error', $eventResponse['message'], $eventResponse['raw'], 500);
        }

        return response('success', 'Event berhasil dibuat', $eventResponse['raw']);
    }

    // 📖 GET EVENTS
    public function getEvents()
    {
        $user = \Core\Middleware::Userget();
        $accessToken = $this->getValidAccessToken($user);

        $ch = curl_init("https://www.googleapis.com/calendar/v3/calendars/primary/events");

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer $accessToken"
            ]
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $result = json_decode($response, true);
        $items = $result['items'] ?? [];

        $formattedEvents = [];
        foreach ($items as $item) {
            $start = $item['start']['dateTime'] ?? $item['start']['date'] ?? null;
            $end = $item['end']['dateTime'] ?? $item['end']['date'] ?? null;

            if ($start) {
                $startTimeFormatted = date('Y-m-d H:i:s', strtotime($start));
                $endTimeFormatted = $end ? date('Y-m-d H:i:s', strtotime($end)) : $startTimeFormatted;

                $formattedEvents[] = [
                    'id' => $item['id'],
                    'title' => $item['summary'] ?? '(Tanpa Judul)',
                    'start_time' => $startTimeFormatted,
                    'end_time' => $endTimeFormatted
                ];
            }
        }

        return response('success', 'List event', $formattedEvents);
    }

    // ❌ DELETE EVENT
    public function deleteEvent($id = null)
    {
        $user = \Core\Middleware::Userget();
        
        // Mengakomodasi parsing ID dari query string API routes (?id=X) jika diakses langsung via HTTP DELETE
        $eventId = $id ?? ($_GET['id'] ?? null);

        if (!$eventId) {
            return response('error', 'ID Event wajib disertakan', null, 400);
        }

        $event = $this->eventModel->findById($eventId);

        if (!$event) {
            return response('error', 'Event tidak ditemukan di DB lokal', null, 404);
        }

        $accessToken = $this->getValidAccessToken($user);
        $url = "https://www.googleapis.com/calendar/v3/calendars/primary/events/" . $event['google_event_id'];

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => "DELETE",
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer $accessToken"
            ]
        ]);

        curl_exec($ch);
        curl_close($ch);

        $this->eventModel->deleteEvent($eventId);

        return response('success', 'Event berhasil dihapus');
    }

    // ✏️ UPDATE EVENT (Aman untuk Route HTTP PUT manual & Internal Chat AI)
    public function updateEvent($id = null, $dataManual = null)
    {
        $user = \Core\Middleware::Userget();

        // 🔥 Deteksi context: Kalau ada $dataManual, berarti dipanggil dari Chat AI (Internal)
        $isInternal = ($dataManual !== null);
        $input = $dataManual ?? jsonInput();
        
        // Mengakomodasi parsing ID dari argument internal ATAU URL Query string (?id=X) dari router
        $eventId = $id ?? ($input['id'] ?? ($_GET['id'] ?? null));

        if (!$eventId) {
            $msg = 'ID Event tidak ditemukan';
            return $isInternal ? ['status' => 'error', 'message' => $msg] : response('error', $msg, null, 400);
        }

        $event = $this->eventModel->findById($eventId);
        if (!$event) {
            $msg = 'Event tidak ditemukan di database';
            return $isInternal ? ['status' => 'error', 'message' => $msg] : response('error', $msg, null, 404);
        }

        $rawStart = isset($input['start']) ? $input['start'] : $event['start_time'] . ' +07:00';
        $rawEnd   = isset($input['end'])   ? $input['end']   : $event['end_time'] . ' +07:00';

        $startTimeLocal = date('Y-m-d H:i:s', strtotime($rawStart));
        $endTimeLocal = date('Y-m-d H:i:s', strtotime($rawEnd));

        // 🛑 STEP 3: Jalankan Pengecekan Clash Sebelum Update (Kecualikan ID event ini sendiri)
        $clashEvent = $this->eventModel->checkClash($user['id'], $startTimeLocal, $endTimeLocal, $eventId);
        if ($clashEvent) {
            $msg = "Gagal update! Bentrok dengan jadwal '" . $clashEvent['title'] . "', brok.";
            return $isInternal
                ? ['status' => 'error', 'message' => $msg, 'raw' => $clashEvent]
                : response('error', $msg, $clashEvent, 409);
        }

        try {
            $accessToken = $this->getValidAccessToken($user);
        } catch (\Exception $e) {
            return $isInternal
                ? ['status' => 'error', 'message' => $e->getMessage()]
                : response('error', $e->getMessage(), null, 500);
        }

        $eventData = [
            'summary' => $input['title'] ?? $event['title'],
            'start' => [
                'dateTime' => date('c', strtotime($rawStart)),
                'timeZone' => 'Asia/Jakarta'
            ],
            'end' => [
                'dateTime' => date('c', strtotime($rawEnd)),
                'timeZone' => 'Asia/Jakarta'
            ]
        ];

        $url = "https://www.googleapis.com/calendar/v3/calendars/primary/events/" . $event['google_event_id'];

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => "PUT",
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer $accessToken",
                "Content-Type: application/json"
            ],
            CURLOPT_POSTFIELDS => json_encode($eventData)
        ]);

        $apiResponse = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decodedResponse = json_decode($apiResponse, true);

        // Handle error dari API Google Calendar
        if ($httpCode !== 200 || isset($decodedResponse['error'])) {
            $msg = $decodedResponse['error']['message'] ?? 'Gagal update di Google Calendar API';
            file_put_contents('debug_google.log', "PUT Update Error | Code: $httpCode | Response: " . $apiResponse . PHP_EOL, FILE_APPEND);
            
            return $isInternal
                ? ['status' => 'error', 'message' => $msg, 'raw' => $decodedResponse]
                : response('error', $msg, $decodedResponse, 500);
        }

        $this->eventModel->updateEvent($eventId, [
            'title' => $eventData['summary'],
            'start_time' => $startTimeLocal,
            'end_time' => $endTimeLocal
        ]);

        return $isInternal
            ? ['status' => 'success', 'raw' => $decodedResponse]
            : response('success', 'Event berhasil diupdate', $decodedResponse);
    }

    public function getLocalEvents()
    {
        $user = \Core\Middleware::Userget();

        $startDate = date('Y-m-d 00:00:00', strtotime('-7 days'));
        $endDate = date('Y-m-d 23:59:59', strtotime('+35 days'));

        $events = $this->eventModel->getWeeklyEvents($user['id'], $startDate, $endDate);

        return response('success', 'List event berhasil dimuat.', $events);
    }
}
