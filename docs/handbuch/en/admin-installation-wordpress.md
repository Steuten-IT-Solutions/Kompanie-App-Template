---
titel: Installation with WordPress
---
If your website runs on WordPress, you install the app as a plugin, i.e. as an extension of WordPress. The app then becomes part of the website: users, login and at first also the {bereich.news} come from WordPress.

## What you need
- WordPress 6.2 or later and PHP 8.1 or later. PHP is the programming language WordPress and the app run in on the server; the version can usually be changed in the hosting provider’s customer menu.
- An administrator account on the WordPress website.

## Step by step
### Install the plugin
1. On the company’s download page, download the **plugin**. Don’t unzip the file.
2. In WordPress, open **Plugins → Add New Plugin → Upload Plugin**, choose the file and click **Install Now**.
3. Click **Activate**. The app can then be reached at `your-website.de/app/`.
4. Open the app. As an administrator, it guides you through the [setup assistant](seite:admin-einrichtung).

### Show the app’s content on the website
On WordPress pages and in posts, these shortcodes show content from the app. You simply write them into the text of the page:
- `[kompanie_termine]`: the public {bereich.events}
- `[kompanie_kalender]`: the events from the internal {bereich.calendar}, for a page in the protected area of the website. Unlike the other shortcodes, it only shows the events to logged-in people who also see the {bereich.calendar} in the app. Without logging in only a note is shown. With `[kompanie_kalender anzahl="10"]` you limit the number of events (20 without a value, all upcoming ones with `anzahl="0"`, about a year ahead).
- `[kompanie_galerie]`: the {bereich.gallery}
- `[kompanie_aemter]`: the {bereich.offices}
- `[kompanie_mitglieder]`: the {bereich.directory} as a picture gallery

### Choose a placeholder image for members without a photo
In `[kompanie_mitglieder]`, people without a photo are shown with their initials. Instead, your own image can be shown there, e.g. a silhouette or the logo.
1. In the WordPress back end, open the app’s page under *Settings* and there the **Website: members without a photo** section.
2. Click **Choose an image from the media library**, upload or choose an image and confirm it.
3. It applies straight away. A portrait image in 2 : 3 fits best; other formats are cropped in the centre.

**Remove placeholder image** brings the initials back, as does deleting the image from the media library. In the app itself the initials stay.

### Load the app faster
Every time the app is called, WordPress starts with all active plugins, including ones the app never needs, such as page builders, statistics or forms. On some web spaces every call therefore takes a second or longer. When switched on, WordPress loads only the app itself and the plugins you choose when the app is called, without the theme if you like. The website, the WordPress back end, login and scheduled tasks keep running with all plugins, as do the {bereich.news} from the website’s posts. Nothing changes in the database.
1. In the WordPress back end, open the app’s page under *Settings* and there the **Load the app faster** section.
2. Click **Measure now**. After a few seconds you see how long a call of the app takes with all plugins and how long when fast.
3. Tick plugins the app might need: security plugins (firewall, protection against too many requests), plugins that change user roles and plugins that store media library images elsewhere. If in doubt, tick it.
4. **Also load the theme in the app** is ticked. The app shows nothing of the theme; but some themes change things that also affect the app, such as the uploads folder. If you untick it, the app usually gets a little faster. Measure beforehand and check the app afterwards.
5. Switch on **Load only the plugins needed when the app is called** and click **Save**.
6. Open the app and check that everything works as usual.

For this the plugin creates the file `kompanie-app-schnell.php` in the `wp-content/mu-plugins/` folder and removes it when deactivated. If it may not write there, the section shows a note with the file to download; then upload it into this folder via FTP.

**Emergency switch:** if the app doesn’t run properly after switching on and you can no longer get into the back end, add the line `define('KOMPANIE_APP_SCHNELL', false);` to `wp-config.php`. The app then loads all plugins again until you remove the line.

## Good to know
- **Settings:** you find the app’s settings in the app under **Admin → Settings**. In addition, the WordPress back end has a page of the app under *Settings* with maintenance mode, sections, Google access, {bereich.calendar}, {bereich.gallery}, the placeholder image and “Load the app faster”. It helps if the app itself can’t be reached.
- **{rolle.admin} accounts:** an {rolle.admin} in the app is whoever is an administrator in WordPress. You only assign this role in WordPress. You choose other people’s role in the app or in the WordPress user profile under **Role in the app** (see [Roles](seite:admin-rollen-und-mitglieder)). Anyone allowed to create users in WordPress (e.g. because a plugin gives this to an editor role) may also do so in the app and therefore sees members’ email and phone.
- **Address:** without “pretty” permalinks (WordPress’s readable addresses), the app runs under a longer address; WordPress then shows it at the top of the back end. For the short address `/app/`, choose e.g. “Post name” under **Settings → Permalinks**.
- **{bereich.news}:** they come from the website’s posts; there is nothing to set for this. Anyone with the permission **Write {bereich.news}** opens the WordPress editor from the app. If you would rather write them in the app, choose that under [Set up {bereich.news}](seite:admin-neuigkeiten).

- **HTTPS:** the website should only run via `https://`, otherwise members’ passwords and data travel through the network unencrypted. WordPress or the hosting provider sets up the certificate (and HSTS) for the whole website.

> **For advanced users: permissions from WordPress.** The WordPress capabilities `create_users`, `promote_users` and `manage_options` count in the app as *Create members*, *Assign roles* and *Settings*. If you don’t want that, shorten the mapping with the filter `kompanie_app_wp_capability_map`, e.g. `add_filter('kompanie_app_wp_capability_map', fn () => []);`. Administrators of the website remain {rolle.admin} accounts of the app.

> **For advanced users: servers with nginx.** On the Apache and LiteSpeed web servers, the plugin itself makes sure the app’s files stay in the browser for a long time and arrive compressed (via `.htaccess`). If the website runs on **nginx**, ask the hosting provider to cache the files under `wp-content/plugins/kompanie-app/app/assets/` for a year (`Cache-Control: public, max-age=31536000, immutable`; their name changes with every version) and to deliver text files compressed (gzip). The app also runs without this, but loads more slowly when first opened.

> **For advanced users: background tasks (WP-Cron).** The app does the syncing of {bereich.calendar} and {bereich.gallery}, notifications and the daily search for updates via WP-Cron. WP-Cron only runs when someone opens the website or the app; on rarely visited websites these tasks are therefore delayed, updates e.g. only after the next visit. A cron job at the hosting provider is more reliable: first create a cron job in the hosting provider’s customer menu that calls `https://your-website.de/wp-cron.php` every 15 minutes. Only then add `define('DISABLE_WP_CRON', true);` to `wp-config.php`; without the cron job the tasks otherwise stop running altogether. Some hosting providers have their own setting for this in the customer menu. A cron job every minute is not needed and puts load on the web space: the app sends notifications and emails right after the request that triggers them (on servers with PHP-FPM or LiteSpeed, which most hosting providers have); the other tasks run hourly or daily. Without PHP-FPM, notifications and emails wait for the next cron run; in that case every 5 minutes is better.

## See also
- [Setup assistant](seite:admin-einrichtung)
- [Settings](seite:admin-einstellungen)
