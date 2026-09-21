<x-filament-panels::page>
    <div class="gadya-dash">
        @if ($this->writesForUs)
            <p class="gadya-dash__notice">The writing here is done by Gadya Media on your behalf - there is nothing for you to set up and no key to buy. Everything it writes goes into your draft first, so you always read it before the world does.</p>
        @endif

        @forelse ($this->failures as $failure)
            <div class="gadya-dash__card">
                <p class="gadya-dash__title">{{ $failure['title'] }}</p>
                <p class="gadya-dash__muted">
                    {{ $failure['what'] ?? $failure['description'] }}
                    <br>Found on <strong>{{ $failure['path'] === '/' ? 'the home page' : $failure['path'] }}</strong>.
                </p>

                @if ($failure['fixable'])
                    <p class="gadya-dash__muted">Gadya CMS can put this right for you - use the buttons at the top of this page.</p>
                @else
                    <p class="gadya-dash__muted">This one lives in the site's code. Gadya looks after it for you; you do not need to do anything.</p>
                @endif

                @if (! empty($failure['elements']))
                    <ul class="gadya-dash__list">
                        @foreach (array_slice($failure['elements'], 0, 5) as $element)
                            <li><code>{{ \Illuminate\Support\Str::limit($element['selector'] ?? '', 120) }}</code></li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @empty
            <div class="gadya-dash__card">
                <p class="gadya-dash__title">Nothing to report</p>
                <p class="gadya-dash__muted">The last check found nothing wrong. Checks run on their own each week; you can also run one from Settings → Search &amp; speed.</p>
            </div>
        @endforelse

        @if ($this->recentFixes->isNotEmpty())
            <div class="gadya-dash__card">
                <p class="gadya-dash__title">What Gadya CMS fixed for you</p>
                <ul class="gadya-dash__list">
                    @foreach ($this->recentFixes as $fix)
                        <li>
                            <strong>{{ $fix->says() }}</strong> - {{ $fix->subject }}
                            <br><span class="gadya-dash__muted">"{{ \Illuminate\Support\Str::limit($fix->after, 160) }}" · {{ $fix->created_at?->diffForHumans() }}</span>
                        </li>
                    @endforeach
                </ul>
                <p class="gadya-dash__muted">These are your words now - change any of them wherever you edit that page or photo.</p>
            </div>
        @endif
    </div>
</x-filament-panels::page>
