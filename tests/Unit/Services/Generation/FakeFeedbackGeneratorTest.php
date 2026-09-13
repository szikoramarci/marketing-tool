<?php

use App\Contracts\GenerationRequest;
use App\QuizConfig\ConfigSchemaValidator;
use App\Services\Generation\FakeFeedbackGenerator;

it('produces a schema-valid document reflecting the request', function () {
    $request = new GenerationRequest(
        sourceMaterial: 'Egy rövid szöveg a forrásanyagról.',
        instructions: 'Készíts egy egyszerű kvízt.',
        sourceReference: 'blogposzt-2026',
    );

    $result = (new FakeFeedbackGenerator)->generate($request);

    $document = json_decode($result->documentJson);
    expect($document)->not->toBeNull()
        ->and($document->generation_trace->source_reference)->toBe('blogposzt-2026')
        ->and($document->generation_trace->instructions)->toBe('Készíts egy egyszerű kvízt.')
        ->and($document->locale)->toBe('hu');

    $validation = (new ConfigSchemaValidator)->validate($document);
    expect($validation->valid)->toBeTrue()
        ->and($validation->errors)->toBe([]);
});

it('is deterministic for the same request', function () {
    $this->freezeTime();

    $request = new GenerationRequest(
        sourceMaterial: 'Szöveg.',
        instructions: 'Utasítás.',
        sourceReference: 'forrás',
    );

    $generator = new FakeFeedbackGenerator;

    expect($generator->generate($request)->documentJson)->toBe($generator->generate($request)->documentJson);
});
