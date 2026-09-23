# Changelog

All notable changes to `gadya/cms` are documented here.

## 0.12.3

### Added

- Every item in a section carries a `key` that survives renames and reorders, so a site can sync its cards to another system and still know which one is which. **Add item** on the page and a new item in the admin both give one; saving a page in the admin keeps the keys already there. Items from before this release have none until a site assigns them.

## 0.12.2

### Fixed

- A card added with **Add item** can be given a description and a photo. It starts with a title alone, and the editor refused to save a field the item did not already have ("That field does not exist on this page"), so the new card could only ever hold its title. A field of an existing list item is now filled in; a page still never gains a field it does not have, and a list never gains a slot. Templates that hide an empty description or photo with `@isset` should show an empty slot in edit mode so there is something to click.
- **Publish** waits for the edit that was just made. Clicking it straight after typing blurred the field, which started its save at the same moment the form posted, so the old draft went live and the client had to press Publish twice. The button now waits for every save to land, and does not publish over one that failed.

## 0.12.1

### Fixed

- **Requires gadya/connect 0.5.** `^0.4` on a 0.x version means *below 0.5*, so sites stayed on connect 0.4, whose check-in carries no summary for the portal's fleet screen. Only the constraint changes.
## 0.12.0

### Added

Three switches for a site whose own `<head>` did things `@cmsSeo` did not, so it can move onto `@cmsSeo` without losing any of them. All three are off by default; nothing changes for a site that does not set them.

- `seo.suffix_written_titles` puts `seo.title_suffix` on the titles the client wrote too, not only on those made from a page's name - for a local business whose town belongs in every title ("Menu | Manalapan, NJ"). A title already carrying the suffix is not given it twice.
- `seo.organization_schema` set to false leaves out `@cmsSeo`'s organisation and website nodes, for a site that describes its own business in richer JSON-LD - a restaurant with its hours, holiday hours and menu. Search engines are not then handed two businesses with the same name. Articles still get their node, pointing at `seo.organization.anchor`, the fragment the site's own business node uses.
- `seo.default_image` and a page's share image accept a path under `public/` or a full address, as well as a photo-library name: a share card made for social previews usually lives beside the site's other images.

### Changed

- Indexable pages ask for `max-image-preview:large`, which lets Google show a large image preview in search and Discover. It has to be said; it is not assumed.

## 0.11.4

### Fixed

- The accessibility statement is listed in the sitemap. It is a page a person reads - often the person deciding whether to complain - and was the one public page the package served that the sitemap left out. A site's own sitemap guard found it.

## 0.11.3

### Changed

- **A wide lockup is cropped to its mark** rather than shrunk whole: a name that is legible at 320 pixels is a smudge at 32, and the leading square of a lockup is the mark (or, on a wordmark, its first letters). A logo that carries its own opaque background keeps it and fills the tile, so there is no white square floating inside a coloured one; a logo on transparency is padded on a colour chosen to contrast with it.

  A purpose-made square mark still beats anything drawn automatically - `brand.favicon_source` names one.

## 0.11.2

### Fixed

- Logos are composited. `insert()` takes the alignment as its **fourth** argument, after the offsets; passing `'center'` second is a type error, so every logo fell silently through to the initials. `gadya-cms:favicon` also now reports the source it actually used rather than the one it could have tried.

## 0.11.1

### Fixed

- The icon is actually drawn. On Intervention 4 the composite method is `insert`, not `place`, so every logo fell through to the initials - and the initials needed a font file that is not there, so what came out was a flat coloured square. A square is a valid PNG of the right size, which is why the first tests passed it. Logos are composited properly now, the initials are drawn with GD's own font and scaled, and `describe()` reports whether what came out is one flat colour. `gadya-cms:favicon` fails loudly when it is.

## 0.11.0

### Added

