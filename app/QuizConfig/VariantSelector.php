<?php

namespace App\QuizConfig;

use App\Models\Campaign;
use App\Models\ConfigVersion;

class VariantSelector
{
    /**
     * Deterministically picks one of the campaign's active config versions, weighted by
     * traffic_weight. Same visitor token + campaign always yields the same variant; a new
     * token (new browser session) may land on a different one — an accepted tradeoff, see
     * spec section 6. Returns null if the campaign has no active versions.
     */
    public function select(Campaign $campaign, string $visitorToken): ?ConfigVersion
    {
        $versions = $campaign->configVersions()
            ->where('status', 'active')
            ->orderBy('id')
            ->get();

        if ($versions->isEmpty()) {
            return null;
        }

        $totalWeight = $versions->sum('traffic_weight');

        if ($totalWeight <= 0) {
            return $versions->first();
        }

        $point = crc32("{$visitorToken}|{$campaign->id}") % $totalWeight;

        $cumulative = 0;

        foreach ($versions as $version) {
            $cumulative += $version->traffic_weight;

            if ($point < $cumulative) {
                return $version;
            }
        }

        return $versions->last();
    }
}
