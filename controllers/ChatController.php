<?php

namespace Controllers;

use Model\ChatModel;
use Model\EventModel;
use Core\Database;
use WebSocket\Client;

class ChatController
{
    private $chatModel;
    private $eventModel;

    public function __construct()
    {
        $config = require BASE_PATH . 'config.php';
        $db = new Database($config);

        $this->chatModel = new ChatModel($db);
        $this->eventModel = new EventModel($db);
    }

    // 🤖 KIRIM PESAN KE AI 
    public function sendMessageToAI()
    {
        $user = \Core\Middleware::Userget();
        $input = jsonInput();

        $message = $input['message'] ?? '';

        // 🔥 BACA PAYLOAD EVENT YANG DI-ATTACH DARI FRONTEND (JIKA ADA)
        $attachedEvent = $input['attached_event'] ?? null;

        if (!$message && !$attachedEvent) {
            return response('error', 'Pesan kosong', null, 400);
        }

        // Simpan pesan user asli ke DB lokal
        $this->chatModel->saveMessage([
            'user_id' => $user['id'],
            'role'    => 'user',
            'content' => $message ? $message : "[Mengirim Lampiran Jadwal: " . ($attachedEvent['title'] ?? '') . "]"
        ]);

        $currentDate = date('c');

        // 🔥 SYSTEM PROMPT ADVANCED: Mengenal Create & Update Jadwal
        $systemPrompt = "Waktu saat ini: $currentDate.\n"
            . "Tugas lu adalah mendeteksi apakah user ingin MEMBUAT (create) jadwal baru, MENGUBAH (update) jadwal lama, atau MENGHAPUS (delete) jadwal.\n"
            . "PENTING: Jika ada data dari [CONTEXT ATTACHMENT JADWAL], gunakan data tersebut sebagai acuan target_title utama.\n"
            . "WAJIB kembalikan dalam format JSON murni tanpa markdown, tanpa teks pengantar.\n\n"
            . "Format Jika MEMBUAT Jadwal Baru:\n"
            . "{\"action\": \"create\", \"title\": \"Judul\", \"start\": \"ISO8601\", \"end\": \"ISO8601\"}\n\n"
            . "Format Jika MENGUBAH / UPDATE Jadwal:\n"
            . "{\"action\": \"update\", \"target_title\": \"Nama Jadwal Yang Mau Diubah\", \"title\": \"Judul Baru\", \"start\": \"ISO8601\", \"end\": \"ISO8601\"}\n\n"
            . "Format Jika MENGHAPUS Jadwal (User pakai kata hapus, delete, cancel, hilangkan):\n"
            . "{\"action\": \"delete\", \"target_title\": \"Nama Jadwal Yang Mau Dihapus\"}\n\n"
            . "Jika tidak ada aksi kalender terdeteksi, kembalikan: {\"status\":\"no_event\"}";

        // 🔥 GABUNGKAN CONTEXT JADWAL KE PESAN JIKA ADA ATTACHMENT
        $finalUserMessage = $message;
        if ($attachedEvent) {
            $finalUserMessage = "[CONTEXT ATTACHMENT JADWAL]\n"
                . "Target Title / Current Title: " . ($attachedEvent['title'] ?? '') . "\n"
                . "Current Start: " . ($attachedEvent['start'] ?? '') . "\n"
                . "Current End: " . ($attachedEvent['end'] ?? '') . "\n"
                . "====================\n"
                . "User Message: " . $message;
        }

        // Panggil AI dengan pesan yang sudah dibekali data context attachment
        $aiResponse = $this->callOpenClaw($systemPrompt, $finalUserMessage);

        if (isset($aiResponse['error'])) {
            return response('error', 'Koneksi ke OpenClaw putus: ' . $aiResponse['message'], null, 500);
        }

        if (!$aiResponse || !isset($aiResponse['choices'][0]['message']['content'])) {
            return response('error', 'Gagal mendapatkan respon dari OpenClaw Gateway', $aiResponse, 500);
        }

        $rawAiOutput = trim($aiResponse['choices'][0]['message']['content']);

        // ========================================================
        // 🔍 REGEX SNIPER: Tarik paksa blok JSON dari jawaban AI
        // ========================================================
        $parsed = null;
        if (preg_match('/\{[\s\S]*\}/', $rawAiOutput, $matches)) {
            $cleanJsonString = $matches[0];
            $parsed = json_decode($cleanJsonString, true);
        }

        // Jika Regex gagal atau AI mengembalikan 'no_event'
        if (!$parsed || isset($parsed['status'])) {
            $fallbackMessage = 'Tidak ada informasi jadwal terdeteksi dari kalimat lu, brok.';

            if (!$parsed && !empty($rawAiOutput)) {
                $fallbackMessage = $rawAiOutput;
            }

            $this->chatModel->saveMessage([
                'user_id' => $user['id'],
                'role'    => 'assistant',
                'content' => $fallbackMessage
            ]);

            return response('success', 'Tidak ada event terdeteksi', [
                'ai_raw' => $fallbackMessage
            ]);
        }

        // ========================================================
        // 🔀 PERCABANGAN AKSI: CREATE VS UPDATE VS DELETE
        // ========================================================
        $calendarController = new \Controllers\CalendarController();

        // 1. ❌ AKSI MENGHAPUS JADWAL (DELETE)
        if (isset($parsed['action']) && $parsed['action'] === 'delete') {
            $existingEvents = $this->eventModel->getUserEvents($user['id']);
            $targetEvent = null;

            foreach ($existingEvents as $evt) {
                if (strtolower($evt['title']) === strtolower($parsed['target_title'])) {
                    $targetEvent = $evt;
                    break;
                }
            }

            if (!$targetEvent) {
                $errContent = "Jadwal '" . $parsed['target_title'] . "' emang gak ada atau udah lu hapus sebelumnya, brok.";
                $this->chatModel->saveMessage([
                    'user_id' => $user['id'],
                    'role'    => 'assistant',
                    'content' => $errContent
                ]);
                return response('error', $errContent, null, 404);
            }

            $calendarController->deleteEvent($targetEvent['id']);
            $successMessage = "Jadwal \"" . $targetEvent['title'] . "\" berhasil gue hapus dari Google Calendar lu, brok! 🗑️✅";

            $this->chatModel->saveMessage([
                'user_id' => $user['id'],
                'role'    => 'assistant',
                'content' => $successMessage
            ]);

            return response('success', $successMessage, [
                'action' => 'delete',
                'deleted_title' => $targetEvent['title']
            ]);

            // 2. ✏️ AKSI MENGUBAH JADWAL (UPDATE)
        } elseif (isset($parsed['action']) && $parsed['action'] === 'update') {
            $existingEvents = $this->eventModel->getUserEvents($user['id']);
            $targetEvent = null;

            foreach ($existingEvents as $evt) {
                if (strtolower($evt['title']) === strtolower($parsed['target_title'])) {
                    $targetEvent = $evt;
                    break;
                }
            }

            if (!$targetEvent) {
                $errContent = "Jadwal dengan nama '" . $parsed['target_title'] . "' gak ketemu di data gue, brok.";
                $this->chatModel->saveMessage([
                    'user_id' => $user['id'],
                    'role'    => 'assistant',
                    'content' => $errContent
                ]);
                return response('error', $errContent, null, 404);
            }

            $updateData = [
                'title' => $parsed['title'] ?? $targetEvent['title'],
                'start' => $parsed['start'],
                'end'   => $parsed['end']
            ];

            // Eksekusi update via CalendarController
            $googleResponse = $calendarController->updateEvent($targetEvent['id'], $updateData);

            // 🔥 DETEKSI CLASH SAAT UPDATE
            $responseData = json_decode($googleResponse->getBody(), true);
            if (isset($responseData['status']) && $responseData['status'] === 'error') {
                $clashMessage = $responseData['message'] ?? "Gagal update jadwal karena bentrok, brok.";
                $this->chatModel->saveMessage([
                    'user_id' => $user['id'],
                    'role'    => 'assistant',
                    'content' => $clashMessage
                ]);
                return $googleResponse;
            }

            $timeStartStr = date('H:i', strtotime($parsed['start']));
            $timeEndStr = date('H:i', strtotime($parsed['end']));
            $successMessage = "Berhasil mengupdate kegiatan: \"" . $updateData['title'] . "\" menjadi jam $timeStartStr sampai $timeEndStr WIB! ✅";

            $this->chatModel->saveMessage([
                'user_id' => $user['id'],
                'role'    => 'assistant',
                'content' => $successMessage
            ]);

            return response('success', $successMessage, [
                'event'           => [
                    'title' => $updateData['title'],
                    'start' => date('c', strtotime($parsed['start'])),
                    'end'   => date('c', strtotime($parsed['end']))
                ],
                'google_response' => $responseData
            ]);

            // 3. 📅 AKSI BUAT JADWAL BARU (CREATE)
        } else {
            $eventResponse = $calendarController->createEventFromAI(
                $user,
                $parsed['title'],
                $parsed['start'],
                $parsed['end']
            );

            // 🔥 TANGANI JIKA BENTROK (CLASH)
            if ($eventResponse['status'] === 'clash') {
                $clashMessage = "Gak bisa dijadwalkan brok, soalnya jam segitu lu ada jadwal tabrakan dengan kegiatan '" . $eventResponse['raw']['title'] . "'! 🛑";

                $this->chatModel->saveMessage([
                    'user_id' => $user['id'],
                    'role'    => 'assistant',
                    'content' => $clashMessage
                ]);

                return response('error', $eventResponse['message'], $eventResponse['raw'], 409);
            }

            // TANGANI JIKA EROR GOOGLE API LAINNYA
            if ($eventResponse['status'] === 'error') {
                return response('error', $eventResponse['message'], $eventResponse['raw'], 500);
            }

            // JIKA SELESAI DAN SUKSES, SIMPAN KE DB
            $successMessage = "Berhasil menjadwalkan kegiatan: " . $parsed['title'] . " ✅";
            $this->chatModel->saveMessage([
                'user_id' => $user['id'],
                'role'    => 'assistant',
                'content' => $successMessage
            ]);

            return response('success', 'Event berhasil dibuat via AI', [
                'event'           => $parsed,
                'google_response' => $eventResponse
            ]);
        }
    }

