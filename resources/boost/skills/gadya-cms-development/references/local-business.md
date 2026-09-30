# Menus and opening hours

Two things every local business site needs and every client changes: what is on the menu (and what has just sold out), and when the doors are open.

## Food menus

A restaurant, a bagel shop or a café keeps its menus under **Content → Food menus**. The screen is off until the plugin asks for it, because a salon or a plumber has no use for it:

```php
GadyaCmsPlugin::make()->foodMenus()
```

A menu (Breakfast, Lunch, Drinks) has sections (Bagels, Spreads, Hot drinks), and a section has items. Menus and sections are dragged into order on the menu's screen; items on **Menu items & sold out**, grouped by section.

Each item has:

| | |
| --- | --- |
| Name and description | as the menu prints them |
| Price | typed as dollars and cents, kept as whole cents (`450`) |
| Sizes | instead of one price: Small $3, Large $4.50 - each size its own label and price |
| Dietary and allergens | vegetarian, vegan, gluten-free, dairy-free, halal, kosher, spicy, contains nuts, contains shellfish (`menus.dietary`) |
| Photo | from the photo library, with a description |
| When it is available | plain words: "Weekdays until 11am" |
| Special | picked out on the menu and in the specials list |
| Sold out | stays on the menu, marked sold out |
| On the menu | off hides it without deleting it - for something seasonal |

**Sold out and specials are live the moment they are switched.** There is nothing to publish: a bagel that stays on the site until someone remembers to press Publish is the problem the switch exists to solve. The table has a sold-out switch on every row, a bulk *Mark sold out* / *Mark available*, and **Everything back in** for the start of the day. The sidebar counts what is sold out.

A menu itself is a draft (only editors see it) until it is set to *On the site*.

### On the page

```blade
<x-gadya-cms::menu menu="breakfast" />
<x-gadya-cms::menu menu="drinks" :level="3" :images="false" />
<x-gadya-cms::menu-specials heading="Today’s specials" :limit="4" />
```

`menu` is the menu's short name, set on its screen. `level` is the heading level of the menu's name (sections and items sit below it, so the page outline stays correct); `images`, `schema` (the JSON-LD) and `jump` (the list of links to each section) can each be switched off.

The markup is semantic and ships class names, not styles, like every other package template: `cms-menu`, `cms-menu__section`, `cms-menu-item`, `cms-menu-item--featured`, `cms-menu-item--sold-out`, `cms-menu-item__price`, `cms-menu-item__prices` (a `<dl>` of sizes), `cms-menu-item__tags`, `cms-menu__key`. Dietary marks show their short form (`VG`) with the full word for screen readers, and a key under the menu explains the ones it uses.

**The live editor** cannot edit a menu in place - it is rows, not words in the site document - so for someone editing, the menu carries a *Change this menu in the admin* link to its screen. A draft menu shows to her, with the link, and never to a visitor.

### Structured data

Each published menu writes `Menu` → `MenuSection` → `MenuItem` JSON-LD with an `Offer` per price (`price` as `"4.50"`, `priceCurrency` from `menus.currency`), `availability` `InStock` or `SoldOut`, `suitableForDiet` for the marks schema.org has a word for (allergen warnings have none), and an `@id` of `https://site/#menu-breakfast`. To point the business at it, add `hasMenu` to the site's own JSON-LD or leave it to Google to find on the page.

### Built for ordering later

Menus, sections, items and each size carry a `key` (a ULID) that never changes through renames and reorders, and every price is an integer number of cents. An ordering service or a till integration can hold on to those. Read menus yourself with `Gadya\Cms\Menus\FoodMenus`: `find($slug)`, `all()`, `featured($limit)`, `structuredData($menu)`; `MenuItem::prices()` gives `[{key, label, price_cents}]` for any item, one price or several.

```php
'menus' => [
    'currency' => 'USD',
    'currency_symbol' => '$',
    'dietary' => [
        'vegan' => ['label' => 'Vegan', 'short' => 'VG', 'schema' => 'https://schema.org/VeganDiet'],
        // ...
    ],
],
```

