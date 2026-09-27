# Photos

Uploads are staged on a local disk and processed on the queue: resized to `gadya-cms.media.max_edge`, stripped of metadata (including GPS), written as WebP with a thumbnail, and optimised with `jpegoptim`, `pngquant` and `cwebp` when the server has them. Imagick is used when installed - it handles HEIC and large photos better - and GD otherwise. `php artisan gadya-cms:doctor` says which.

The document stores the filename, never a URL, so a photo can be re-processed or moved without every page that uses it going stale. `@siteImage($ref)` resolves it.

- **Folders and tags** - a folder is a label on the row (nothing moves on disk); filter by it, or move several photos at once.
- **Used on** - every page and article a photo appears on, from both the draft and the live document.
- **Deleting** - a photo still in use is never deleted, singly or in bulk; the panel says where it is.
- **Describe** - alt text, for screen readers and image search, and **Describe with AI**, which looks at the photograph and writes the first draft. Every photo needs a description, on upload and whenever it is edited, unless it is marked **decoration** - a divider, a texture - whose right description is none: it is saved empty on purpose, and screen readers skip it. AI marks decoration it recognises as such.
- **Write missing alt text with AI** - select photos (the **Needs a description** filter finds them) and they are described in the background, ten at a time, with `AltTextWriter` on the client's own key or Gadya's through the portal. Decoration and photos already described are left alone. An upload can be described the same way: **Describe them for me with AI** is on by default when AI is available. **Settings → Speed & accessibility** says how many photos still need one.
- **Keep this part in view** - where a template that crops the photo should stay centred, chosen in words (top, left, middle) because that is how the complaint arrives. Render it with `@siteFocus`:

```blade
<img src="@siteImage($page['hero_image'], 1200)"
     srcset="@siteSrcset($page['hero_image'])"
     style="@siteFocus($page['hero_image'])"
     alt="...">
```

Images already shipped with the site (`public/images/site`) are indexed as *legacy* photos by `gadya-cms:import-legacy-media` and never deleted from disk.

# Team

**Settings → Team** lists everyone who may work on the site. An invitation is an email with a single-use, expiring link to choose a password; no password is ever sent. It carries a password-reset token and rides the panel's own reset flow, so the panel needs `->passwordReset()`.

When an email is the wrong way in - a colleague in the room, an inbox that eats automatic mail - **Add with a password** creates the account with a password made up for you, sends nothing, and shows the sign-in details (address, email, password, and how to change it) with a Copy button. **Set a new password** on a person's row does the same for someone who is locked out. The password is shown once.

Two rules hold whatever the request says: nobody can remove themselves, and the last administrator can be neither removed nor demoted. The list shows who has signed in and when, so a stale invitation is obvious.

Roles are the application's own strings; the panel needs only the labels and which role is the administrator (`gadya-cms.users`).
