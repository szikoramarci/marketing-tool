<x-layouts.app>
    <form
        method="POST"
        action="{{ route('quiz.submit', $quizSession) }}"
        x-data="{ step: 0, last: {{ count($config->questions) - 1 }} }"
        class="space-y-8"
    >
        @csrf

        @if ($errors->any())
            <div class="rounded-md border border-red-300 bg-red-50 p-4 text-sm text-red-800">
                <p class="font-medium">Kérjük, javítsd az alábbiakat:</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    @foreach ($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @foreach ($config->questions as $index => $question)
            <fieldset x-show="step === {{ $index }}">
                <legend class="text-lg font-semibold">{{ $question->text }}</legend>

                @if (! empty($question->help_text))
                    <p class="mt-1 text-sm text-gray-500">{{ $question->help_text }}</p>
                @endif

                <div class="mt-4 space-y-2">
                    @foreach ($question->options as $option)
                        <label class="flex items-center gap-3 rounded-md border border-gray-200 px-4 py-3 has-checked:border-gray-900">
                            @if ($question->input_type === 'multi')
                                <input
                                    type="checkbox"
                                    name="answers[{{ $question->id }}][]"
                                    value="{{ $option->id }}"
                                    @checked(in_array($option->id, old('answers.'.$question->id, [])))
                                >
                            @else
                                <input
                                    type="radio"
                                    name="answers[{{ $question->id }}]"
                                    value="{{ $option->id }}"
                                    @checked(old('answers.'.$question->id) === $option->id)
                                >
                            @endif
                            <span>{{ $option->text }}</span>
                        </label>
                    @endforeach
                </div>

                <div class="mt-6 flex justify-between">
                    <button
                        type="button"
                        x-show="step > 0"
                        @click="step--"
                        class="rounded-md border border-gray-300 px-4 py-2 text-sm"
                    >Vissza</button>
                    <span x-show="step === 0"></span>

                    <button
                        type="button"
                        x-show="step < last"
                        @click="step++"
                        class="rounded-md bg-gray-900 px-4 py-2 text-sm text-white"
                    >Tovább</button>

                    <button
                        type="submit"
                        x-show="step === last"
                        class="rounded-md bg-gray-900 px-4 py-2 text-sm text-white"
                    >Beküldés</button>
                </div>
            </fieldset>
        @endforeach
    </form>
</x-layouts.app>
