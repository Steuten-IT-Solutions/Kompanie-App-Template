# Website

Website for visitors (Issue #273). Developer docs for the website are in English; the interface and the manual stay
German.

## Decisions (13.11.2026)

- The website builds on the existing own pages (`app_pages`, `PageService`) and blocks (`Domain/Blocks`). There is no
  separate page model or block catalogue. The data model is to be prepared for two languages; the first steps are German
  only.
- The standalone version comes first. WordPress keeps managing its own pages; Gutenberg blocks follow later.
- PHP stays at 8.1 or newer.
- Steps: (1) area switch, right, entry file, home page; then drafts and publishing, more pages with addresses, a menu and
  the rule that nothing goes online before imprint and privacy policy.

## Step 1: what exists

- **Area `website`** (`Features::DEFINITIONS`, requires `pages`). It needs a place for the website: `App::$websiteHost`
  (`Port\WebsiteHost`). Without one (WordPress) the area is missing from the list and always off (`Features::HOSTED`).
- **Right `manage_website`** (needs `member`); switching the area on creates the role „Webseite verwalten“.
- **Settings** `website` = `{home_page_id}`. The home page must be an own page with audience `public`.
  `WebsiteService::homePage()` renders its blocks with `Blocks::forViewer($blocks, null, false, false)`: always as a guest,
  also for someone logged in to the App, so blocks for members never reach the website.
- **Rendering** in the core (`Website/WebsiteRenderer`, `Website/BlockHtml`, `Website/website.css`): plain HTML, one
  stylesheet from the own server, no scripts, no cookies, nothing from other servers. Links into the App (`#/…`) point to
  the App's address. Pages with people (block `contacts`) get `noindex`. Without a home page a short placeholder with the
  way to the App (`noindex`).
- **Routes** `GET`/`PATCH /admin/website`, `POST /admin/website/entry` (tag `Webseite` in `api/openapi.yaml`).
- **Status page**: `WebsiteService::statusItem()` while the area is on.

## Standalone version

The program stays in its folder (usually `/app/`); the website is served from the folder above it.

- `StandaloneWebsiteHost` checks and writes the entry file `index.php` there. Its content only defines
  `KOMPANIE_WEBSITE` and includes `<program folder>/index.php`, so it never changes with an update (updates replace only
  `StandaloneUpdateTarget::PROGRAM`). It carries the marker `kompanie-app-website`.
- States: `installed`, `missing`, `outdated` (ours, for another folder name), `occupied` (another `index.php`,
  `index.html` …: never replaced), `not_writable` (the Verwaltung shows the content for uploading by hand), `root` (the
  App is at the top of the address, so there is no folder above it).
- `Kernel::handleHttp()` answers through `handleWebsite()` when `KOMPANIE_WEBSITE` is defined: the home page with a
  strict CSP (`default-src 'none'; style-src 'self'; img-src 'self' data:`), `Cache-Control: no-cache`, no session. With
  the area off it redirects to the App. The stylesheet is the App route `webseite.css?v=<hash>`.
- Not yet: `.htaccess` and addresses for further pages (`/ueber-uns`, without rewriting `/?page=…`).