- **Every site gets a favicon, without anyone asking for one.** Almost every site we take over arrives without the little square in the browser tab, because the client does not know the word and it is too small a thing to raise. The site now draws its own from the logo - contained rather than cropped, so a wordmark stays legible - and serves it at `/favicon.ico` (a real .ico wrapping a PNG), `/favicon-32.png`, `/apple-touch-icon.png`, `/favicon-512.png` and `/site.webmanifest`, with the tags written into `@cmsSeo`.

  A site with no logo at all gets the business's initials in white on its brand colour, which still beats the blank page browsers show. A site with a real `favicon.ico` or `favicon.png` in `public/` keeps it and publishes no tags of ours, because two different icons in one head is worse than none - and the web server answers that file before Laravel is asked anyway.

  `brand.favicon` turns it off; `brand.favicon_source` names a different image, for a mark that reads at 32 pixels where a wide wordmark does not. `gadya-cms:favicon` draws it and says where it came from; `--forget` draws it again after a logo change.

## 0.10.4

### Fixed

- `gadya-cms:agent-ready` no longer tells a site to turn on llms.txt, the sitemap or Markdown when the **application already serves them itself**. Three of our own sites were being marked down for features they had, written their own way. The Markdown check asks the application for the home page as Markdown rather than reading a switch, because content negotiation cannot be read off the routing table.

- The "does the application serve this?" check skips the package's own routes by name, so a switch turned off cannot be satisfied by the route it would have registered.

## 0.10.3

### Added

- An **agent card the site already publishes** at `/.well-known/agent-card.json` is named in the capability manifest and the home page's `Link` header, rather than ignored or duplicated. Several of our sites wrote one before the package could.

## 0.10.2

### Fixed

- `robots.txt` names the sitemap and llms.txt when the **application** serves them, not only when the package does. A site with its own sitemap was serving a robots.txt with no `Sitemap:` line at all.

## 0.10.1

### Fixed

- The catalogues now advertise an address the **application** serves, not only one the package serves. A site that writes its own `llms.txt` or `sitemap.xml` and switches ours off was having those addresses left out of its own capability manifest, although they answer perfectly well. An address nothing answers is still never advertised.

## 0.10.0

### Added

- **Machine-readable discovery, for the agents that now do the choosing.** Three documents, generated from what the site really serves:
  - `/.well-known/ai-catalog.json` - an ARD capability manifest naming each thing an agent can use, with two or three representative questions per entry so a registry can index it;
  - `/.well-known/api-catalog` - an RFC 9727 linkset anchored on the site, with `service-doc` and `service-desc` links;
  - `/.well-known/agent-skills/index.json` - the Agent Skills discovery index, each skill carrying a digest.

  All three are served with `Access-Control-Allow-Origin: *` and an hour's cache. `seo.discovery` turns them off.

- **An MCP server card** at `/.well-known/mcp/server-card.json` (SEP-1649), but only where the application declares `seo.mcp.endpoint`. A site that runs no MCP server answers 404 rather than publishing an empty card, and says nothing about MCP in its catalogues.

- **Richer Link headers** on the home page: `describedby` and `service-doc` to llms.txt, `sitemap`, and `api-catalog` to the new catalogue (RFC 8288, RFC 9727 section 3).

- **The DNS checklist asks for a DNS-AID record** (`_index._agents`), so an agent that knows only the domain can find the catalogue without loading a page. Optional, marked as a draft standard, with what to type at the registrar - it is the one part of this that a web application cannot publish for itself.

- `gadya-cms:agent-ready` scores the Link headers and the discovery documents, and `gadya-cms:audit` lists `seo.discovery`.

### A note on what is deliberately not here

Agent-readiness checkers also ask for OAuth and OIDC discovery metadata, `auth.md`, OAuth protected-resource metadata, and the agent payment protocols (x402, MPP, UCP, ACP). Those describe protected APIs, agent registration and machine payments. A site that has none of them must not advertise them: an agent that follows a catalogue to an address answering 404 is worse off than one that found nothing, and a published payment endpoint that cannot take a payment is worse still. When a site really gains an authenticated API or sells through an agent, the metadata belongs with it.

## 0.9.1

### Added

