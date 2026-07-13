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

        // Bersihkan spasi/karakter aneh di ujung output AI
        $rawAiOutput = trim($rawAiOutput);

        if (preg_match('/\{[\s\S]*/', $rawAiOutput, $matches)) {
            $cleanJsonString = trim($matches[0]);

            // 🔥 FALLBACK FIXER: Jika string diawali '{' tapi ujungnya bukan '}', tambal manual!
            if (str_starts_with($cleanJsonString, '{') && !str_ends_with($cleanJsonString, '}')) {
                $cleanJsonString .= '}';
            }

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

        // 🔥 AMANKAN TARGET TITLE: Ambil dari AI, kalau kosong ambil dari context attachment frontend
        $targetTitleFromAI = $parsed['target_title'] ?? null;
        if (empty($targetTitleFromAI) && $attachedEvent) {
            $targetTitleFromAI = $attachedEvent['title'] ?? '';
        }

        // 1. ❌ AKSI MENGHAPUS JADWAL (DELETE)
        if (isset($parsed['action']) && $parsed['action'] === 'delete') {
            $existingEvents = $this->eventModel->getUserEvents($user['id']);
            $targetEvent = null;

            foreach ($existingEvents as $evt) {
                // Anti-Null Safe Core Injection (?? '')
                if (strtolower($evt['title'] ?? '') === strtolower($targetTitleFromAI ?? '')) {
                    $targetEvent = $evt;
                    break;
                }
            }

            if (!$targetEvent) {
                $errContent = "Jadwal '" . ($targetTitleFromAI ?? 'Unknown') . "' emang gak ada atau udah lu hapus sebelumnya, brok.";
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

            // 🚀 Coba cari dulu berdasarkan tebakan target_title dari AI
            foreach ($existingEvents as $evt) {
                if (strtolower($evt['title'] ?? '') === strtolower($targetTitleFromAI ?? '')) {
                    $targetEvent = $evt;
                    break;
                }
            }

            // 🔄 FALLBACK SAKTI: Kalau tebakan AI meleset, paksa cari pake judul asli yang ada di lampiran attached_event!
            if (!$targetEvent && $attachedEvent) {
                $attachedTitle = $attachedEvent['title'] ?? '';
                foreach ($existingEvents as $evt) {
                    if (strtolower($evt['title'] ?? '') === strtolower($attachedTitle)) {
                        $targetEvent = $evt;
                        break;
                    }
                }
            }

            if (!$targetEvent) {
                $errContent = "Jadwal dengan nama '" . ($targetTitleFromAI ?? 'Unknown') . "' gak ketemu di data gue, brok.";
                $this->chatModel->saveMessage([
                    'user_id' => $user['id'],
                    'role'    => 'assistant',
                    'content' => $errContent
                ]);
                return response('error', $errContent, null, 404);
            }

            $updateData = [
                'title' => !empty($parsed['title']) ? $parsed['title'] : $targetEvent['title'],
                'start' => $parsed['start'],
                'end'   => $parsed['end']
            ];

            // Eksekusi update via CalendarController
            $googleResponse = $calendarController->updateEvent($targetEvent['id'], $updateData);

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
                ]
            ]);

            // 3. 📅 AKSI BUAT JADWAL BARU (CREATE)
        } else {
            $eventResponse = $calendarController->createEventFromAI(
                $user,
                $parsed['title'],
                $parsed['start'],
                $parsed['end']
            );

            if ($eventResponse['status'] === 'clash') {
                $clashMessage = "Gak bisa dijadwalkan brok, soalnya jam segitu lu ada jadwal tabrakan dengan kegiatan '" . $eventResponse['raw']['title'] . "'! 🛑";
                $this->chatModel->saveMessage([
                    'user_id' => $user['id'],
                    'role'    => 'assistant',
                    'content' => $clashMessage
                ]);
                return response('error', $eventResponse['message'], $eventResponse['raw'], 409);
            }

            if ($eventResponse['status'] === 'error') {
                return response('error', $eventResponse['message'], $eventResponse['raw'], 500);
            }

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
                        if (strtolower($evt['title'] ?? '') === strtolower($extractedTitle)) {
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

    private function callOpenClaw($systemPrompt, $userMessage)
    {
        $apiUrl = $_ENV['OPENCLAW_API_URL'];
        try {
            $client = new \WebSocket\Client($apiUrl, [
                'timeout' => 60,
                'headers' => [
                    'Origin' => 'http://127.0.0.1:18789'
                ],
                'context' => stream_context_create([
                    'ssl' => [
                        'verify_peer'        => false,
                        'verify_peer_name'  => false,
                        'allow_self_signed' => true
                    ]
                ])
            ]);

            $handshake = $client->receive();
            file_put_contents('debug_openclaw.log', "HANDSHAKE:\n$handshake\n\n", FILE_APPEND);

            $connectPayload = [
                "type"   => "req",
                "id"     => uniqid(),
                "method" => "connect",
                "params" => [
                    "minProtocol" => 4,
                    "maxProtocol" => 4,
                    "client"      => [
                        "id"       => "openclaw-control-ui",
                        "version"  => "control-ui",
                        "platform" => "Linux x86_64",
                        "mode"     => "webchat"
                    ],
                    "role"   => "operator",
                    "scopes" => ["operator.admin", "operator.read", "operator.write", "operator.approvals", "operator.pairing"],
                    "auth"   => [
                        "password" => "kurokaze"
                    ]
                ]
            ];
            $client->text(json_encode($connectPayload));

            $auth = $client->receive();
            file_put_contents('debug_openclaw.log', "AUTH:\n$auth\n\n", FILE_APPEND);
            $authDecoded = json_decode($auth, true);

            if (!($authDecoded['ok'] ?? false)) {
                $client->close();
                return ['error' => true, 'message' => 'Auth Client Gagal', 'raw' => $auth];
            }

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
            file_put_contents('debug_openclaw.log', "PROMPT SENT\n", FILE_APPEND);

            $aiTextResponse = "";

            while (true) {
                $msg = $client->receive();
                if (!$msg) break;

                file_put_contents('debug_openclaw.log', "RECV FRAME: $msg\n", FILE_APPEND);
                $decoded = json_decode($msg, true);

                if (isset($decoded['event']) && ($decoded['event'] === 'health' || $decoded['event'] === 'tick')) {
                    continue;
                }

                if (isset($decoded['type']) && $decoded['type'] === 'event') {
                    if ($decoded['event'] === 'chat' || $decoded['event'] === 'agent') {

                        $chunkText = $decoded['payload']['deltaText']
                            ?? $decoded['payload']['data']['text']
                            ?? $decoded['payload']['data']['delta']
                            ?? '';

                        if (!empty($chunkText)) {
                            $aiTextResponse .= $chunkText;
                        }

                        if (isset($decoded['payload']['state']) && $decoded['payload']['state'] === 'error') {
                            $client->close();
                            $errMsg = $decoded['payload']['errorMessage'] ?? 'LLM Agent Error Encountered';
                            return ['error' => true, 'message' => $errMsg, 'raw' => $msg];
                        }

                        if (isset($decoded['payload']['state']) && $decoded['payload']['state'] === 'final') {
                            $finalText = $decoded['payload']['message']['content'][0]['text']
                                ?? $decoded['payload']['message']['content'][0]
                                ?? '';

                            if (!empty($finalText) && is_string($finalText)) {
                                $aiTextResponse = $finalText;
                            }
                            break;
                        }

                        if (isset($decoded['payload']['done']) && $decoded['payload']['done'] === true) {
                            break;
                        }
                    }
                }

                if (isset($decoded['id']) && $decoded['id'] === $requestId) {
                    if (isset($decoded['ok']) && $decoded['ok'] === false) {
                        $client->close();
                        return ['error' => true, 'message' => $decoded['errorMessage'] ?? 'Request chat.send rejected', 'raw' => $msg];
                    }

                    $finalText = $decoded['payload']['message']['content'][0]['text'] ?? '';
                    if (!empty($finalText)) {
                        $aiTextResponse = $finalText;
                        break;
                    }

                    if (($decoded['ok'] ?? false) && !empty($aiTextResponse)) {
                        break;
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

            return ['error' => true, 'message' => 'Sesi chat selesai tanpa mengembalikan output teks.'];
        } catch (\Throwable $e) {
            return ['error' => true, 'message' => $e->getMessage()];
        }
    }
}
