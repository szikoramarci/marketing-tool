<?php

use App\Contracts\GenerationRequest;
use App\Services\Generation\AnthropicFeedbackGenerator;
use Illuminate\Support\Facades\Http;

function fakeAnthropicResponse(string $sourceReference, string $instructions): array
{
    $fixture = file_get_contents(base_path('tests/Fixtures/anthropic/messages-response.json'));
    $fixture = str_replace(
        ['__SOURCE_REFERENCE__', '__INSTRUCTIONS__'],
        [$sourceReference, $instructions],
        $fixture,
    );

    return json_decode($fixture, associative: true);
}

it('sends the expected request shape and extracts the generated document', function () {
    config([
        'services.anthropic.api_key' => 'test-key',
        'services.anthropic.model' => 'claude-opus-5',
    ]);

    Http::fake([
        'api.anthropic.com/*' => Http::response(fakeAnthropicResponse('blogposzt', 'Készíts kvízt.')),
    ]);

    $result = (new AnthropicFeedbackGenerator)->generate(new GenerationRequest(
        sourceMaterial: 'Forrásanyag szövege.',
        instructions: 'Készíts kvízt.',
        sourceReference: 'blogposzt',
    ));

    expect($result->model)->toBe('claude-opus-5');

    $document = json_decode($result->documentJson);
    expect($document->generation_trace->source_reference)->toBe('blogposzt');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.anthropic.com/v1/messages'
            && $request->hasHeader('x-api-key', 'test-key')
            && $request->hasHeader('anthropic-version', '2023-06-01')
            && $request['model'] === 'claude-opus-5'
            && str_contains($request['system'], 'Quiz config version') // the schema's own "title", proving it's inlined
            && str_contains($request['messages'][0]['content'], 'Forrásanyag szövege.');
    });
});

it('throws a clear exception when the model refuses', function () {
    config(['services.anthropic.api_key' => 'test-key']);

    $refusal = fakeAnthropicResponse('x', 'y');
    $refusal['stop_reason'] = 'refusal';

    Http::fake(['api.anthropic.com/*' => Http::response($refusal)]);

    (new AnthropicFeedbackGenerator)->generate(new GenerationRequest(
        sourceMaterial: 'Forrás.',
        instructions: 'Utasítás.',
        sourceReference: 'forrás',
    ));
})->throws(RuntimeException::class);

it('throws a clear exception when no API key is configured', function () {
    config(['services.anthropic.api_key' => null]);

    (new AnthropicFeedbackGenerator)->generate(new GenerationRequest(
        sourceMaterial: 'Forrás.',
        instructions: 'Utasítás.',
        sourceReference: 'forrás',
    ));
})->throws(RuntimeException::class);