- **`gadya-cms:backup-drill` looks inside the newest backup.** It opens the archive, finds the database dump and checks it holds real tables, then writes down what it found - pass or fail - so there is something to show a client besides a promise. A backup that is running but saving nothing is found on an ordinary Tuesday rather than on the worst day of her year. Schedule it monthly; the result travels in the check-in.

## 0.9.0

### Added

- **An accessibility record, and a statement written from it.** A public **/accessibility-statement** page generated from the site's own history: the standard it aims at, how it is assessed, how many pages were checked and when, what is still outstanding, and a dated table of every barrier put right. It never claims full conformance - automated checks cannot justify that, and a claim nobody tested is what gets businesses into trouble in the first place. This is the opposite of an overlay widget: no script, no badge, a record. `accessibility.statement`, `accessibility.path` and `accessibility.pledge` in config; turn the page off on a site with its own statement.

- **The fortnightly nudge.** `gadya-cms:drift-digest` emails the handful of things that have quietly gone out of date - an enquiry nobody opened for a day, a What's on page with nothing coming up, links that lead nowhere, photos with no description, pages with no search snippet, a blog untouched for four months, business details Google is not being given. It sends nothing when there is nothing to say. `--show` prints them instead. Schedule it fortnightly.

- **Download everything.** `gadya-cms:takeout`, and a **Download everything** button on Speed & accessibility: one zip with every word as Markdown, every photograph at full size, every article, every enquiry as a spreadsheet, the whole site as JSON, and a README that tells the client plainly how to take it to someone else. A client who cannot leave has to be kept rather than earned.

- **Do AI assistants recommend her?** The panel shows whether the assistants people now ask for recommendations actually name the business, and what they say. The asking is done weekly by the portal, which holds the key; the site only reads the answer.

- **Her accessibility record on her own screen**, and, where the site takes backups, what the portal knows about them.

### Changed

- The check-in carries one `PortalSummary` - quality, accessibility, drift, unanswered enquiries and backup state - so the portal shows every site at a glance without opening any of them.

## 0.8.1

### Added

- **Settings → Speed & accessibility.** Google's Lighthouse check already ran weekly; now what it found is written where the client can read it. Each failure is in plain English, against the page it was found on, split into what the CMS can put right itself and what lives in the templates - the second with the failing selector, for whoever looks after the code.

- **Two buttons that fix the first pile.** *Describe the photos* looks at each photo with no description and writes one sentence saying what is in it. *Write the missing search snippets* reads each page and writes the sentence Google shows under its name. Both write into the **draft**: the client reads them, changes anything she would say differently, and publishes. `php artisan gadya-cms:fix` does the same from the command line and prints the brief for everything left.

- **The writing is Gadya's, not another bill.** A site with its own key under Settings → AI keeps using it. A site without one, paired with the portal, borrows **Gadya Media's key** over the link `gadya/connect` already keeps - nothing to buy, nothing to configure, and no API key on a client's server. The portal counts and can cap each site.

- **The client is told what was fixed.** Every change is recorded in the new `gadyacms_fixes` table and listed on the screen: what was changed, what it now says, when, and whether Gadya or her own key wrote it. They are her words from then on, editable wherever that page or photo is edited.

- A page speed check now keeps **every failing audit** and the elements that failed it, not only the scores and the biggest opportunities (new `failures` column on `gadyacms_page_scores`).

### Fixed

- `opportunities` were stored but `failures` would not have been: `PageScore` did not list the new column as fillable.

## 0.8.0

### Added

- **Email without setting anything up.** A site paired with the Gadya Media portal and with no mail service of its own now sends through Gadya: the message goes to the portal over the signed link `gadya/connect` already keeps, and the portal sends it on. No SMTP details to collect, no mail credential on the client's server, and sending can be capped or cut off per site from the portal. Messages come from `{site}@on.gadya.media` with a Reply-To the business actually reads, and carry one quiet line at the foot saying gadya.media sent them.

  A site with `MAIL_MAILER` set to a real service is never touched. `GADYA_MAIL=false` opts out for good, `GADYA_MAIL=true` insists, and `GADYA_MAIL_MAILBOX`, `GADYA_MAIL_DOMAIN`, `GADYA_MAIL_REPLY_TO` and `GADYA_MAIL_FOOTER` cover the rest. Copy the new `mail` block into `config/gadya-cms.php`. See [Email](docs/email.md).

