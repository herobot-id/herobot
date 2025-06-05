<?php

namespace App\Http\Controllers;

use App\Models\ChatHistory;
use App\Models\Integration;
use App\Models\Transaction;
use App\Services\OpenAIService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppMessageController extends Controller
{
    protected $openAIService;

    public function __construct(OpenAIService $openAIService)
    {
        $this->openAIService = $openAIService;
    }

    public function handleIncomingMessage(Request $request)
    {
        $integrationId = $request->input('integrationId');
        $sender = $request->input('sender');
        $messageContent = $request->input('message');

        $integration = Integration::with(['bots', 'team.balance'])->findOrFail($integrationId);
        $bot = $integration->bots->first();
        $team = $integration->team;

        if (! $bot) {
            return response()->json(['error' => 'No bot found for this integration'], 404);
        }

        // // Check if team has enough credits (150 per response)
        // if ($team->balance->amount < 150) {
        //     return response()->json(['error' => 'Insufficient credits. Please top up your credits to continue using the service.'], 402);
        // }

        // Get latest transaction
        $latestTransaction = Transaction::where('team_id', $team->id)
            ->latest()
            ->first();

        $chatHistory = ChatHistory::where('integration_id', $integrationId)
            ->where('sender', $sender)
            ->latest()
            ->take(5)
            ->get()
            ->reverse()
            ->values();

        $response = $this->generateResponse($bot, $messageContent, $chatHistory);

        // Create or update transaction record
        if ($latestTransaction && $latestTransaction->type == 'usage' && $latestTransaction->created_at->isToday()) {
            // Update existing transaction for today
            $totalResponses = ($latestTransaction->amount / 150) + 1;
            $latestTransaction->update([
                'amount' => $latestTransaction->amount + 150,
                'description' => 'AI Response Credits Usage (Total responses: ' . $totalResponses . ')',
            ]);
        } else {
            // Create new transaction
            Transaction::create([
                'team_id' => $team->id,
                'amount' => 150,
                'type' => 'usage',
                'description' => 'AI Response Credits Usage (Total responses: 1)',
                'status' => 'completed',
            ]);
        }

        // Deduct credits from team's balance
        $team->balance->decrement('amount', 150);

        $this->saveChatHistory($integrationId, $sender, $messageContent, $response);

        return response()->json(['response' => $response]);
    }

    /**
     * Menghasilkan respons dari model AI (OpenRouter atau Gemini) dengan fallback.
     *
     * @param  object                                   $bot         Instance model bot (memiliki properti "prompt")
     * @param  string                                   $message     Pesan terbaru dari pengguna
     * @param  \Illuminate\Support\Collection           $chatHistory Koleksi objek riwayat obrolan (memiliki properti "message" dan "response")
     * @return string|bool  String berisi jawaban terformat, atau false kalau gagal
     */
    private function generateResponse($bot, $message, $chatHistory)
    {
        // 1. Ambil URL dan Nama aplikasi untuk header custom (OpenRouter)
        $siteUrl  = config('app.url');
        $siteName = config('app.name');

        // 2. Ambil API Key dan model untuk OpenRouter dari config/services.php
        [
            'api_key' => $apiKey,
            'model'   => $model
        ] = config('services.openrouter');

        // 3. Ambil API Key dan nama model untuk Gemini (fallback)
        $geminiKey   = config('services.gemini.api_key');
        $geminiModel = config('services.gemini.model');

        // 4. Cari "knowledge" relevan (jika ada) menggunakan service vektor similarity
        if (empty($geminiKey)) {
            $relevantKnowledge = $this->openAIService->searchSimilarKnowledge($message, $bot, 3);
            Log::info('Menggunakan OpenAI untuk mencari knowledge relevan');
        } else {
            $geminiService = new \App\Services\GeminiService();
            $relevantKnowledge = $geminiService->searchSimilarKnowledge($message, $bot, 3);
            Log::info('Menggunakan Gemini untuk mencari knowledge relevan');
        }

        Log::info('Relevan Knowledge ditemukan:', [
            'knowledge_count' => $relevantKnowledge->count(),
            'message'         => $message,
        ]);

        // 5. Susun "system prompt" berdasar prompt bawaan bot + knowledge yang ditemukan
        $systemPrompt = $bot->prompt;

        if ($relevantKnowledge->isNotEmpty()) {
            // Jika ada knowledge, tambahkan ke system prompt
            $systemPrompt .= "\n\nGunakan informasi berikut untuk menjawab pertanyaan:\n\n";

            foreach ($relevantKnowledge as $knowledge) {
                $systemPrompt .= "{$knowledge['text']}\n\n";
            }
        } else {
            // Jika tidak ada knowledge, tambahkan fallback
            $systemPrompt .= "\n\nTidak ada informasi spesifik yang ditemukan dalam basis pengetahuan. "
                . "Tawarkan untuk menghubungkan dengan staf yang dapat membantu lebih lanjut.";
        }

        // 6. Bangun array $messages yang akan dikirim ke model:
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ...$chatHistory
                ->map(function ($ch) {
                    return [
                        ['role' => 'user',      'content' => $ch->message],
                        ['role' => 'assistant', 'content' => $ch->response],
                    ];
                })
                ->flatten(1)
                ->toArray(),
            ['role' => 'user', 'content' => $message],
        ];

        try {
            // =====================================================================
            // 7. Jika OPENROUTER_API_KEY tersedia, kirim ke OpenRouter
            // =====================================================================
            if (!empty($apiKey)) {

                // 7.1. Kirim request POST ke OpenRouter
                $openRouterResponse = Http::withHeaders([
                    'Authorization' => "Bearer {$apiKey}",
                    'HTTP-Referer'  => $siteUrl,
                    'X-Title'       => $siteName,
                    'Content-Type'  => 'application/json',
                ])->post('https://openrouter.ai/api/v1/chat/completions', [
                    'model'    => $model,
                    'messages' => $messages,
                ])->json();

                // 7.2. Log hasil request dan response (untuk debugging)
                Log::info('OpenRouter Request dan Response:', [
                    'model'    => $model,
                    'messages' => $messages,
                    'response' => $openRouterResponse,
                ]);

                // 7.3. Periksa apakah response sesuai struktur yang diharapkan
                if (
                    isset($openRouterResponse['choices'])
                    && is_array($openRouterResponse['choices'])
                    && isset($openRouterResponse['choices'][0]['message']['content'])
                ) {
                    // Ambil konten jawaban mentah
                    $rawContent = $openRouterResponse['choices'][0]['message']['content'];
                } else {
                    // Jika struktur tak sesuai, lempar exception
                    throw new \Exception('Response OpenRouter tidak berisi field choices[0].message.content');
                }
            }
            // =====================================================================
            // 8. Jika OPENROUTER_API_KEY kosong tapi GEMINI_API_KEY tersedia, pakai Gemini
            // =====================================================================
            elseif (empty($apiKey) && !empty($geminiKey)) {

                // 8.1. Cari "prompt user" terakhir dari array $messages
                $userPrompt = '';
                foreach (array_reverse($messages) as $msg) {
                    if (isset($msg['role']) && $msg['role'] === 'user' && !empty($msg['content'])) {
                        $userPrompt = $msg['content'];
                        break;
                    }
                }
                // Jika tidak ada "user" sama sekali, pakai pesan terakhir apa pun
                if (empty($userPrompt) && !empty($messages)) {
                    $lastMsg    = end($messages);
                    $userPrompt = $lastMsg['content'] ?? '';
                }

                // 8.2. Susun payload JSON
                $geminiPayload = [
                    'system_instruction' => [
                        'parts' => [
                            ['text' => $systemPrompt]
                        ]
                    ],
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => $userPrompt]
                            ]
                        ]
                    ]
                ];

                // 8.3. Bentuk URL endpoint Gemini
                $geminiUrl = "https://generativelanguage.googleapis.com/v1beta/models/"
                    . $geminiModel
                    . ":generateContent?key={$geminiKey}";

                // 8.4. Kirim request POST ke Gemini
                $geminiResponse = Http::withHeaders([
                    'Content-Type' => 'application/json',
                ])->post($geminiUrl, $geminiPayload)->json();

                // 8.5. Log request dan response (untuk debugging)
                Log::info('Gemini Request dan Response:', [
                    'url'      => $geminiUrl,
                    'payload'  => $geminiPayload,
                    'response' => $geminiResponse,
                ]);

                if (
                    isset($geminiResponse['candidates'])
                    && is_array($geminiResponse['candidates'])
                    && isset($geminiResponse['candidates'][0]['content'])
                    && isset($geminiResponse['candidates'][0]['content']['parts'])
                    && is_array($geminiResponse['candidates'][0]['content']['parts'])
                    && isset($geminiResponse['candidates'][0]['content']['parts'][0]['text'])
                ) {
                    $rawContent = $geminiResponse['candidates'][0]['content']['parts'][0]['text'];
                } else {
                    throw new \Exception('Response Gemini tidak berisi field candidates[0].content.parts[0].text');
                }
            }
            // =====================================================================
            // 9. Jika kedua API Key kosong → lempar exception
            // =====================================================================
            else {
                throw new \Exception('Kedua API key tidak ditemukan: OPENROUTER_API_KEY maupun GEMINI_API_KEY kosong.');
            }

            // =====================================================================
            // 10. Setelah dapat $rawContent (hasil teks mentah), konversi ke format WhatsApp
            //     Asumsi: fungsi convertMarkdownToWhatsApp sudah tersedia di kelas ini.
            // =====================================================================
            $formattedResponse = $this->convertMarkdownToWhatsApp($rawContent);

            return $formattedResponse;
        } catch (\Exception $e) {
            // =====================================================================
            // 11. Jika terjadi error, log pesan error dan return false
            // =====================================================================
            Log::error('Failed to generate response: ' . $e->getMessage());
            return false;
        }
    }


    private function convertMarkdownToWhatsApp($text)
    {
        // Convert italic: *text* or _text_ to _text_
        $text = preg_replace('/(?<!\*)\*(?!\*)(\S+?)(?<!\*)\*(?!\*)|_(\S+?)_/', '_$1$2_', $text);

        // Convert bold: **text** or __text__ to *text*
        $text = preg_replace('/(\*\*|__)(.*?)\1/', '*$2*', $text);

        // Convert strikethrough: ~~text~~ to ~text~
        $text = preg_replace('/~~(.*?)~~/', '~$1~', $text);

        // Convert inline code: `text` to ```text```
        $text = preg_replace('/`([^`]+)`/', '```$1```', $text);

        // Convert bullet points: - text to • text
        $text = preg_replace('/^- /m', '• ', $text);

        // Convert links: [text](url) to text: url
        $text = preg_replace('/\[([^\]]+)\]\(([^\)]+)\)/', '$2', $text);

        // Convert headers: # text to text
        $text = preg_replace('/^#+\s+(.*)$/m', '*$1*', $text);

        return $text;
    }

    private function saveChatHistory($integrationId, $sender, $message, $response)
    {
        ChatHistory::create([
            'integration_id' => $integrationId,
            'sender' => $sender,
            'message' => $message,
            'response' => $response,
        ]);
    }
}
