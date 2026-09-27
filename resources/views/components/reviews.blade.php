@props([
    'limit' => null,
    'minRating' => null,
    'heading' => 'What our customers say',
    'linkText' => 'Leave us a review',
])

@php
    /*
     * The business's Google reviews, fetched through the Gadya portal and
     * kept for twelve hours. Nothing at all is rendered when there are
     * none to show, so a template can place this unconditionally.
     *
     * No AggregateRating markup on purpose: Google ignores self-serving
     * review stars on a business's own site, and may count them against it.
     */
    $reviews = app(\Gadya\Cms\Portal\Reviews::class)->forDisplay(
        $limit === null ? null : (int) $limit,
        $minRating === null ? null : (int) $minRating,
    );
@endphp

@if ($reviews !== null)
    <section {{ $attributes->merge(['class' => 'cms-reviews']) }} aria-labelledby="cms-reviews-heading">
        @if ($heading)
            <h2 id="cms-reviews-heading" class="cms-reviews__heading">{{ $heading }}</h2>
        @endif

        @if ($reviews['rating'] !== null && $reviews['count'])
            <p class="cms-reviews__summary">
                <span class="cms-reviews__stars" aria-hidden="true">{{ str_repeat('★', (int) round($reviews['rating'])) }}{{ str_repeat('☆', 5 - (int) round($reviews['rating'])) }}</span>
                <span>Rated {{ number_format($reviews['rating'], 1) }} out of 5 from {{ number_format($reviews['count']) }} {{ \Illuminate\Support\Str::plural('review', $reviews['count']) }}</span>
            </p>
        @endif

        @if ($reviews['reviews'] !== [])
            <ul class="cms-reviews__list">
                @foreach ($reviews['reviews'] as $review)
                    <li class="cms-reviews__item">
                        <figure class="cms-reviews__review">
                            <p class="cms-reviews__rating">
                                <span class="cms-reviews__stars" aria-hidden="true">{{ str_repeat('★', $review['rating']) }}{{ str_repeat('☆', 5 - $review['rating']) }}</span>
                                <span class="cms-reviews__sr" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;">Rated {{ $review['rating'] }} out of 5</span>
                            </p>
                            <blockquote class="cms-reviews__text">{{ $review['text'] }}</blockquote>
                            <figcaption class="cms-reviews__author">
                                @if ($review['profile_photo_url'])
                                    <img class="cms-reviews__photo" src="{{ $review['profile_photo_url'] }}" alt="" width="32" height="32" loading="lazy" referrerpolicy="no-referrer">
                                @endif
                                <span>{{ $review['author'] }}</span>
                                @if ($review['relative_time'])
                                    <span class="cms-reviews__when">
                                        @if ($review['time'])
                                            <time datetime="{{ $review['time'] }}">{{ $review['relative_time'] }}</time>
                                        @else
                                            {{ $review['relative_time'] }}
                                        @endif
                                    </span>
                                @endif
                            </figcaption>
                        </figure>
                    </li>
                @endforeach
            </ul>
        @endif

        <p class="cms-reviews__footer">
            <span class="cms-reviews__attribution">Reviews from Google</span>
            @if ($reviews['review_url'])
                <a class="cms-reviews__link" href="{{ $reviews['review_url'] }}" target="_blank" rel="noopener">{{ $linkText }}<span style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;"> on Google (opens in a new tab)</span></a>
            @endif
        </p>
    </section>
@endif
