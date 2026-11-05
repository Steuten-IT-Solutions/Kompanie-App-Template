# Website

Status: **data model and API contract only.** Migration 38 creates the tables; the routes in `api/openapi.yaml`
(tag `Website`, `x-status: planned`) are not implemented yet. The architecture and the decisions behind it are in the
architecture draft (step 2 of the website plan). This file describes what the code is meant to follow.

Developer docs for the website are written in English (see the language decision for code, comments and docs). The
interface stays German.

## Operating modes

One package, two switches, chosen during setup and changeable later:

| Mode            | `website` | `app` |
| --------------- | --------- | ----- |
| Website only    | on        | off   |
| App only        | off       | on    |
| Website and App | on        | on    |

The Verwaltung (admin backend) is always there. The program stays in `/app/`; the website is served at the domain root
through a small entry file (`index.php` that only requires the install, plus `.htaccess`). Without URL rewriting pages
are reached as `/?page=…`.

Neither switch is part of this change yet.

## Data model (migration 38)

```
site_pages ─┬─< site_page_translations ─┬─< site_page_revisions
            │   (one per language)       └── draft_revision_id / published_revision_id
            └─< site_menu_items (page_id, optional)
```

- **`site_pages`**: the page itself, independent of language. `parent_id` allows two levels. `is_home` marks the home
  page (path `''`). `allow_person_indexing` defaults to `0`: a page with blocks that show people (members, offices) is
  `noindex` unless an editor switches this on.
- **`site_page_translations`**: one row per page and language (`UNIQUE (page_id, lang)`). `path` is the full path
  without language prefix and unique per language (`UNIQUE (lang, path)`), e.g. `de` + `ueber-uns` → `/ueber-uns`,
  `en` + `about-us` → `/en/about-us`. The default language has no prefix. `path` is `varchar(180)` so the unique key
  stays below 767 bytes in utf8mb4.
- **`site_page_revisions`**: immutable snapshots of one translation: title, meta description, header picture (a rich
  image uid) and the blocks as JSON. Saving the draft adds a revision and moves `draft_revision_id`; publishing moves
  `published_revision_id`. Restoring an old revision copies it as a new draft. Retention: the published revision, the
  draft and the 50 newest others.
- **`site_menu_items`**: entries of the `main` and `footer` menus, two levels in `main`. An entry links either a page or
  an address. `labels` is a JSON object per language; an empty label for a page uses the page title.

Images in blocks are rich images (`rich_images`, `owner_type = 'site_page'`). All four tables are in
`Backup::CORE_TABLES`. None has a `user_id` column; `created_by`, `updated_by` and `published_by` name editors like
elsewhere, so they are not personal data of the person in the sense of `UserData`.

Website settings (languages, default language, imprint and privacy pages, menu layout, header picture on/off) go into
the existing settings store, not into a table.

## Blocks

Stored as a JSON list in the revision; schema `SiteBlock` in `api/openapi.yaml`. First block types: `text` (rich text,
cleaned with the `RichText` allowlist), `image`, `events`, `news`, `gallery`, `members`, `offices`. Blocks that show data
from the App render only what a guest may see and nothing while their feature is off, like the WordPress shortcodes do
today. Their renderers move from `adapters/wordpress/includes/Shortcodes.php` into the core so that the standalone
website, the editor preview and the Gutenberg blocks share them.

## Publishing rules

- Nothing is published in a language until that language has a published imprint (§ 5 DDG); the imprint page itself can
  always be published (`409 imprint_missing`).
- Imprint and privacy policy are always linked in the footer and cannot be deleted or taken offline while other pages
  are online.
- The App already has links to imprint and privacy policy (*Einstellungen → Impressum und Datenschutz*, settings
  `legal_imprint_url` and `legal_privacy_url`). While those are empty and the website has published imprint and privacy
  pages, the App links to these pages; links the admin entered win.
- Saving and publishing use the loaded revision as a check (`409 conflict` if someone else saved in between).
- Publishing clears the page cache. The cache must also be cleared after background syncs (events, news, gallery) and
  when the date changes, because blocks show upcoming events.
- Visitors get no cookies and no requests to other servers.

## WordPress

WordPress keeps managing pages, menus and the theme. The plugin only adds Gutenberg blocks (the shortcodes stay). There
only `/admin/site/blocks` and `/admin/site/preview` are needed. News on WordPress keeps coming from WordPress posts.

## Not part of this change (follow-ups)

- Right `manage_website` in `Capability` (needs `member`, like the other management rights) and the switches
  `website` and `app` in `Features`.
- `POST /rich-images` for `manage_website`, and serving images of published pages without login.
- Repository and service for the tables, the routes, the server-side renderer and the entry file with its status check.
- News written in the Verwaltung for the standalone version (source `local`, de/en).
