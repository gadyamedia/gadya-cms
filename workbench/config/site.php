<?php

/*
 * The demo site's document. Every shape the package handles appears once:
 * a home page with cards, plain pages, a gallery, a hidden page, a
 * scheduled page, a menu with a parent, and globals.
 */
return [
    'announcement' => 'Now booking summer parties across Springfield',
    'phone' => '(555) 010-2030',
    'address' => '12 Evergreen Terrace, Springfield',
    'footer' => ['tagline' => 'Bouncy castles, face painting and the best cake table in town.'],
    'nav' => [
        ['label' => 'Home', 'slug' => 'home'],
        ['label' => 'Parties', 'children' => [
            ['label' => 'Birthday parties', 'slug' => 'birthday-parties'],
            ['label' => 'School events', 'slug' => 'school-events'],
        ]],
        ['label' => 'Pricing', 'slug' => 'pricing'],
        ['label' => 'Blog', 'slug' => 'blog-index', 'side' => 'end'],
        ['label' => 'Contact', 'slug' => 'contact', 'side' => 'end', 'highlight' => true],
    ],
    'theme' => ['primary' => '#0f766e', 'secondary' => '#f97316'],
    'pages' => [
        'home' => [
            'title' => 'Children\'s parties in Springfield',
            'type' => 'home',
            'heading' => 'Parties your kids will talk about for years',
            'cta' => 'Check a date',
            'description' => 'We bring the bouncy castle, the games, the face paint and the calm. You bring the birthday child.',
            'hero_image' => 'hero.jpg',
            'seo' => ['meta_description' => 'Children\'s party hire in Springfield: bouncy castles, entertainers, face painting and full party packages.'],
            'sections' => [
                ['type' => 'cards', 'title' => 'What we do', 'subtitle' => 'Pick one, or all of it', 'items' => [
                    ['title' => 'Bouncy castles', 'text' => 'Six sizes, all cleaned between every party.', 'image' => 'castle.jpg'],
                    ['title' => 'Entertainers', 'text' => 'Magicians, clowns and a very patient dinosaur.', 'image' => 'entertainer.jpg'],
                    ['title' => 'Face painting', 'text' => 'Tigers, butterflies and whatever they saw on TV this week.', 'image' => 'facepaint.jpg'],
                ]],
                ['type' => 'gallery', 'title' => 'Last weekend', 'images' => ['party-1.jpg', 'party-2.jpg', 'party-3.jpg']],
            ],
        ],
        'birthday-parties' => [
            'title' => 'Birthday parties',
            'type' => 'content',
            'heading' => 'Birthday parties, start to finish',
            'description' => 'Two hours of entertainment, set-up and clear-up included, for up to thirty children.',
            'hero_image' => 'castle.jpg',
            'sections' => [
                ['type' => 'text-grid', 'title' => 'How it works', 'items' => [
                    ['title' => '1. Pick a date', 'text' => 'Weekends go first; book six weeks ahead in summer.'],
                    ['title' => '2. Pick a package', 'text' => 'Castle only, castle and entertainer, or the whole works.'],
                    ['title' => '3. We turn up', 'text' => 'Forty minutes before the first guest, and we leave it as we found it.'],
                ]],
            ],
        ],
        'school-events' => [
            'title' => 'School events',
            'type' => 'content',
            'heading' => 'Fetes, fun days and end-of-term parties',
            'description' => 'Everything a PTA needs for a fun day, delivered and staffed.',
            'sections' => [],
        ],
        'pricing' => [
            'title' => 'Pricing',
            'type' => 'content',
            'heading' => 'Simple prices, no surprises',
            'description' => 'Every package includes delivery within Springfield, set-up and clear-up.',
            'sections' => [
                ['type' => 'cards', 'title' => 'Packages', 'items' => [
                    ['title' => 'Castle only', 'text' => '$180 for four hours.'],
                    ['title' => 'Castle + entertainer', 'text' => '$390 for two hours of entertainment.'],
                    ['title' => 'The whole works', 'text' => '$650: castle, entertainer, face painter and party bags.'],
                ]],
            ],
        ],
        'blog-index' => [
            'title' => 'Blog',
            'type' => 'content',
            'heading' => 'Party ideas and news',
            'description' => 'Everything we have learned from a thousand birthdays.',
            'sections' => [],
        ],
        'contact' => [
            'title' => 'Contact',
            'type' => 'contact',
            'heading' => 'Tell us about the party',
            'description' => 'A date, a rough number of children and where. We reply the same day.',
            'sections' => [],
        ],
        'halloween-2024' => [
            'title' => 'Halloween 2024',
            'type' => 'content',
            'status' => 'archived',
            'heading' => 'Last year\'s Halloween party',
            'description' => 'Kept for the photos.',
            'sections' => [],
        ],
        'christmas' => [
            'title' => 'Christmas parties',
            'type' => 'content',
            'publish_at' => '2026-11-01T09:00:00+00:00',
            'heading' => 'Christmas parties',
            'description' => 'Bookings open in November.',
            'sections' => [],
        ],
    ],
];