    // 📜 METHOD LOGIC HISTORY
    public function history()
    {
        $user = \Core\Middleware::Userget();

        $chatLogs = $this->chatModel->getChatHistory($user['id'], 20);
        $userEvents = $this->eventModel->getUserEvents($user['id']);

        $formattedData = [];

        foreach ($chatLogs as $chat) {
            $eventData = null;

            if ($chat['role'] === 'assistant') {
                $extractedTitle = null;

                if (preg_match('/Berhasil menjadwalkan kegiatan:\s*(.+?)(?:\s*✅)?$/u', $chat['content'], $matches)) {
                    $extractedTitle = trim($matches[1]);
                } elseif (preg_match('/Berhasil mengupdate kegiatan:\s*"(.+?)"/u', $chat['content'], $matches)) {
                    $extractedTitle = trim($matches[1]);
                }

                if ($extractedTitle) {
                    foreach ($userEvents as $evt) {
                        if (strtolower($evt['title']) === strtolower($extractedTitle)) {
                            $eventData = [
                                'title' => $evt['title'],
                                'start' => date('c', strtotime($evt['start_time'])),
                                'end'   => date('c', strtotime($evt['end_time']))
                            ];
                            break;
                        }
                    }
                }
            }

            $formattedData[] = [
                'role'       => $chat['role'],
                'content'    => $chat['content'],
                'created_at' => date('c', strtotime($chat['created_at'])),
                'event_data' => $eventData
            ];
        }

        return response('success', 'Riwayat chat asisten berhasil dimuat.', $formattedData);
    }