- **Settings → Automatic replies** now says who sends the site's email and from which address - asked of the portal, which is what decides it, rather than guessed from the site's own name - lists what has gone out lately and whether it arrived, and offers **Send me a test email** that reports the failure on the screen rather than in a log.

- **Where replies go is the client's to choose**, on that same screen. Nobody reads the shared sending address, so every message asks for replies at the address she names there; left blank it falls back to the business email from the site's details, as before.

- `gadya-cms:audit` checks the site can send email at all, and that a real queue carries it: with `QUEUE_CONNECTION=sync` a visitor waits for the portal to answer before her form says thank you.

### Changed

- The DNS checklist on **Get found** no longer asks for an SPF record on a domain that does not send. A site whose email goes through Gadya is told so, and is still asked for DMARC - which belongs on the client's own domain whoever sends for her.

## 0.7.4

### Fixed

- A panel using `->brand(false)` - keeping its own chrome - no longer loses the colours and faces the CMS screens are drawn with. The dashboard, the photo library and the editor were rendering with no surfaces, no type and no accent, because the switch also withheld the custom properties the panel stylesheet reads. It now only skips the panel's name, logo, font and colours, as it says.

## 0.7.3

### Fixed

- The migrations can be run again after one fails half way. A database without DDL transactions - MySQL - keeps the tables a failed migration already made and does not record it as run, so the next deploy repeated it and stopped on `table already exists` (or its unique index). Every table is now created only when it is missing, and a table that is already there is left exactly as it is.

## 0.7.2

### Fixed

- `gadya-cms:install` and `gadya-cms:editor` no longer fail with a database error on a site that works out a person's role for itself - from an `is_admin` flag, say - and exposes `role` as an accessor rather than a column. They say the site makes its own accounts and carry on.

## 0.7.1

### Added

- `brand.follows` maps each panel colour onto one of the site's own, for a palette that names its colours its own way (`'primary' => 'fun-purple'`). Without it the panel follows a site colour of the same name, and keeps the configured one where the palette has none - which is what every existing site does, so upgrading changes nothing until you say so.

## 0.7.0

### Added

- **The panel and its sign-in screen follow the site.** The colours and type from **Look & feel** now paint the panel, and that screen has a **logo** picker whose choice appears on the sign-in screen and at the top of the panel - no config file to edit per site. It follows the published site, so an unpublished draft changes nothing. A site that wants a different-looking panel sets `brand.follow_site` to `false` and keeps filling in `brand.*` by hand.

## 0.6.1

### Added

- **Updates can be run from the Gadya Media portal.** `gadya-cms:install` publishes `.github/workflows/gadya-update.yml`, which updates the Gadya packages, runs the site's tests, and puts the result in git: a patch release that passes goes straight to the default branch, anything larger or a failing run waits in a pull request. An existing workflow file is never overwritten.

## 0.6.0

### Added


- `php artisan gadya-cms:password --email=… ` sets a new password for someone who cannot get into the panel, with `--generate` to make a strong one and print it once. Sessions opened with the old password are signed out.

## 0.5.7

### Fixed

- The audit screen in the panel said the scheduled jobs were missing on a site where they are scheduled. Laravel only loads `routes/console.php` for console commands, so in a web request the audit saw an empty schedule; it now reads the files that define it. `gadya-cms:audit` on the command line was always right.

## 0.5.6

### Changed

- `gadya-cms:audit` no longer demands the analytics and Search Console jobs from a site that has those features switched off, and treats `@cmsSeo` as a choice on a site that also switched off the package's sitemap, robots.txt and llms.txt - such a site writes its own head tags on purpose. Both are still listed, as decisions rather than to-dos.

## 0.5.5

### Changed

