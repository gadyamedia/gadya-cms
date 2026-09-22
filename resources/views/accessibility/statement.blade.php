@extends(config('gadya-cms.blog.layout', 'layouts.app'))

@section('content')
    <section class="cms-statement">
        <header class="cms-statement__header">
            <h1 class="cms-statement__heading">Accessibility statement</h1>
            <p class="cms-statement__lede">{{ $statement['business'] }} wants this website to be usable by everyone, including people who use a screen reader, a keyboard alone, or a magnified screen.</p>
        </header>

        @unless ($checked)
            <p>This site has not yet been checked. The first check runs shortly, and this page will say what it found.</p>
        @else
            <h2>How conformant this site is</h2>
            <p>We aim at <strong>{{ $statement['target'] }}</strong>, the standard most accessibility law refers to.</p>
            <p>{{ $statement['conformance'] }}</p>

            <h2>How we know</h2>
            <p>{{ $statement['assessed'] }}</p>
            <p>
                {{ $statement['checked_pages'] }} {{ \Illuminate\Support\Str::plural('page', $statement['checked_pages']) }} were last checked
                @if ($statement['last_checked_at'])
                    on {{ $statement['last_checked_at']->format('j F Y') }}
                @endif
                @if ($statement['score'] !== null)
                    and scored {{ $statement['score'] }} out of 100 on the automated checks
                @endif
                @if ($statement['since'])
                    . This record begins {{ $statement['since']->format('j F Y') }}.
                @endif
            </p>

            <h2>What is still outstanding</h2>
            @if ($statement['outstanding']->isEmpty())
                <p>The automated checks currently find no failures. That is not the same as perfect: parts of {{ $statement['target'] }} can only be judged by a person, and we welcome being told about anything these checks cannot see.</p>
            @else
                <ul>
                    @foreach ($statement['outstanding'] as $issue)
                        <li>
                            <strong>{{ $issue['title'] }}</strong> - on {{ $issue['path'] === '/' ? 'the home page' : $issue['path'] }}.
                            {{ $issue['fixable'] ? 'Being corrected in the site\'s content.' : 'Being corrected in the site\'s code by Gadya Media.' }}
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($statement['remediated']->isNotEmpty())
                <h2>What has been put right</h2>
                <table class="cms-statement__table">
                    <thead><tr><th>What</th><th>Where</th><th>When</th></tr></thead>
                    <tbody>
                        @foreach ($statement['remediated']->take(50) as $entry)
                            <tr>
                                <td>{{ $entry['what'] }}</td>
                                <td>{{ $entry['where'] }}</td>
                                <td>{{ $entry['on']?->format('j M Y') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        @endunless

        <h2>Telling us about a problem</h2>
        <p>{{ config('gadya-cms.accessibility.pledge') }}</p>
        <ul>
            @if (! empty($statement['contact']['email']))
                <li>Email <a href="mailto:{{ $statement['contact']['email'] }}">{{ $statement['contact']['email'] }}</a></li>
            @endif
            @if (! empty($statement['contact']['telephone']))
                <li>Telephone <a href="tel:{{ $statement['contact']['telephone'] }}">{{ $statement['contact']['telephone'] }}</a></li>
            @endif
        </ul>

        <p class="cms-statement__meta">This statement is generated from this site's own record of checks and corrections, and is rewritten each time a check runs. Last updated {{ ($statement['last_checked_at'] ?? now())->format('j F Y') }}.</p>
    </section>
@endsection
