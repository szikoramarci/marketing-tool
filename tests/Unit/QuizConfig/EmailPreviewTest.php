<?php

use App\QuizConfig\EmailPreview;
use Tests\Support\QuizConfigFixture;

it('resolves fixed, shared_block, and module_driven steps, plus per-module content', function () {
    $config = QuizConfigFixture::toObject(QuizConfigFixture::example());

    $preview = EmailPreview::resolve($config);

    $groupStressSequence = collect($preview['sequences'])->firstWhere('id', 'seq_group_stress');
    expect($groupStressSequence['applies_to_groups'])->toBe(['group_stress']);

    $steps = $groupStressSequence['steps'];
    expect($steps[0]['kind'])->toBe('fixed')
        ->and($steps[0]['subject'])->toBe('A kvíz eredményed részletesen')
        ->and($steps[1]['kind'])->toBe('module_driven')
        ->and($steps[1]['module_rank'])->toBe(1)
        ->and($steps[3]['kind'])->toBe('shared_block')
        ->and($steps[3]['subject'])->toBe('Szeretnél beszélgetni róla?')
        ->and($steps[3]['body'])->toBe('Ha szívesen átbeszélnéd a helyzeted, foglalj egy ingyenes konzultációt.');

    expect($preview['default_sequence']['id'])->toBe('seq_default');

    $moduleA = collect($preview['modules'])->firstWhere('id', 'module_stress_basics');
    expect($moduleA['subject'])->toBe('Három dolog, ami azonnal csökkenti a stresszt');
});
