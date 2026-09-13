<x-filament-panels::page>
    @php($preview = $this->getPreview())

    <div class="space-y-8">
        @foreach ([...$preview['sequences'], $preview['default_sequence']] as $sequence)
            <section class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                <h2 class="text-base font-semibold">
                    {{ $sequence['id'] }}
                    @if ($sequence['applies_to_groups'])
                        <span class="font-normal text-gray-500">— csoportok: {{ implode(', ', $sequence['applies_to_groups']) }}</span>
                    @else
                        <span class="font-normal text-gray-500">— alapértelmezett (minden más csoportra)</span>
                    @endif
                </h2>

                <div class="mt-4 space-y-4">
                    @foreach ($sequence['steps'] as $index => $step)
                        <div class="rounded-md border border-gray-100 p-3 dark:border-gray-800">
                            <p class="text-xs text-gray-500">
                                #{{ $index + 1 }} · küldés {{ $step['delay_hours'] }} óra múlva · {{ $step['kind'] }}
                            </p>

                            @if ($step['kind'] === 'module_driven')
                                <p class="mt-1 text-sm italic text-gray-500">
                                    A(z) {{ $step['module_rank'] }}. legrelevánsabb modul tartalma (lásd lent, „Modultartalmak").
                                </p>
                            @else
                                <p class="mt-1 text-sm font-medium">{{ $step['subject'] }}</p>
                                <p class="mt-1 whitespace-pre-line text-sm text-gray-700 dark:text-gray-300">{{ $step['body'] }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>
        @endforeach

        <section class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
            <h2 class="text-base font-semibold">Modultartalmak</h2>
            <p class="text-xs text-gray-500">Ezeket a kitöltő sosem látja közvetlenül — csak a fenti "module_driven" lépéseken keresztül, a rangsor alapján.</p>

            <div class="mt-4 space-y-4">
                @foreach ($preview['modules'] as $module)
                    <div class="rounded-md border border-gray-100 p-3 dark:border-gray-800">
                        <p class="text-xs text-gray-500">{{ $module['id'] }} ({{ $module['name'] }})</p>
                        <p class="mt-1 text-sm font-medium">{{ $module['subject'] }}</p>
                        <p class="mt-1 whitespace-pre-line text-sm text-gray-700 dark:text-gray-300">{{ $module['body'] }}</p>
                    </div>
                @endforeach
            </div>
        </section>
    </div>
</x-filament-panels::page>