- Works with Intervention Image 4 as well as 3. Laravel 13's `Image` facade needs version 4, so a site that already uses it can now install the package. Photos are read and written through `Gadya\Cms\Support\Images`, which works with either version.

## 0.5.4

### Added

- `SitemapEntries::add(fn () => ...)` lets a site put its own records (rentals, services, job postings) in `/sitemap.xml`. `seo.sitemap_extra` only takes fixed paths. See *Sitemap and robots* in `docs/seo.md`.

## 0.5.3

### Changed

- **`@gadyaBuiltBy` is rendered by the server.** The badge no longer loads a script from gadya.media or the Arvo font from Google Fonts, which Lighthouse counted as render-blocking. It looks the same. The text uses Arvo when the site already loads it, and Georgia otherwise. The logo now loads lazily and reserves its space. Nothing to change on the site.
- **`gadya-cms:media-variants` covers legacy photos.** Photos shipped in `public/images/site` before the library existed now get WebP variants on the media disk. The originals stay where they are. `@siteImage($name, $width)` and `@siteSrcset($name)` serve those variants, so phones stop downloading full-size PNGs. After updating, run `php artisan gadya-cms:media-variants` once on the server.

## 0.5.2

### Changed

- `gadya-cms:audit` lists features a site has switched off (plugin switches, `*.routes`, the SEO files) and a static `public/robots.txt` or `public/sitemap.xml` as **optional** rather than to-do. A site that deliberately goes without them now audits clean.

## 0.5.1

### Fixed

- `gadya-cms:audit` no longer asks a site to copy the package's example fonts into `fonts.display` and `fonts.sans`: the font choices are the site's own.

## 0.5.0

### Added

