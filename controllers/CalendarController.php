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
        $user = \Core\Middleware::Userget();
        $accessToken = $this->getValidAccessToken($user);

        // 🚀 STEP 1: Ambil daftar seluruh kalender
        $urlList = "https://www.googleapis.com/calendar/v3/users/me/calendarList?showHidden=true&minAccessRole=reader";
        $chList = curl_init($urlList);
        curl_setopt_array($chList, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer $accessToken"]
        ]);
        $listResponse = curl_exec($chList);
        curl_close($chList);

        $listResult = json_decode($listResponse, true);
        $calendars = $listResult['items'] ?? [];

        $testCalendarId = 'c_classroom62a26e2e@group.calendar.google.com';
        $hasTargetCalendar = false;
        if (!empty($calendars)) {
            foreach ($calendars as $cal) {
                if (isset($cal['id']) && $cal['id'] === $testCalendarId) {
                    $hasTargetCalendar = true;
                }
            }
        }
        if (!$hasTargetCalendar) {
            $calendars[] = ['id' => $testCalendarId];
        }

        $formattedEvents = [];
        // Gunakan kombinasi ID + Jam sebagai penanda unik agar tidak terjadi overwriting di memori loop
        $processedComboKeys = [];

        // 🚀 STEP 2: Looping setiap kalender (Primary, Python, Roblox, dll.)
        foreach ($calendars as $cal) {
            $calendarId = $cal['id'];

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
                continue;
            }

            // 🚀 STEP 3: Looping & Sinkronisasi event
            foreach ($items as $item) {
                $start = $item['start']['dateTime'] ?? $item['start']['date'] ?? null;
                $end = $item['end']['dateTime'] ?? $item['end']['date'] ?? null;

                if ($start) {
                    $startTimeFormatted = date('Y-m-d H:i:s', strtotime($start));
                    $endTimeFormatted = $end ? date('Y-m-d H:i:s', strtotime($end)) : $startTimeFormatted;
                    $googleStatus = $item['status'] ?? 'confirmed';

                    // Bikin kunci unik gabungan untuk loop memori
                    $comboKey = $item['id'] . '_' . $startTimeFormatted;
                    if (in_array($comboKey, $processedComboKeys)) {
                        continue;
                    }
                    $processedComboKeys[] = $comboKey;

                    // 🔍 FIX UTAMA: Cari di DB berdasarkan Google ID DAN Start Time agar slot jam 10, 13, 15 tidak saling menimpa!
                    $db = new Database(require BASE_PATH . 'config.php');
                    $queryCheck = "SELECT * FROM events WHERE google_event_id = ? AND start_time = ? LIMIT 1";
                    $existingEvent = $db->query($queryCheck, [$item['id'], $startTimeFormatted])->take();

                    if ($googleStatus === 'cancelled') {
                        if ($existingEvent && $existingEvent['status'] !== 'cancelled') {
                            $this->eventModel->deleteEvent($existingEvent['id']);
                        }
                        continue;
                    }

                    if ($existingEvent) {
                        if (
                            $existingEvent['title'] !== ($item['summary'] ?? '(Tanpa Judul)') ||
                            strtotime($existingEvent['end_time']) !== strtotime($endTimeFormatted) ||
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

        return response('success', 'Sinkronisasi selesai brok!', $formattedEvents);
    }
    public function deleteEvent($id)
    {
        $user = \Core\Middleware::Userget();

        // 🔍 FIX: Cari menggunakan findByGoogleEventId karena frontend mengirim Google ID string
        $event = $this->eventModel->findByGoogleEventId($id);

        if (!$event) {
            return response('error', 'Event tidak ditemukan di DB lokal, brok.', null, 404);
        }

        $accessToken = $this->getValidAccessToken($user);

        // PENTING: Karena shared/classroom calendar gak bisa dihapus sembarangan via primary bypass, 
        // pastikan targetnya ke primary, atau handle soft-delete lokal jika ini kalender institusi
        $url = "https://www.googleapis.com/calendar/v3/calendars/primary/events/" . $event['google_event_id'];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => "DELETE",
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer $accessToken"
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Hapus dari DB lokal menggunakan ID primernya
        $this->eventModel->deleteEvent($event['id']);

        return response('success', 'Event berhasil dihapus dari sistem, brok!');
    }

    // ✏️ UPDATE EVENT - FIXED PENCARIAN BERDASARKAN GOOGLE ID
    public function updateEvent($id, $dataManual = null)
    {
        $user = \Core\Middleware::Userget();
        $input = $dataManual ?? jsonInput();

        // 🔍 FIX: Cari menggunakan findByGoogleEventId karena frontend mengirim Google ID string
        $event = $this->eventModel->findByGoogleEventId($id);
        if (!$event) {
            return response('error', 'Event tidak ditemukan untuk diupdate', null, 404);
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

        // Update target ke Google Calendar primary
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

        // Eksekusi update ke Model lokal menggunakan id database internal
        $this->eventModel->updateEvent($event['id'], [
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
