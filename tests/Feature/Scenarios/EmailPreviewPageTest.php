<?php

use App\Filament\Resources\ConfigVersions\Pages\PreviewEmails;
use App\Models\Campaign;
use App\Models\ConfigVersion;
use App\Models\User;
use Livewire\Livewire;
use Tests\Support\QuizConfigFixture;

it('shows resolved email content for a config version', function () {
    $this->actingAs(User::factory()->create());

    $campaign = Campaign::factory()->create();
    $document = QuizConfigFixture::toObject(QuizConfigFixture::example());
    $document->status = 'draft';
    $configVersion = ConfigVersion::createFromDocument($campaign, $document, 'admin@example.com');

    Livewire::test(PreviewEmails::class, ['record' => $configVersion->id])
        ->assertOk()
        ->assertSee('seq_group_stress')
        ->assertSee('A kvíz eredményed részletesen')
        ->assertSee('legrelevánsabb modul tartalma')
        ->assertSee('Szeretnél beszélgetni róla?')
        ->assertSee('Három dolog, ami azonnal csökkenti a stresszt');
});
