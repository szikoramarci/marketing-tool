<?php

namespace Database\Factories;

use App\Models\ConfigVersion;
use App\Models\QuizSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuizSession>
 */
class QuizSessionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Created eagerly (rather than via a lazy factory reference) so campaign_id and
        // config_version_id are guaranteed to agree on the same campaign.
        $configVersion = ConfigVersion::factory()->create();

        return [
            'campaign_id' => $configVersion->campaign_id,
            'config_version_id' => $configVersion->id,
            'answers' => ['q1' => 'q1_o1'],
            'is_preview' => false,
            'is_bot_suspected' => false,
            'started_at' => now(),
        ];
    }
}
