---
name: gadya-cms-content
description: Help someone write, edit and publish on a site that runs Gadya CMS - pages, articles, photos, the menu, search snippets, the AI writer's voice, and what "publish" means. For editors and site owners, not developers.
---

# Working on a Gadya CMS site

## When to use this skill

Use it when the person is an editor, writer or site owner asking how to change something on their site, what a screen does, why a change is not showing, or how to write well for search engines and AI assistants. Answer in their words: pages, photos, publish - not slugs, JSON or routes. If they need code, hand over to `gadya-cms-development`.

## The one rule

**Nothing reaches visitors until Publish changes is pressed.** (And it asks *when* - leave the time blank for now, or set a time and the whole draft goes live then.) Saving in the admin, or editing on the page, changes the *draft*. Every screen that saves a draft has a green **Publish changes** button; the live editor's toolbar has **Publish**. If "I changed it and the site still shows the old one": they saved but did not publish. Every publish is kept under **History** and can be restored.

## Where things are

| They want to | Go to |
| --- | --- |
| Change words or photos on a page | Open the page on the site with **Edit on the page** (Pages → row action), click text to edit it, click a photo to swap it |
| Add, hide, rename, reorder, schedule a page | **Content → Pages** |
| Change the menu | **Appearance → Menu** - drag to reorder, a parent item holds children (a drop-down) |
| The announcement bar, phone number, footer text | **Appearance → Everywhere** |
| Colours and fonts | **Appearance → Look & feel** |
| Places on the contact map | **Appearance → Locations** |
| Write or edit an article | **Content → Articles**; **Write with AI** for a draft |
| File articles under categories, or tag them | **Content → Categories & tags**, or from the article itself |
| Read or approve comments | **Content → Comments** (nothing shows until you approve it) |
| Add an open day, a camp, a class | **Content → What's on** |
| See who is on the mailing list, or export it | **Content → Mailing list** |
| Copy a page or an article to start the next one | **Duplicate**, on its row |
| Get back a page you deleted | **Content → Pages**, the **Trash** filter |
| Upload or organise photos | **Content → Photos** (folders, tags, "Describe" for alt text) |
| Read what visitors sent | **Content → Enquiries** (opening one marks it read) |
| See visitors, searches, page speed | **Dashboard** |
| Forward an old web address | **Settings → Redirects** |
| Invite someone | **Settings → Team** (they get an email link; no password is sent) |
| Set up the AI writer | **Settings → AI** |
| Close the site while you work on it | **Settings → Coming soon mode** |
| Change the thank-you email a form sends | **Settings → Automatic replies** |
| Find out who changed something | **Settings → Activity** |
| Fix a link that goes nowhere | **Settings → Broken links** |
| Connect Google Search Console / PageSpeed | **Settings → Search & speed** |
| Show a draft to someone without an account | **Share a preview** on a page or article - a link that expires |

Which of these a person sees depends on their role: a *contributor* writes articles and manages photos; an *editor* does everything but settings and the team; an *administrator* does everything.

## Pages

- **Hidden** pages disappear from the site, the menu and every card at once. **Show from / Hide after** schedules a page; the day arrives and it appears, nothing to press.
- **Address** (the slug) is fixed after creation; use **Change address** to rename with a redirect, so old links keep working.
- **In search results** on each page: title (30-60 characters), description (140-160), the photo used when shared. Leave blank and the page's own heading and description are used. **Write with AI** fills them from the page.
- Sections are the blocks of a page (cards, a gallery, a text grid). Their words and photos are edited on the page; their order and kind in the admin.

## Articles

- A draft is only visible in the admin. *Published* with a future date is *Scheduled*.
- **How findable is it?** re-scores on every save; the list says what would raise the score (keyword in the title and first hundred words, three or more sections, two links to your own pages, three questions and answers, a photo with alt text, an excerpt).
- **Questions and answers** render under the article and are read by search engines and assistants as FAQ - write each answer so it stands alone.
- **Rewrite with AI** replaces every word but keeps the address; read it through before publishing. Nothing AI writes is published on its own.

## Writing the AI's voice (Settings → AI → Voice)

The writer sounds like whatever is written here, so be concrete:

- **What the business does**: services, what makes it different, anything a new writer would need on day one. Two to five sentences.
- **Who it is for**: the actual customer ("parents of 3-8 year olds in Staten Island"), not "everyone".
- **Tone**: three adjectives and an example line.
- **House rules**: what it must never claim (prices, guarantees, awards), words to avoid.
- The service area and phone number go into every closing call to action.

## Photos

- Upload once; the site makes the sizes it needs. Prefer landscape, at least 1600px wide.
- **Keep this part in view** decides what stays in frame when a page crops the photo. If a face is being cut off, that is the setting.
- **Describe with AI** writes the description by looking at the photo; correct it if it is wrong.
- **Describe** every photo (what is in it, one sentence) - screen readers and image search read it.
- A photo still on a page cannot be deleted; the message says where it is.

## Good snippets and headings

- Title in search results: the page's subject first, the business name last, under 60 characters.
- Description: a plain sentence saying what is on the page and why to click, 140-160 characters, no exclamation marks.
- One H1 per page (the heading); sections as H2; never skip a level.
- Answer the reader's question in the first paragraph; assistants quote opening paragraphs.

## Categories, tags and comments

- A **category** is a shelf ("Party ideas"); keep them few. A **tag** is a word articles share; have as many as you like. Each gets a page of its own, and only categories with something in them are shown.
- Comments wait for you. The email carries the whole comment, so you can judge it without opening the panel; **Show it** puts it under the article, **Spam** throws it away.

## When something looks wrong

1. Did they publish? (See the one rule.)
2. Is the page hidden or scheduled? Pages list shows a badge.
3. Is someone else editing? The toolbar says who holds the lock; wait, or ask them to exit.
4. Is the photo still processing? The photo library shows *processing* for a moment after upload.
5. A preview link expired? They last three days; share a new one.
6. Is the site in coming-soon mode? You can see it because you are signed in; a visitor cannot. **Settings → Coming soon mode**.
7. Is a publish waiting for a time? The dashboard says so at the top.

## Numbers on the dashboard

- **Visits** are pages opened; **People** are counted once a day each. Nobody's address is stored and no cookie is set, which is why there is no cookie banner.
- **Got in touch** is phone taps plus forms and bookings, as a share of people.
- **In Google search** appears once Search Console is connected; it lags two days.
- **Page speed** is Lighthouse on a phone; green is 90 or more.
- **Ready for AI assistants** lists what would help assistants find and quote the site; each item names its fix.
- **What people searched for here** - and especially what they searched for and did not find - is the clearest list of what the site is missing, in the visitor's own words.
