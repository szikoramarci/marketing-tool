<x-layouts.app :title="$group['result_page']['title']">
    <article class="space-y-8">
        <header>
            <h1 class="text-2xl font-bold">{{ $group['result_page']['title'] }}</h1>
            <p class="mt-3 text-gray-700">{{ $group['result_page']['summary'] }}</p>
        </header>

        @if (! empty($group['result_page']['video_url']))
            <video controls class="w-full rounded-md" src="{{ $group['result_page']['video_url'] }}"></video>
        @endif

        @foreach ($group['result_page']['sections'] as $section)
            <section>
                <h2 class="text-lg font-semibold">{{ $section['title'] }}</h2>
                <p class="mt-2 text-gray-700">{{ $section['body'] }}</p>
            </section>
        @endforeach

        <section class="rounded-md border border-gray-200 bg-white p-6">
            <h2 class="text-lg font-semibold">{{ $group['result_page']['cta']['email_capture']['headline'] }}</h2>
            <p class="mt-2 text-gray-700">{{ $group['result_page']['cta']['email_capture']['body'] }}</p>
        </section>
    </article>
</x-layouts.app>
