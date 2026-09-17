<?php

use Gadya\Cms\Content\SlugPagePaths;

return [
    /*
     * The Filament panel the CMS registers its resources and pages on.
     */
    'panel' => 'admin',

    /*
     * The site key resolved for every request. The package is multi-site
     * capable at the storage layer, but a single-site install never has to
     * think about it.
     */
    'default_site' => env('GADYA_CMS_SITE', 'default'),

    /*
     * The config key holding the document the application ships with, which
     * a fresh install is seeded from and the live site falls back to.
     */
    'document' => 'site',

    /*
     * The gate that guards every CMS screen and every live-editor write.
     */
    'gate' => 'manage-content',

    /*
     * Where the live editor is mounted on the public site.
     */
    'editor' => [
        'prefix' => 'cms',
        'lock_ttl_minutes' => 15,
    ],

    /*
     * How long a preview link stays good for.
     */
    'preview' => [
        'expires_hours' => 72,
    ],

    /*
     * How many published revisions are retained before the oldest are pruned.
     */
    'revisions' => [
        'keep' => 30,
    ],

    'media' => [
        'disk' => env('GADYA_CMS_MEDIA_DISK', 'public'),
        'staging_disk' => env('GADYA_CMS_STAGING_DISK', 'local'),
        'directory' => 'site-media',
        'legacy_directory' => 'images/site',
        'max_edge' => 2400,
        'thumbnail_edge' => 400,
        'quality' => 82,
        'thumbnail_quality' => 78,
        'max_kilobytes' => 15360,
    ],

    /*
     * The paths inside the site document the live editor may write to, and
     * the kind of editor each one gets. A path not listed here can never be
     * written by an inline edit, whatever the browser sends.
     */
    'editable_fields' => [
        'pages.*.title' => 'text',
        'pages.*.heading' => 'text',
        'pages.*.description' => 'multiline',
        'pages.*.hero_image' => 'image',
        'pages.*.cta' => 'text',
        'pages.*.promo.heading' => 'text',
        'pages.*.promo.text' => 'multiline',
        'pages.*.sections.*.title' => 'text',
        'pages.*.sections.*.subtitle' => 'text',
        'pages.*.sections.*.heading' => 'text',
        'pages.*.sections.*.text' => 'multiline',
        'pages.*.sections.*.items.*.title' => 'text',
        'pages.*.sections.*.items.*.text' => 'multiline',
        'pages.*.sections.*.items.*.image' => 'image',
        'pages.*.sections.*.images.*' => 'image',
        'announcement' => 'text',
        'phone' => 'text',
        'address' => 'text',
        'contact_locations.*.name' => 'text',
        'contact_locations.*.address' => 'text',
        'contact_locations.*.map_query' => 'text',
    ],

    /*
     * Words and pictures that appear on every page: each is a top-level
     * path in the site document, edited under Appearance → Everywhere and
     * on the page itself through @editableGlobal. Types: text, textarea,
     * image. Group them however reads best.
     */
    'globals' => [
        'announcement' => ['label' => 'Announcement bar', 'type' => 'text', 'max' => 120, 'required' => true, 'group' => 'Top of every page'],
        'phone' => ['label' => 'Phone number', 'type' => 'text', 'max' => 40, 'required' => true, 'group' => 'Contact details'],
        'address' => ['label' => 'Address', 'type' => 'text', 'max' => 255, 'group' => 'Contact details'],
    ],

    /*
     * Where a top-level menu item sits when the document does not say.
     * Only applies until the client saves the menu, after which every item
     * carries its own answer.
     */
    'navigation' => [
        /*
         * The page type whose entries in the `locations` map are nested
         * under it in the menu. Null for a site with no such page.
         */
        'locations_type' => 'locations',
        'default_end_slugs' => [],
        'default_highlight_slugs' => [],
    ],

    /*
     * Section types the client may add to a page, and the slugs she may
     * never take because a real route already owns them.
     */
    'pages' => [
        /*
         * The slug of the page served at the site root.
         */
        'home_slug' => 'home',

        /*
         * Where a page lives on the public site. The default answers "/" for
         * the home page and "/slug" for everything else; a site that nests
         * some pages under a prefix names a class implementing
         * Gadya\Cms\Contracts\ResolvesPagePaths here.
         */
        'paths' => SlugPagePaths::class,

        /*
         * The fields at the top of every page's edit screen, in order. Each
         * is `text`, `textarea` or `image`, with an optional label and
         * length. These are structure; the words themselves are edited on
         * the page.
         */
        'content_fields' => [
            'heading' => ['label' => 'Heading', 'type' => 'text', 'max' => 120],
            'cta' => ['label' => 'Button text', 'type' => 'text', 'max' => 60],
            'description' => ['label' => 'Description', 'type' => 'textarea', 'rows' => 4],
            'hero_image' => ['label' => 'Main photo', 'type' => 'image'],
        ],

        'section_types' => ['cards', 'text-grid', 'gallery', 'menu', 'application'],
        'creatable_types' => ['content', 'legal'],
        'reserved_slugs' => ['admin', 'cms', 'up', 'storage', 'livewire', 'blog', 'home'],

        /*
         * Single-segment URIs that already belong to a real route. The public
         * page route must not match these or it would shadow them. Multi-segment
         * prefixes are absent deliberately: they cannot collide with a
         * one-segment page address.
         */
        'route_excluded_slugs' => ['admin', 'cms', 'up', 'storage', 'livewire'],
    ],

    /*
     * The curated type choices offered on the Look & Feel screen. Keeping
     * the list short is the point: the client cannot make the site
     * unreadable, and every option is a font the site already loads well.
     */
    'fonts' => [
        'display' => [
            'Yeseva One' => ['family' => 'Yeseva One', 'fallback' => 'Georgia, serif', 'bunny' => 'yeseva-one', 'weights' => [400]],
            'Playfair Display' => ['family' => 'Playfair Display', 'fallback' => 'Georgia, serif', 'bunny' => 'playfair-display', 'weights' => [400, 700]],
            'Fredoka' => ['family' => 'Fredoka', 'fallback' => 'ui-sans-serif, sans-serif', 'bunny' => 'fredoka', 'weights' => [400, 600]],
            'Baloo 2' => ['family' => 'Baloo 2', 'fallback' => 'ui-sans-serif, sans-serif', 'bunny' => 'baloo-2', 'weights' => [400, 700]],
            'Bungee' => ['family' => 'Bungee', 'fallback' => 'ui-sans-serif, sans-serif', 'bunny' => 'bungee', 'weights' => [400]],
            'Lobster' => ['family' => 'Lobster', 'fallback' => 'cursive', 'bunny' => 'lobster', 'weights' => [400]],
        ],
        'sans' => [
            'Lato' => ['family' => 'Lato', 'fallback' => 'ui-sans-serif, system-ui, sans-serif', 'bunny' => 'lato', 'weights' => [300, 400, 700, 900]],
            'Nunito' => ['family' => 'Nunito', 'fallback' => 'ui-sans-serif, system-ui, sans-serif', 'bunny' => 'nunito', 'weights' => [400, 700, 900]],
            'Poppins' => ['family' => 'Poppins', 'fallback' => 'ui-sans-serif, system-ui, sans-serif', 'bunny' => 'poppins', 'weights' => [400, 600, 700]],
            'Inter' => ['family' => 'Inter', 'fallback' => 'ui-sans-serif, system-ui, sans-serif', 'bunny' => 'inter', 'weights' => [400, 600, 700]],
            'Quicksand' => ['family' => 'Quicksand', 'fallback' => 'ui-sans-serif, system-ui, sans-serif', 'bunny' => 'quicksand', 'weights' => [400, 600, 700]],
        ],
    ],

    /*
     * Who may work on the site, and what they are allowed to do.
     *
     * Roles are the application's own strings so the panel never has to
     * know about its enum; it only needs the labels to show and the one
     * role that must never be left with nobody in it.
     */
    'users' => [
        'gate' => 'manage-users',
        /*
         * Each role's label and what it may do. A role given as a plain
         * label may do everything but manage the team. Abilities: content,
         * articles, photos, enquiries, publish, settings - or '*'.
         */
        'roles' => [
            'contributor' => ['label' => 'Contributor', 'abilities' => ['articles', 'photos']],
            'editor' => ['label' => 'Editor', 'abilities' => ['content', 'articles', 'photos', 'enquiries', 'publish']],
            'admin' => ['label' => 'Administrator', 'abilities' => ['*']],
        ],
        'default_role' => 'editor',
        'admin_role' => 'admin',

        /* How long an invitation link stays good for. */
        'invitation_expires_hours' => 168,
    ],

    /*
     * First-party analytics. Counted on this server, from this site's own
     * traffic: no third-party script, no cookie, and no visitor's address
     * ever stored - only a daily-rotating hash of one, which counts people
     * once a day and cannot follow anyone beyond it.
     */
    'analytics' => [
        'enabled' => env('GADYA_CMS_ANALYTICS', true),

        /* How far back the dashboard keeps data before pruning it. */
        'retention_days' => 180,

        /* The window "on the site now" covers. */
        'live_minutes' => 5,

        /*
         * The only event names the public site may record. A page cannot
         * invent a metric, and nothing arbitrary reaches the database.
         */
        'events' => ['phone_click', 'cta_click', 'booking_start', 'lead_form_submit', 'directions_click'],

        /*
         * A choropleth for the countries visitors come from. Point this at
         * a world map SVG whose paths carry lowercase ISO country codes as
         * classes and the dashboard draws one; leave it null and it shows
         * the same figures as a list, which needs no asset at all.
         */
        'world_map' => null,
        /*
         * The live panel's websocket. Left to Laravel's own broadcasting
         * configuration: when a broadcaster is set up, the package points
         * Filament's Echo client at it so an application does not have to
         * repeat the connection details in a second config file.
         */
        'live' => [
            'enabled' => true,
        ],

        'skip_prefixes' => ['admin', 'cms', 'livewire', 'up', 'storage', 'build', 'vendor', '.well-known', 'booking', 'lead-forms'],
        'skip_paths' => ['robots.txt', 'sitemap.xml', 'favicon.ico'],
    ],

    /*
     * Articles. The panel manages them whenever the plugin has the blog
     * switched on; the public routes and templates are optional, for an
     * application that does not have its own.
     */
    'blog' => [
        'routes' => true,
        'prefix' => 'blog',
        'title' => 'Blog',
        'heading' => null,
        'description' => '',

        /* The layout the shipped templates extend; it must yield `content`. */
        'layout' => 'layouts.app',
        'per_page' => 12,
    ],

    /*
     * The forms the public site may post to. Only the fields listed are
     * kept, under the rules given; `notify` is who is emailed; the
     * `analytics_event` is what the dashboard counts it as (null for none).
     *
     * In a template:
     *
     *     <form method="POST" action="{{ route('gadya-cms.forms.store', 'contact') }}">
     *         @cmsForm('contact')
     *         ...
     *     </form>
     *     @cmsFormStatus('contact')
     */
    'forms' => [
        'honeypot' => 'website',
        'forms' => [
            'contact' => [
                'label' => 'Contact',
                'fields' => [
                    'name' => ['required', 'string', 'max:120'],
                    'email' => ['required', 'email', 'max:255'],
                    'phone' => ['nullable', 'string', 'max:40'],
                    'message' => ['required', 'string', 'max:5000'],
                ],
                'notify' => [],
                'success' => 'Thank you. We will be in touch soon.',
                'analytics_event' => 'lead_form_submit',
            ],
        ],
    ],

    /*
     * What search engines and link previews are told. The site name and
     * suffix decorate a page that has no snippet of its own; the sitemap
     * and robots file are generated from what is published, unless a
     * static file in public/ already answers.
     */
    'seo' => [
        'site_name' => null,
        'title_suffix' => '',
        'default_description' => '',
        'default_image' => null,
        'sitemap' => true,
        'sitemap_extra' => [],
        'robots' => true,
        'robots_disallow' => ['/admin', '/cms'],
    ],

    /*
     * Writing with AI. The service, model and key are chosen in the panel
     * and stored encrypted; this only says who may change them.
     */
    'ai' => [
        'gate' => 'manage-users',
    ],

    /*
     * Brand colours for the Filament panel. These mirror the palette the
     * client sees on the public site so the admin never feels like a
     * different product.
     */
    'brand' => [
        'name' => env('GADYA_CMS_BRAND', config('app.name')),

        /*
         * A filename in the photo library, or a path under public/. Null
         * falls back to the brand name as text.
         */
        'logo' => null,
        'logo_height' => '2.75rem',
        'logo_height_auth' => '5rem',

        'primary' => '#9f12c7',
        'secondary' => '#f54fa3',

        /*
         * The rest of the palette the sign-in screen is painted with, so the
         * client meets the same colours she sees on her own site.
         */
        'background' => '#fffdf3',
        'ink' => '#000000',
        'accent' => '#f0e56c',

        'fonts' => [
            'display' => 'Yeseva One',
            'body' => 'Lato',

            /*
             * A stylesheet that provides the two families above. Null skips
             * the request and leaves the panel on its default font.
             */
            'stylesheet' => 'https://fonts.bunny.net/css?family=lato:400,700,900|yeseva-one:400',
        ],
    ],
];
