<?php

namespace App\States\ConfigVersionStatus\Transitions;

use App\Models\ConfigVersion;
use App\QuizConfig\Validation\ConfigValidator;
use App\QuizConfig\Validation\ConfigVersionNotValidException;
use App\States\ConfigVersionStatus\Active;
use Spatie\ModelStates\Transition;

class ActivateConfigVersion extends Transition
{
    public function __construct(
        private readonly ConfigVersion $configVersion,
    ) {}

    /**
     * @throws ConfigVersionNotValidException
     */
    public function handle(ConfigValidator $validator): ConfigVersion
    {
        $report = $validator->validate($this->configVersion->toConfigObject());

        if (! $report->isValid()) {
            throw new ConfigVersionNotValidException($report);
        }

        $this->configVersion->status = new Active($this->configVersion);
        $this->configVersion->save();

        return $this->configVersion;
    }
}