    // 🔧 HELPER CALL OPENCLAW (OPTIMIZED & CLEAN FROM FILE I/O BLOCKING)
    private function callOpenClaw($systemPrompt, $userMessage)
    {
        $apiUrl = $_ENV['OPENCLAW_API_URL'];
        // Menggunakan password sesuai dengan session runtime gateway lu saat ini
        $password = trim($_ENV['OPENCLAW_GATEWAY_PASSWORD']); 

        try {
            $client = new \WebSocket\Client($apiUrl, [
                'timeout' => 45, // Naikkan dikit biar gemini punya nafas lebih panjang saat berpikir
                'fragment_size' => 4096
            ]);

            // 1. Handshake Awal
            $client->receive();

            // 2. Connect payload (Satu array murni seperti cara cerdas lu di devices.json)
            $connectPayload = [
                "type"   => "req",
                "id"     => uniqid(),
                "method" => "connect",
                "params" => [
                    "minProtocol" => 3,
                    "maxProtocol" => 4,
                    "client"      => [
                        "id"       => "gateway-client",
                        "version"  => "1.0.0",
                        "platform" => "linux",
                        "mode"     => "backend"
                    ],
                    "role"   => "operator",
                    "scopes" => ["operator.read", "operator.admin", "operator.write"],
                    "auth"   => [
                        "password" => $password
                    ]
                ]
            ];

            $client->text(json_encode($connectPayload));

            // 3. Auth Validation (Mengikuti gaya aman commit pertama lu brok)
            $auth = $client->receive();
            $authDecoded = json_decode($auth, true);

            // Gak usah pakai if ok jika ok tidak dikirim, tapi proteksi jika balasan mengembalikan error struktural
            if (isset($authDecoded['type']) && $authDecoded['type'] === 'error') {
                $client->close();
                return ['error' => true, 'message' => 'Auth Ditolak: ' . ($authDecoded['error']['errorMessage'] ?? 'Akses dilarang')];
            }

            // 4. Send Message
            $requestId = uniqid();
            $fullMessageString = $userMessage;
            if (!empty($systemPrompt)) {
                $fullMessageString = "[System Instruction: " . $systemPrompt . "]\nUser Message: " . $userMessage;
            }

            $chatPayload = [
                "type"   => "req",
                "id"     => $requestId,
                "method" => "chat.send",
                "params" => [
                    "sessionKey"     => "agent:main:main",
                    "idempotencyKey" => uniqid('idemp_', true),
                    "message"        => (string) $fullMessageString
                ]
            ];

            $client->text(json_encode($chatPayload, JSON_UNESCAPED_SLASHES));

            // 5. Streaming Loop Response Handler (Diadopsi dari pola sukses commit pertama)
            $aiTextResponse = "";

            while (true) {
                $msg = $client->receive();
                if (!$msg) break;

                $decoded = json_decode($msg, true);

                // Lewati keep-alive frame
                if (isset($decoded['event']) && ($decoded['event'] === 'health' || $decoded['event'] === 'tick')) {
                    continue;
                }

                // Ambil text chunk delta
                if (isset($decoded['type']) && $decoded['type'] === 'event') {
                    if ($decoded['event'] === 'chat' || $decoded['event'] === 'agent') {

                        $chunkText = $decoded['payload']['deltaText']
                            ?? $decoded['payload']['data']['text']
                            ?? $decoded['payload']['data']['delta']
                            ?? '';

                        if (!empty($chunkText)) {
                            $aiTextResponse .= $chunkText;
                        }

                        // Status final dari OpenClaw murni
                        if (isset($decoded['payload']['state']) && $decoded['payload']['state'] === 'final') {
                            if (isset($decoded['payload']['message']['content'][0]['text'])) {
                                $aiTextResponse = $decoded['payload']['message']['content'][0]['text'];
                            }
                            break;
                        }

                        if (isset($decoded['payload']['done']) && $decoded['payload']['done'] === true) {
                            break;
                        }
                    }
                }

                // Cek feedback ID Request asli
                if (isset($decoded['id']) && $decoded['id'] === $requestId) {
                    // Jika ada runtime error dari Gemini
                    if (isset($decoded['ok']) && $decoded['ok'] === false) {
                        $client->close();
                        return ['error' => true, 'message' => $decoded['error']['message'] ?? 'Gemini Processing Failed'];
                    }

                    if (isset($decoded['payload']['message']['content'][0]['text'])) {
                        $aiTextResponse = $decoded['payload']['message']['content'][0]['text'];
                        break;
                    }

                    // Pelindung andalan commit pertama: Hanya boleh break saat "ok" kalau teksnya udah beneran keisi semua
                    if (isset($decoded['ok']) && $decoded['ok'] === true) {
                        if (!empty($aiTextResponse)) {
                            break;
                        }
                    }
                }
            }

            $client->close();

            if (!empty($aiTextResponse)) {
                return [
                    'choices' => [
                        [
                            'message' => [
                                'role'    => 'assistant',
                                'content' => trim($aiTextResponse)
                            ]
                        ]
                    ]
                ];
            }

            return ['error' => true, 'message' => 'Gagal mengumpulkan serpihan teks streaming dari model AI.'];
        } catch (\Throwable $e) {
            return ['error' => true, 'message' => 'Exception: ' . $e->getMessage()];
        }
    }
}
