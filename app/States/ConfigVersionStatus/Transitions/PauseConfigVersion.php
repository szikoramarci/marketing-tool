<?php

namespace App\States\ConfigVersionStatus\Transitions;

use App\Models\ConfigVersion;
use App\States\ConfigVersionStatus\Paused;
use Spatie\ModelStates\Transition;

class PauseConfigVersion extends Transition
{
    public function __construct(
        private readonly ConfigVersion $configVersion,
    ) {}

    public function handle(): ConfigVersion
    {
        $this->configVersion->status = new Paused($this->configVersion);
        $this->configVersion->save();

        return $this->configVersion;
    }
}
