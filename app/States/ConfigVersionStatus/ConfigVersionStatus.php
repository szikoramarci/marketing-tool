<?php

namespace App\States\ConfigVersionStatus;

use App\States\ConfigVersionStatus\Transitions\ActivateConfigVersion;
use App\States\ConfigVersionStatus\Transitions\ArchiveConfigVersion;
use App\States\ConfigVersionStatus\Transitions\PauseConfigVersion;
use Spatie\ModelStates\State;
use Spatie\ModelStates\StateConfig;

abstract class ConfigVersionStatus extends State
{
    public static function config(): StateConfig
    {
        return parent::config()
            ->default(Draft::class)
            ->allowTransition(Draft::class, Active::class, ActivateConfigVersion::class)
            ->allowTransition(Paused::class, Active::class, ActivateConfigVersion::class)
            ->allowTransition(Active::class, Paused::class, PauseConfigVersion::class)
            ->allowTransition(Active::class, Archived::class, ArchiveConfigVersion::class)
            ->allowTransition(Paused::class, Archived::class, ArchiveConfigVersion::class);
    }
}
