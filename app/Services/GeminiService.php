<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiService
{
    protected $apiKey;
    protected $baseUrl;
    protected $model;

    public function __construct()
    {
        $this->apiKey = config('services.gemini.api_key');
        $this->model = 'gemini-embedding-exp-03-07';
        $this->baseUrl = "https://generativelanguage.googleapis.com/v1beta/models";

        if (! function_exists('fast_cosine_similarity')) {
            Log::warning('Vector search C extension not available. Using PHP implementation.');
        }
    }

    /**
     * Membuat embedding untuk satu string saja.
     * Jika $text adalah array, akan diarahkan ke createBatchEmbeddings().
     *
     * @param string|array $text
     * @return array        Array embedding (nilai numerik)
     * @throws \Exception
     */
    public function createEmbedding($text)
    {
        // Jika teks berupa array, artinya batch processing
        if (is_array($text)) {
            return $this->createBatchEmbeddings($text);
        }

        try {
            $payload = [
                'model'   => $this->model,
                'content' => [
                    'parts' => [
                        [
                            'text' => $text
                        ]
                    ]
                ]
            ];

            $url = "{$this->baseUrl}/{$this->model}:embedContent?key={$this->apiKey}";

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->post($url, $payload);

            if ($response->successful()) {
                return $response->json()['embedding']['values'] ?? [];
            }

            throw new \Exception('Gagal membuat embedding: ' . $response->body());
        } catch (\Exception $e) {
            Log::error('Error createEmbedding Gemini: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Membuat batch embeddings dari array teks.
     *
     * @param array $texts
     * @return array        Array embeddings (array of numeric arrays)
     * @throws \Exception
     */
    public function createBatchEmbeddings(array $texts)
    {
        try {
            $parts = array_map(function ($t) {
                return ['text' => $t];
            }, $texts);

            $payload = [
                'model'   => $this->model,
                'content' => [
                    'parts' => $parts
                ]
            ];

            $url = "{$this->baseUrl}/{$this->model}:embedContent?key={$this->apiKey}";

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->post($url, $payload);

            if ($response->successful()) {
                $allEmbeddings = $response->json()['embedding']['values'] ?? null;

                if (! is_array($allEmbeddings)) {
                    throw new \Exception('Format response batch embedding tidak valid');
                }

                return $allEmbeddings;
            }

            throw new \Exception('Gagal membuat batch embeddings: ' . $response->body());
        } catch (\Exception $e) {
            Log::error('Error createBatchEmbeddings Gemini: ' . $e->getMessage());
            throw $e;
        }
    }

    public function searchSimilarKnowledge($query, $bot, $limit = 3)
    {
        try {
            // Create embedding for the query
            $queryEmbedding = $this->createEmbedding($query);
            Log::info('Query embedding created', [
                'query' => $query,
                'embedding' => $queryEmbedding,
            ]);
            // Get only necessary vectors with optimized query
            $knowledgeVectors = $bot->knowledge()
                ->where('status', 'completed')
                ->with(['vectors:id,knowledge_id,text,vector']) // Select only needed fields
                ->get()
                ->flatMap(function ($knowledge) use ($queryEmbedding) {
                    return $knowledge->vectors->map(function ($vector) use ($queryEmbedding) {
                        return [
                            'text' => $vector->text,
                            'similarity' => $this->calculateSimilarity($queryEmbedding, $vector->vector),
                        ];
                    });
                });

            // Sort and limit results
            return $knowledgeVectors->sortByDesc('similarity')
                ->take($limit)
                ->values();

        } catch (\Exception $e) {
            Log::error('Error searching similar knowledge: '.$e->getMessage());

            return collect();
        }
    }

    private function calculateSimilarity($vector1, $vector2)
    {
        if (function_exists('fast_cosine_similarity')) {
            return fast_cosine_similarity($vector1, $vector2);
        }

        // Fallback to PHP implementation
        return $this->cosineSimilarity($vector1, $vector2);
    }

    public function cosineSimilarity($vector1, $vector2)
    {
        $dotProduct = 0;
        $norm1 = 0;
        $norm2 = 0;

        foreach ($vector1 as $i => $value) {
            $dotProduct += $value * $vector2[$i];
            $norm1 += $value * $value;
            $norm2 += $vector2[$i] * $vector2[$i];
        }

        $norm1 = sqrt($norm1);
        $norm2 = sqrt($norm2);

        return $dotProduct / ($norm1 * $norm2);
    }
}
