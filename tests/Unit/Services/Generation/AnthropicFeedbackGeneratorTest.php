<?php

use App\Contracts\GenerationRequest;
use App\Services\Generation\AnthropicFeedbackGenerator;
use Illuminate\Support\Facades\Http;

function fakeAnthropicStream(string $sourceReference, string $instructions, string $stopReason = 'end_turn'): string
{
    $fixture = file_get_contents(base_path('tests/Fixtures/anthropic/messages-stream-response.txt'));

    return str_replace(
        ['__SOURCE_REFERENCE__', '__INSTRUCTIONS__', '__STOP_REASON__'],
        [$sourceReference, $instructions, $stopReason],
        $fixture,
    );
}

it('sends a streaming request and extracts the generated document', function () {
    config([
        'services.anthropic.api_key' => 'test-key',
        'services.anthropic.model' => 'claude-opus-5',
    ]);

    Http::fake([
        'api.anthropic.com/*' => Http::response(fakeAnthropicStream('blogposzt', 'Készíts kvízt.')),
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
            && $request['stream'] === true
            && str_contains($request['system'], 'Quiz config version') // the schema's own "title", proving it's inlined
            && str_contains($request['messages'][0]['content'], 'Forrásanyag szövege.');
    });
});

it('throws a clear exception when the model refuses', function () {
    config(['services.anthropic.api_key' => 'test-key']);

    Http::fake([
        'api.anthropic.com/*' => Http::response(fakeAnthropicStream('x', 'y', stopReason: 'refusal')),
    ]);

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

it('throws a clear exception on a mid-stream error event', function () {
    config(['services.anthropic.api_key' => 'test-key']);

    $sse = <<<'SSE'
        event: message_start
        data: {"type":"message_start","message":{"id":"msg_01","type":"message","role":"assistant","model":"claude-opus-5","content":[],"stop_reason":null,"usage":{"input_tokens":10,"output_tokens":0}}}

        event: error
        data: {"type":"error","error":{"type":"overloaded_error","message":"Overloaded"}}

        SSE;

    Http::fake(['api.anthropic.com/*' => Http::response($sse)]);

    (new AnthropicFeedbackGenerator)->generate(new GenerationRequest(
        sourceMaterial: 'Forrás.',
        instructions: 'Utasítás.',
        sourceReference: 'forrás',
    ));
})->throws(RuntimeException::class);
