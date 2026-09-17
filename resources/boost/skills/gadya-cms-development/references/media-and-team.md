# Photos

Uploads are staged on a local disk and processed on the queue: resized to `gadya-cms.media.max_edge`, stripped of metadata (including GPS), written as WebP with a thumbnail, and optimised with `jpegoptim`, `pngquant` and `cwebp` when the server has them. Imagick is used when installed - it handles HEIC and large photos better - and GD otherwise. `php artisan gadya-cms:doctor` says which.

The document stores the filename, never a URL, so a photo can be re-processed or moved without every page that uses it going stale. `@siteImage($ref)` resolves it.

- **Folders and tags** - a folder is a label on the row (nothing moves on disk); filter by it, or move several photos at once.
- **Used on** - every page and article a photo appears on, from both the draft and the live document.
- **Deleting** - a photo still in use is never deleted, singly or in bulk; the panel says where it is.
- **Describe** - alt text, for screen readers and image search.

Images already shipped with the site (`public/images/site`) are indexed as *legacy* photos by `gadya-cms:import-legacy-media` and never deleted from disk.

# Team

**Settings → Team** lists everyone who may work on the site. An invitation is an email with a single-use, expiring link to choose a password; no password is ever sent. It carries a password-reset token and rides the panel's own reset flow, so the panel needs `->passwordReset()`.

Two rules hold whatever the request says: nobody can remove themselves, and the last administrator can be neither removed nor demoted. The list shows who has signed in and when, so a stale invitation is obvious.

Roles are the application's own strings; the panel needs only the labels and which role is the administrator (`gadya-cms.users`).
