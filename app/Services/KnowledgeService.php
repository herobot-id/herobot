<?php

namespace App\Services;

use App\Events\KnowledgeUpdated;
use App\Models\Knowledge;
use Illuminate\Support\Facades\Log;

class KnowledgeService
{
    protected $openAIService;

    public function __construct(OpenAIService $openAIService)
    {
        $this->openAIService = $openAIService;
    }

    public function indexKnowledge(Knowledge $knowledge)
    {
        try {
            // Update status to indexing
            $knowledge->update(['status' => 'indexing']);

            // Split the text into chunks
            $chunks = $this->openAIService->splitTextIntoChunks($knowledge->text);

            // Delete existing vectors
            $knowledge->vectors()->delete();

            // Extract all texts for batch processing
            $texts = array_column($chunks, 'content');

            $openAiKey  = config('services.openai.api_key');
            $geminiKey  = config('services.gemini.api_key');
            // Create embeddings for all chunks at once
            if (!empty($openAiKey)) {
                // -------------------------------------------------------------
                // 5a. Jika OpenAI key tersedia, gunakan OpenAI untuk membuat embedding
                // -------------------------------------------------------------
                $vectors = $this->openAIService->createEmbedding($texts);
            } elseif (empty($openAiKey) && !empty($geminiKey)) {
                $geminiServices = new GeminiService();
                $vectors = $geminiServices->createEmbedding($texts);
                Log::info('Menggunakan Gemini untuk membuat embedding', [
                    'knowledge_id' => $knowledge->id,
                    'chunk_count' => count($chunks),
                    'vector_count' => count($vectors),
                ]);
            } else {
                // -------------------------------------------------------------
                // 5c. Jika kedua‐duanya kosong → tidak bisa membuat embedding!
                // -------------------------------------------------------------
                throw new \Exception('Tidak ada API Key: buka OpenAI maupun Gemini tidak tersedia.');
            }

            // Create vector records for each chunk with its corresponding embedding
            foreach ($chunks as $index => $chunk) {
                $knowledge->vectors()->create([
                    'text' => $chunk['content'],
                    'vector' => $vectors[$index],
                ]);
            }

            // Update status to completed
            $knowledge->update(['status' => 'completed']);

            KnowledgeUpdated::dispatch($knowledge);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to index knowledge: ' . $e->getMessage());
            $knowledge->update(['status' => 'failed']);
            throw $e;
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
