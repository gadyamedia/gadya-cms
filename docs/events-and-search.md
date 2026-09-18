# Events, search and the mailing list

## What's on

Events are things whose point is the date they happen on: an open day, a camp, a class. They are rows rather than pages, so the list sorts itself, and an event stays on it until it *ends* rather than until it starts - a three-day camp is still on in its second afternoon.

**Content → What's on** holds them. Each has a start, an optional end, an all-day switch, a place, a price in words ("£8 a child, adults free"), a booking link, a photo and a description.

On the public site:

- `/events` lists what is coming up, with what has already happened underneath
- `/events/{slug}` is the event, with `Event` structured data so Google can show it in its own right
- `/events.ics` is a calendar a phone can subscribe to, kept up to date

```php
'events' => [
    'routes' => true,        // off if your application has its own templates
    'prefix' => 'events',
    'title' => 'What’s on',
    'heading' => null,       // defaults to the title
    'description' => '',
    'past' => 6,             // how many finished events to keep listed
],
```

Read them yourself with `Gadya\Cms\Events\EventCalendar`: `upcoming()`, `past()`, `findLive($slug)`, `ics()`, `structuredData($event)`.

Upcoming events are added to the sitemap automatically. A draft event is invisible to visitors and visible to an editor with the live editor on, like a draft article.

## The search box

`/search?q=…` searches the published pages and the live articles. Pages are searched in PHP from the site document, articles in the database; a word in a title outranks the same word in the body, and the snippet is cut around the first match.

```blade
@cmsSearchForm
@cmsSearchForm(['label' => 'Find a party place', 'placeholder' => 'Try “foam”'])
```

```php
'site_search' => [
    'routes' => true,
    'path' => 'search',
    'limit' => 20,
],
```

The results page carries `noindex`, because a visitor's search is not a page worth indexing.

**What people search for is counted**, as a `site_search` event with the term and how many results it found. Searches that found nothing are the most useful thing on the dashboard: they are the content the site is missing, in the visitor's own words.

Read it yourself with `Gadya\Cms\Search\SiteSearch::for($query)`.

## The mailing list

```blade
@cmsNewsletterForm
@cmsNewsletterForm(['label' => 'Party ideas, once a month', 'button' => 'Join'])
```

Addresses are kept in `gadyacms_subscribers`, lower-cased so nobody joins twice, with where they signed up from. **Content → Mailing list** lists them and downloads them as a CSV in the columns Mailchimp and everything that copied it expect (`Email Address`, `First Name`, `Last Name`).

Unsubscribing is a signed link that needs no account and never expires, because it will be clicked from an old email:

```php
$subscriber->unsubscribeUrl();
```

Signing up again after unsubscribing puts someone back on the list. The list is in your database, so moving to a different mailing service is an export, not a migration.

```php
'newsletter' => [
    'enabled' => true,
    'label' => 'Get our news by email',
    'button' => 'Sign up',
    'success' => 'Thank you. We will be in touch.',
    'unsubscribed' => 'You have been taken off the list.',
],
```
