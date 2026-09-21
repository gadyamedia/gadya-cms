# Roles and abilities

Roles stay your application's own strings; the package only needs to know what each one may do:

```php
'users' => [
    'roles' => [
        'contributor' => ['label' => 'Contributor', 'abilities' => ['articles', 'photos']],
        'editor' => ['label' => 'Editor', 'abilities' => ['content', 'articles', 'photos', 'enquiries', 'publish']],
        'admin' => ['label' => 'Administrator', 'abilities' => ['*']],
    ],
    'default_role' => 'editor',
    'admin_role' => 'admin',
],
```

| Ability | Unlocks |
| --- | --- |
| `content` | Pages, the menu, Look & feel, Everywhere, Locations, the live editor |
| `articles` | Articles and Write with AI |
| `photos` | The photo library |
| `enquiries` | The enquiries inbox |
| `publish` | Publish changes (panel and toolbar), revision history |
| `settings` | Redirects, AI settings, Search & speed |

A role given as a plain label (`'editor' => 'Editor'`) keeps the 0.2 behaviour: everything but `settings` and the team. The administrator role always has everything; the team screen is still guarded by your `manage-users` gate.

Each ability is a gate named `gadya-cms.{ability}`, registered on top of your `manage-content` gate - a person must be allowed into the CMS at all, then allowed to do this. Define a gate of the same name yourself and the package keeps yours.

Your user model's `canManageContent()` / `canAccessPanel()` must include every role you list here, or a contributor is stopped at the door.

# Everywhere on the site

Words and pictures that appear on every page - the announcement bar, the phone number, the footer tagline, a call-to-action strip - are top-level paths in the site document, listed in `gadya-cms.globals`:

```php
'globals' => [
    'announcement' => ['label' => 'Announcement bar', 'type' => 'text', 'max' => 120, 'required' => true, 'group' => 'Top of every page'],
    'phone' => ['label' => 'Phone number', 'type' => 'text', 'max' => 40, 'required' => true, 'group' => 'Contact details'],
    'footer.tagline' => ['label' => 'Footer tagline', 'type' => 'textarea', 'group' => 'Footer'],
    'cta.image' => ['label' => 'Call-to-action photo', 'type' => 'image', 'group' => 'Call to action'],
],
```

They are edited together under **Appearance → Everywhere**, and on the page:

```blade
<p @editableGlobal('announcement')>{{ $site['announcement'] }}</p>
<p @editableGlobal('footer.tagline', 'multiline')>{{ $site['footer']['tagline'] ?? '' }}</p>
```

Add each path to `editable_fields` for the on-page editor to accept it. Every screen that saves a draft now carries **Publish changes**.

# The panel wears the site's clothes

The panel and its sign-in screen follow the site itself, so the client signs in to something that looks like her own website:

- the colours and type she chose under **Appearance → Look & feel**;
- the **logo** she picked on that same screen, from her photo library.

They change when she presses **Publish changes**, not before - the panel shows the live look, never an unpublished draft.

`->brand(false)` on the plugin is a different thing again: it leaves the panel's name, logo, font and colours as the site set them, while the CMS's own screens keep the colours and type they are drawn with.

A site that wants the panel to look different from the site sets `brand.follow_site` to `false` and fills in `brand.primary`, `brand.logo` and the rest by hand; the logo picker then disappears from her screen.

```php
'brand' => [
    'follow_site' => true,   // the panel follows Look & feel
    'follows' => ['primary' => 'fun-purple', 'accent' => 'fun-yellow'],
    'logo' => 'logo.webp',   // only read when follow_site is false, or nothing is picked
],
```

`follows` maps each panel colour to one of the site's own, for a palette that names its colours its own way. Anything left out follows a site colour of the same name (`primary`, `secondary`, `background`, `ink`, `accent`) and falls back to the configured value when the palette has none.
