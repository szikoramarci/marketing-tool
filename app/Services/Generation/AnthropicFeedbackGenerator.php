<?php

namespace App\Services\Generation;

use App\Contracts\FeedbackGenerator;
use App\Contracts\GenerationRequest;
use App\Contracts\GenerationResult;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AnthropicFeedbackGenerator implements FeedbackGenerator
{
    public function generate(GenerationRequest $request): GenerationResult
    {
        $model = config('services.anthropic.model');
        $apiKey = config('services.anthropic.api_key');

        if (blank($apiKey)) {
            throw new RuntimeException('ANTHROPIC_API_KEY is not configured.');
        }

        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])
            ->timeout(120)
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => $model,
                'max_tokens' => 16000,
                'system' => $this->systemPrompt(),
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => "Forrásanyag ({$request->sourceReference}):\n{$request->sourceMaterial}\n\nUtasítás:\n{$request->instructions}",
                    ],
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException("Anthropic API hiba ({$response->status()}): {$response->body()}");
        }

        $body = $response->json();

        if (($body['stop_reason'] ?? null) === 'refusal') {
            throw new RuntimeException('A modell elutasította a kérést.');
        }

        $textBlock = collect($body['content'] ?? [])->firstWhere('type', 'text');

        if ($textBlock === null) {
            throw new RuntimeException('A válasz nem tartalmazott szöveges tartalmat.');
        }

        return new GenerationResult(
            documentJson: trim($textBlock['text']),
            model: $model,
        );
    }

    private function systemPrompt(): string
    {
        $schema = file_get_contents(app_path('QuizConfig/Schema/quiz-config.schema.json'));

        return <<<PROMPT
            Egy online konzultációs praxis kvíz-konfigurációját kell elkészítened a megadott
            forrásanyag és utasítás alapján, a következő JSON séma szerint. A válaszod
            KIZÁRÓLAG egy érvényes JSON dokumentum legyen — se markdown code fence, se
            magyarázó szöveg előtte/utána.

            A dokumentumnak tartalmaznia kell minden mezőt, amit a séma megkövetel, beleértve
            a metaadatokat is (version_id, content_hash, created_at, created_by, status —
            ezekhez adj tetszőleges helyőrző értéket, felül lesznek írva mentéskor). A
            "generation_trace" mezőt töltsd ki a kapott forrás-hivatkozással, utasítással és
            a modell nevével.

            NYELVI KÖVETELMÉNY — ez kritikus: "locale" mindig "hu", ÉS minden, a kitöltő
            vagy a lead számára valaha látható/olvasható szöveg is legyen magyar nyelvű.
            Ide tartozik: minden kérdés "text" és "help_text" mezője; minden válaszopció
            "text" mezője; minden eredményoldal "title", "summary", a "sections" tömb
            "title"/"body" párjai, és a "cta.email_capture" "headline"/"body" mezői; minden
            email "subject" és "body"/"content" mezője (fix lépések, "shared_blocks",
            "module_content"). A "labels"/"evaluation_groups"/"modules" "name" mezői belső
            adminisztrátori címkék (a kitöltő sosem látja őket), ezeket is írd magyarul,
            hacsak az utasítás mást nem kér.

            Amit VÁLTOZATLANUL, angolul kell hagyni: minden mező- és kulcsnév (pl. "text",
            "input_type"), minden stabil azonosító ("id" értékek, pl. "q1", "q1_o1",
            "label_a", "group_fallback", "module_a"), és minden zárt enum-érték a séma
            szerint (pl. "input_type": "single"|"multi", "axis": "content"|"readiness",
            "kind": "fixed"|"module_driven"|"shared_block", "operator": "gte" stb.,
            "consultation_offer_emphasis": "featured"|"subdued"|"hidden") — ezeket SOHA ne
            fordítsd le, pontosan a séma által megengedett formában szerepeljenek.

            A séma:

            {$schema}
            PROMPT;
    }
}