## Opening hours

**Appearance → Opening hours** is the one place the hours are kept, and everything reads from it: the hours table, the open-now badge, Google's structured data and the Gadya portal.

- **Every week** - each day takes one or more sets of times (lunch and dinner). No times means closed. A closing time earlier than the opening time runs past midnight: a Friday of 6pm to 2am is still Friday night at 1:30 on Saturday morning. Opening and closing at midnight is open all day.
- **Holidays and special days** - a date, what it is for ("Thanksgiving"), and either *Closed all day* or its own times. A date here replaces that day's usual hours.
- **Time zone** - where the business is, not where the server is. By default the hours follow the [site's time zone](operations.md#the-sites-time-zone), so changing it moves them too. Pick a zone here only for hours that run in another one; it is kept with the hours. A site with no zone of its own uses `hours.timezone` (America/New_York). The opening and closing times are clock times and are never converted.

The hours are part of the site document (the `hours` key), so they are saved to the draft, published with **Publish changes**, and kept in the revision history.

### On the page

```blade
<x-gadya-cms::opening-hours />                         {{-- the week, and holidays coming up --}}
<x-gadya-cms::opening-hours caption="When we’re open" :upcoming="30" special-heading="Coming up" />
<x-gadya-cms::open-status />                           {{-- "Open now · closes at 3pm" --}}
<x-gadya-cms::todays-hours label="Today" />            {{-- "Today 7am – 3pm" --}}
```

- The table is a real `<table>` with a caption and a row header per day; today's row has `aria-current="date"` and `cms-hours__day--today`. Times are `<time>` elements. Holidays within `hours.upcoming_days` (60) are listed under it.
- The badge is `cms-open-status--open` or `--closed`, plus `--closing-soon` in the last hour, with `data-open`. It says "Open now · closes at 3pm", "Closed · opens tomorrow at 7am", "Closed · opens Monday at 7am", or "Open now · open 24 hours".
- Today's hours use the holiday's hours on a holiday, and name it.

All three render nothing until hours are set, and give an editor a *Change the opening hours in the admin* link.

The badge is worked out when the page is drawn. A site that caches whole pages for more than a few minutes will show a stale badge; cache for less, or leave the badge off cached pages.

### Structured data and Google

When hours are set, `@cmsSeo`'s LocalBusiness node carries `openingHoursSpecification`: one entry per set of days sharing times, and one per holiday or special day still to come (`validFrom` / `validThrough`; a closed day opens and closes at `00:00`, as Google asks). A site that writes its own business JSON-LD (`seo.organization_schema` false) can add the same with `app(\Gadya\Cms\Hours\BusinessHours::class)->specification()`.

`Gadya\Cms\Hours\OpeningHours` does the arithmetic and is safe across the nights the clocks change - every range is turned into two real instants in the business's time zone:

```php
$hours = app(\Gadya\Cms\Hours\BusinessHours::class)->current();   // draft for an editor, else published

$hours->isOpenAt();                 // now
$hours->status();                   // ['open' => true, 'label' => 'Open now', 'detail' => 'closes at 3pm', 'closes_at' => ..., ...]
$hours->rangesOn('2026-12-24');     // [['07:00', '12:00']] - a holiday's hours win
$hours->upcomingExceptions();
$hours->toArray();                  // the stored shape
$hours->toGoogleBusinessProfile();  // regularHours.periods and specialHours.specialHourPeriods, as that API takes them
```

The stored shape is also what the portal is sent at check-in, as `hours`:

```json
{
  "timezone": "America/New_York",
  "regular": {"mon": [["07:00", "15:00"]], "tue": [], "fri": [["07:00", "15:00"], ["18:00", "02:00"]], "...": []},
  "exceptions": [{"date": "2026-11-26", "closed": true, "ranges": [], "label": "Thanksgiving"}],
  "open_now": true
}
```

The section is left out altogether while no hours are set.
