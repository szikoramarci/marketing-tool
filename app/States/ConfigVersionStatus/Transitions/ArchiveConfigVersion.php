<?php

namespace App\States\ConfigVersionStatus\Transitions;

use App\Models\ConfigVersion;
use App\States\ConfigVersionStatus\Archived;
use Spatie\ModelStates\Transition;

class ArchiveConfigVersion extends Transition
{
    public function __construct(
        private readonly ConfigVersion $configVersion,
    ) {}

    public function handle(): ConfigVersion
    {
        $this->configVersion->status = new Archived($this->configVersion);
        $this->configVersion->save();

        return $this->configVersion;
    }
}
