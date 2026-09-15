<?php

/*
 * The document the fixture application ships with: small enough to read in
 * one go, but with every shape the package has to handle - a home page, a
 * plain page, a hidden page, a section with items, a menu with a child.
 */
return [
    'announcement' => 'Now booking summer parties',
    'phone' => '(555) 010-2030',
    'address' => '1 Example Street, Springfield',
    'nav' => [
        ['label' => 'Home', 'slug' => 'home'],
        ['label' => 'About', 'slug' => 'about'],
        ['label' => 'More', 'children' => [
            ['label' => 'Pricing', 'slug' => 'pricing'],
        ]],
    ],
    'theme' => ['primary' => '#9f12c7'],
    'pages' => [
        'home' => [
            'title' => 'Welcome',
            'type' => 'home',
            'heading' => 'Welcome to the site',
            'description' => 'A short introduction.',
            'hero_image' => 'hero.webp',
            'sections' => [
                ['type' => 'cards', 'title' => 'What we do', 'items' => [
                    ['title' => 'Parties', 'text' => 'We throw them.', 'image' => 'parties.webp'],
                ]],
            ],
        ],
        'about' => [
            'title' => 'About us',
            'type' => 'content',
            'heading' => 'Who we are',
            'description' => 'The people behind the site.',
        ],
        'pricing' => [
            'title' => 'Pricing',
            'type' => 'content',
            'heading' => 'What it costs',
        ],
        'old-offer' => [
            'title' => 'Old offer',
            'type' => 'content',
            'status' => 'archived',
            'heading' => 'This one is hidden',
        ],
    ],
];