- **[gadya/connect](https://github.com/gadyamedia/gadya-connect) is included.** Every site gets:
  - **Get help** for anyone who can sign in to the admin, with a button in the top bar;
  - **Gadya Support**, to pair the site with the Gadya Media portal;
  - once paired: check-ins every five minutes (versions, health, this audit, updates waiting) and one-click sign-in for the Gadya team, which the client can switch off.

  Nothing is sent anywhere until the site is paired.
- `gadya-cms:audit` says whether the site is connected to the portal.

### Upgrading

`composer require gadya/cms:^0.5 -W`, then `php artisan migrate`. See [upgrading](docs/upgrading.md).

## 0.4.9

### Fixed

- Coming-soon mode no longer blocks `/gadya-connect/sso`, so the Gadya team can sign in to help while a site is closed.

## 0.4.8

### Added

- With [gadya/connect](https://github.com/gadyamedia/gadya-connect) installed, the panel gains **Gadya Support** (link the site to the Gadya Media portal) and **Get help** (ask the Gadya team for help from inside the admin), with a Get help button in the top bar. Without it, nothing changes.

## 0.4.7

### Added

- **`@gadyaBuiltBy`** puts the "built by Gadya Media" badge in the bottom-right corner of the footer, with no script to paste by hand. It is drawn in the site's ink (`brand.ink`, or `built_by.color`); the logo is recoloured to match by a CSS filter the package works out from the colour (`Gadya\Cms\Brand\ColorFilter`). `gadya-cms:audit` flags a footer without it.

## 0.4.6

### Added

- A **"Site powered with ❤ by Gadya CMS by gadya.media"** line under every panel screen, the sign-in page included. It shows the installed version and links to gadya.media. Turn it off with `->poweredBy(false)`. `GadyaCmsPlugin::packageVersion()` returns the installed version.

## 0.4.5

### Added

- **`gadya-cms:audit`** lists what an application has not yet taken up from the installed version, each with its fix:
  - config keys a release added
  - migrations not run
  - plugin switches and feature flags that are off
  - scheduled jobs missing from `routes/console.php`
  - `@cmsSeo`, `@cmsToolbar`, search and newsletter forms missing from the templates
  - a stale panel stylesheet, missing gates, static `robots.txt` or `sitemap.xml` files, and out-of-date Boost skills

  `--json` is for agents. The command fails while anything is left to do.
- **The `gadya-cms-upgrade` Boost skill.** Ask an agent to *"upgrade gadya/cms to the latest version and turn on everything"*. It audits the site, updates the package, applies the upgrade notes, switches on and wires up every feature, runs the tests, commits on a branch, and lists what only a person can do. It never touches content.

## 0.4.4

### Added

- **Settings → Get found**: live checks that `robots.txt`, `sitemap.xml` and `llms.txt` actually answer (and the Forge nginx fix when `robots.txt` comes back 404), the sitemap address with step-by-step Search Console and Bing instructions, and the DNS records every domain in `seo.domains` needs, each looked up live and marked in place, missing or pointing elsewhere.
- `Content-Signal` lines in `robots.txt` (`seo.content_signals`, default `search=yes, ai-input=yes, ai-train=no`) and a matching readiness check.
- `Link` headers on the home page pointing agents at `llms.txt` and the sitemap (`seo.link_headers`).

### Changed

- **The dashboard, redesigned.** Rows no longer touch the card edges; headline figures sit in one strip under a live-visitor pill and a segmented date range; the chart has gridlines, a "busiest day" figure and an empty state; pages, sources and countries show proportional share bars; countries get full names; devices a split bar; readiness checks stack instead of cramming into two columns; notices merge into one; "Someone" is now "A team member".

## 0.4.3

### Added

- **Add with a password** on the Team screen: create someone without sending an email, with a password made up for you (or your own), then copy their sign-in details - address, email, password and how to change it - to pass on however you like.
- **Set a new password** on each person's row, handing over the new details the same way.

## 0.4.2

### Fixed

- **Coming-soon mode locked the client out of her own panel.** The check ran before the session started, so nobody ever looked signed in, and Livewire 4's hashed update path (`/livewire-xxxx/update`) was not recognised as the panel's - every click, including Publish, got the notice. It now runs in the web group and matches Livewire by its prefix.
- The coming-soon notice wears the site's brand: its colours, display font and logo.

## 0.4.1

Identical to 0.4.0 in every line of code; it adds the two screenshots the
README links to, and exists because 0.4.0's tag was moved after it had
been published. A published version's contents never change, so the old
tag stays where it was and this is the one to use.

## 0.4.0

The release that answers "but WordPress does…".

### Added

- **Categories and tags** for articles, with archive pages of their own, and **related articles** at the foot of each one.
- **Comments**, off unless a site wants them, moderated unless it says otherwise, emailed in full.
- **Duplicate** for pages and articles.
- **A trash**: a deleted page is off the site at once and back in one click for thirty days, then emptied by `gadya-cms:prune-trash`.
- **Fields per page type**, so a location is not edited through a form built for a legal page.
- **Saved blocks**: keep a section and drop a copy of it into another page.
- **The site's own search box**, with what people searched for - and did not find - counted on the dashboard.
- **Events**: things that happen on a date, sorted for you, with an `.ics` feed and `Event` structured data.
- **A mailing list** with an unsubscribe link that needs no account and an export in the columns mailing services read.
- **A focal point** per photo, rendered by `@siteFocus`, and **alt text written by looking at the photograph**.
- **A profile page**, **unsaved-changes warnings**, and **more than one menu**.
- **An activity log**: who changed what, when.
- **Publishing at a time**: hold the whole draft until Friday at nine.
- **Coming-soon mode** that closes the site without closing the panel, with a password link to share.
- **Broken links** from both ends - what visitors asked for and what the site links to - fixed with one click.
- **Automatic replies**: the thank-you email each form sends, written in the panel.
- `gadya-cms:prune-trash`, `gadya-cms:prune-activity`, `gadya-cms:publish-due`, `gadya-cms:check-links`.

### Changed

- Pages are soft-deleted; `Page::query()` no longer sees deleted rows. See [upgrading](docs/upgrading.md).
- **Publish changes** asks when, so it can be scheduled; passing no time still publishes now.

## 0.3.0

### Added

- **Roles with abilities** (`content`, `articles`, `photos`, `enquiries`, `publish`, `settings`); a contributor role that writes articles but cannot publish a page. Plain-label roles keep working.
- **Everywhere**: the announcement, phone, footer text and any other global, from a configured list, on one screen and on the page.
- **Publish changes** on every screen that saves a draft.
- **`gadya-cms:make:page-template`**: a Blade template with every configured field already editable.
- **A demo site** (`composer demo`) that uses every feature, for trying, screenshots and development.
- **Moving a site**: `gadya-cms:export` (zip or JSON, photos included) and `gadya-cms:import`. Secrets never travel.
- **Responsive photos**: variants at configured widths, `@siteSrcset`, `@siteImage($ref, $width)`, and a backfill command.
- **Search Console** on the dashboard through a service account; **PageSpeed Insights** scores for the top pages; a **readiness score for AI assistants**.
- **For AI assistants**: `/llms.txt`, named AI crawlers in `robots.txt`, Organisation/WebSite/Article JSON-LD, and every page and article as `text/markdown` on request.
- A second Boost skill, `gadya-cms-content`, for people who write and publish rather than build.

### Changed

- *Site details* is now *Locations*; the announcement, phone and address moved to *Everywhere*.
- Redirects, AI settings and Search & speed need the `settings` ability.
- `gadya-cms:export` writes the new format; `--array` gives the old one.

## 0.2.2

- The Boost skill carries the whole `docs/` folder as references, kept identical by a test and `composer sync-docs`.

## 0.2.1

- Laravel Boost guideline and `gadya-cms-development` skill, installed by `boost:update --discover`.

## 0.2.0

The package now stands on its own: it is tested against a minimal host
application, on PHP 8.3 and 8.4, and no longer assumes any route the host
defines. See [docs/upgrading.md](docs/upgrading.md) for the handful of
changes an application upgrading from 0.1 makes.

### Added

- **Articles**, with a writing screen, a findability score on every save,
  scheduled publishing, and optional public routes in the host's layout.
- **Writing with AI**: provider, model and key chosen in the panel and
  stored encrypted; a voice for the business; whole drafts written in the
  background, rewrites of existing articles, and search snippets from a
  page's own words. Any provider Laravel's AI SDK speaks, including a
  self-hosted one.
- **Search snippets** on every page and article, `@cmsSeo` for the head
  tags, a generated `sitemap.xml` and `robots.txt`.
- **Redirects**, a table the client edits, counted, run as global
  middleware so an old address with no route is still forwarded.
- **Scheduling** for pages: show from, hide after, judged at request time.
- **Preview links**: signed, expiring, `no-store`, for someone with no
  account.
- **Forms**: configured fields, a honeypot, an email with reply-to, an
  inbox in the panel with CSV download.
- **Photo folders and tags**, a move-to-folder bulk action, and a bulk
  delete that skips anything still in use and says so.
- **Analytics reports**: CSV download, email on demand, and a weekly email
  to a list the client keeps.
- **`gadya-cms:install`** now does everything between `composer require`
  and a working site, and can be run again.
- An options table for configuration that is not content, so it never
  lands in a revision.

### Changed

- Where a page lives is answered by `Gadya\Cms\Contracts\ResolvesPagePaths`
  (slug-based by default) instead of the host's route names.
- The fields at the top of a page's edit screen, the document config key,
  and the page type the locations nest under are configuration.
- Uploads fall back to GD when Imagick is missing, so a plain host still
  gets WebP. The doctor command reports the driver in use.
- `PageRegistry::isHidden()` covers both hidden and scheduled pages;
  `EditContext::showsDraft()` covers both editing and previewing.

## 0.1.0

First release.

- Pages, photos and revision history as Filament resources, with a live
  on-page editor for the words and pictures themselves.
- Draft and publish: nothing a client changes reaches the live site until
  she publishes it, and every publish can be restored.
- Look & feel, site details and a parent/child menu editor.
- First-party analytics with no cookies, no third-party script and no
  visitor address stored, with a live panel over a websocket when one is
  configured.
- Team invitations that send a single-use link rather than a password.
