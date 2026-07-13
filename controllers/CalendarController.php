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
        // 🔐 WAJIB DIATAS: Ambil user dan siapkan access token terlebih dahulu
        $user = \Core\Middleware::Userget();
        
        error_log("=== DEBUG SINKRONISASI START FOR USER ID: " . $user['id'] . " ===");

        try {
            $accessToken = $this->getValidAccessToken($user);
            error_log("Access Token Berhasil Diambil: " . substr($accessToken, 0, 15) . "...");
        } catch (\Exception $e) {
            error_log("FATAL ERROR TOKEN: " . $e->getMessage());
            return response('error', 'Gagal refresh token Google: ' . $e->getMessage(), null, 401);
        }

        // =======================================================================
        // 🧪 TEST SNIPER: Langsung tembak ID kalender kelas kerjaan lu
        // =======================================================================
        $testCalendarId = 'c_classroom62a26e2e@group.calendar.google.com';
        error_log("Mencoba langsung sniper fetch ke Kalender Kelas: " . $testCalendarId);
        
        $urlTest = "https://www.googleapis.com/calendar/v3/calendars/" . urlencode($testCalendarId) . "/events";
        $chTest = curl_init($urlTest);
        curl_setopt_array($chTest, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer $accessToken"]
        ]);
        $responseTest = curl_exec($chTest);
        $httpCodeTest = curl_getinfo($chTest, CURLINFO_HTTP_CODE);
        curl_close($chTest);
        
        // LOG INI AKAN LANGSUNG MUNCUL DI PANEL LOGS RAILWAY LU!
        error_log("RESPON SNIPER (HTTP {$httpCodeTest}): " . $responseTest);
        // =======================================================================

        // 🚀 STEP 1: Ambil daftar seluruh kalender
        $urlList = "https://www.googleapis.com/calendar/v3/users/me/calendarList?showHidden=true&minAccessRole=reader";
        $chList = curl_init($urlList);
        curl_setopt_array($chList, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer $accessToken"]
        ]);

        $listResponse = curl_exec($chList);
        $httpCodeList = curl_getinfo($chList, CURLINFO_HTTP_CODE);
        curl_close($chList);

        error_log("RESPON LIST KALENDER (HTTP {$httpCodeList}): " . $listResponse);

        $listResult = json_decode($listResponse, true);
        $calendars = $listResult['items'] ?? [];

        // 🔥 STRATEGI FALLBACK: Jika list kosong/error, paksa masukkan target kalender ke antrean looping
        $hasTargetCalendar = false;
        if (!empty($calendars)) {
            foreach ($calendars as $cal) {
                if (isset($cal['id']) && $cal['id'] === $testCalendarId) {
                    $hasTargetCalendar = true;
                }
            }
        }

        if (!$hasTargetCalendar) {
            error_log("Target kalender tidak ditemukan di list utama. Menjalankan mode Force Injection Fallback!");
            $calendars[] = ['id' => $testCalendarId];
        }

        $formattedEvents = [];

        // 🚀 STEP 2: Looping setiap kalender (Primary, Python, Roblox, dll.)
        foreach ($calendars as $cal) {
            $calendarId = $cal['id'];
            error_log("Processing Calendar ID: " . $calendarId);

            $urlEvents = "https://www.googleapis.com/calendar/v3/calendars/" . urlencode($calendarId) . "/events";
            $chEvents = curl_init($urlEvents);
            curl_setopt_array($chEvents, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ["Authorization: Bearer $accessToken"]
            ]);

            $responseEvents = curl_exec($chEvents);
            curl_close($chEvents);

            $resultEvents = json_decode($responseEvents, true);
            $items = $resultEvents['items'] ?? [];

            if (!isset($resultEvents['items'])) {
                error_log("Gagal mengambil item untuk Kalender {$calendarId}. Respon: " . $responseEvents);
                continue; 
            }

            // 🚀 STEP 3: Looping & Sinkronisasi event dari kalender ini ke DB lokal
            foreach ($items as $item) {
                $start = $item['start']['dateTime'] ?? $item['start']['date'] ?? null;
                $end = $item['end']['dateTime'] ?? $item['end']['date'] ?? null;

                if ($start) {
                    $startTimeFormatted = date('Y-m-d H:i:s', strtotime($start));
                    $endTimeFormatted = $end ? date('Y-m-d H:i:s', strtotime($end)) : $startTimeFormatted;
                    $googleStatus = $item['status'] ?? 'confirmed';

                    $existingEvent = $this->eventModel->findByGoogleEventId($item['id']);

                    if ($googleStatus === 'cancelled') {
                        if ($existingEvent && $existingEvent['status'] !== 'cancelled') {
                            $this->eventModel->deleteEvent($existingEvent['id']);
                        }
                        continue;
                    }

                    if ($existingEvent) {
                        if (
                            $existingEvent['title'] !== ($item['summary'] ?? '(Tanpa Judul)') ||
                            $existingEvent['start_time'] !== $startTimeFormatted ||
                            $existingEvent['end_time'] !== $endTimeFormatted ||
                            $existingEvent['status'] !== 'active'
                        ) {
                            $this->eventModel->updateEvent($existingEvent['id'], [
                                'title' => $item['summary'] ?? '(Tanpa Judul)',
                                'start_time' => $startTimeFormatted,
                                'end_time' => $endTimeFormatted
                            ]);
                        }
                    } else {
                        $this->eventModel->createEvent([
                            'user_id' => $user['id'],
                            'google_event_id' => $item['id'],
                            'title' => $item['summary'] ?? '(Tanpa Judul)',
                            'start_time' => $startTimeFormatted,
                            'end_time' => $endTimeFormatted,
                            'status' => 'active'
                        ]);
                    }

                    $formattedEvents[] = [
                        'id' => $item['id'],
                        'title' => $item['summary'] ?? '(Tanpa Judul)',
                        'start_time' => $startTimeFormatted,
                        'end_time' => $endTimeFormatted
                    ];
                }
            }
        }

        error_log("=== SINKRONISASI SELESAI. TOTAL EVENT BERHASIL DISINKRONKAN: " . count($formattedEvents) . " ===");
        
        return response('success', 'Sinkronisasi selesai brok!', $formattedEvents);
    }    public function deleteEvent($id)
    {
        $user = \Core\Middleware::Userget();

        $event = $this->eventModel->findById($id);

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

        $this->eventModel->deleteEvent($id);

        return response('success', 'Event berhasil dihapus');
    }

    // ✏️ UPDATE EVENT (Menerima parameter dataManual hasil ekstraksi Chat AI)
    public function updateEvent($id, $dataManual = null)
    {
        $user = \Core\Middleware::Userget();
        $input = $dataManual ?? jsonInput();

        $event = $this->eventModel->findById($id);
        if (!$event) {
            return response('error', 'Event tidak ditemukan', null, 404);
        }

        $rawStart = isset($input['start']) ? $input['start'] : $event['start_time'] . ' +07:00';
        $rawEnd   = isset($input['end'])   ? $input['end']   : $event['end_time'] . ' +07:00';

        $startTimeLocal = date('Y-m-d H:i:s', strtotime($rawStart));
        $endTimeLocal = date('Y-m-d H:i:s', strtotime($rawEnd));

        // 🛑 STEP 3: Jalankan Pengecekan Clash Sebelum Update (Kecualikan ID event ini sendiri)
        $clashEvent = $this->eventModel->checkClash($user['id'], $startTimeLocal, $endTimeLocal, $event['id']);
        if ($clashEvent) {
            return response('error', "Gagal update! Bentrok dengan jadwal '" . $clashEvent['title'] . "', brok.", $clashEvent, 409);
        }

        $accessToken = $this->getValidAccessToken($user);

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

        $response = curl_exec($ch);
        curl_close($ch);

        $this->eventModel->updateEvent($id, [
            'title' => $eventData['summary'],
            'start_time' => $startTimeLocal,
            'end_time' => $endTimeLocal
        ]);

        return response('success', 'Event berhasil diupdate', json_decode($response, true));
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
