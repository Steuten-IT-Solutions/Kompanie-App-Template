---
titel: Status and updates
---
Here you see at a glance whether the app is running smoothly, which version is installed and whether there are updates. You also find the error log, the status reports to the app’s maintainers and (with WordPress) the request log here.

![The Status and updates page with the traffic light](bild:admin-zustand)

## How to open this page
Tap **Admin** at the bottom, then **Settings** and **Status and updates**.

## What you see here
### App status
A traffic light with one row per topic. Each row says in a short text what is going on and what to do. If everything is fine or a topic isn’t set up, only the topic’s name is shown; the app shows the short text when something needs doing (yellow or red). What each topic means is described below.
- **Updates**: whether the app is up to date, whether an update is pending or has failed.
- **{bereich.gallery}**: when the last sync with Google Drive was, whether it worked and whether pictures are still being prepared or couldn’t be loaded.
- **Calendar**: whether and how the calendar is set up and whether the connection with Google may read it.
- **Backup**: when a backup was last downloaded or stored in Google Drive.
- **Legal notice and privacy**: yellow as long as the link to the legal notice or privacy policy is missing. See [Legal notice and privacy](seite:admin-rechtliches).
- **Server**: which PHP version and database are running; yellow if less than 200 MB are free on the web space.
- **Push notifications**: how many devices are registered, or what the server is missing to send notifications.
- Only without WordPress: **Data protection** (whether the database and login details could be retrieved from the internet; if the row is red, have it fixed by the hosting provider immediately), **Encrypted connection** (yellow if the app runs via `http://` instead of `https://`; then passwords and data travel through the network unencrypted) and **Background tasks** (whether a cron job at the hosting provider does the tasks, with the address for it).

The colours: **green** means everything is fine. **Yellow** means something should be done, e.g. a new backup. **Red** means something isn’t working. **Grey** means switched off, not set up yet or not available in this installation. If a contact address of the maintainers is stored for you, it is shown below under **Help**.

### Updates
- **Installed: …** with the time of the last check and the **What’s new** link.
- **Maintenance until …** or **Maintenance: unlimited**: how long your app receives new versions, if the app’s maintainers specify this. If maintenance has expired, this is shown here in red.
- **✓ The app is up to date.** or **New version: …** with the **Update to … now** button.
- **The automatic update to … was postponed**: only if the backup in Google Drive didn’t work before an automatic update, with the buttons **Update anyway** and **Cancel**.
- **Check for updates** and **Reload status**.
- **Install updates automatically (recommended)** and **Receive pre-releases (for testing)**.
- **Go back to an older version**: to expand, if there are earlier versions.
- **Upload update package**: to expand, if you want to install an update as a file.
- **Send status reports to the app’s maintainers**: only if your app provides for status reports.

### Error log
The errors of the last 14 days (at most 50) with time, type of error and full error text, with the **Copy** and **Clear** buttons. If there were none, it says **No errors in the last 14 days.**

![The error log with two entries](bild:admin-zustand-fehlerprotokoll)

### Request log
WordPress only. With the switch **Record requests (7 days)** the app writes down for one week which requests reach your web space and run PHP: from the app, the website, WordPress itself, crawlers and cron jobs. Then it switches itself off. The overview below has the sections **By type**, **Per day**, **Per hour**, **Most frequent addresses**, **Slowest addresses (3 calls or more)**, **Who is asking (user agent)** and **Where do the calls to wp-cron.php come from?** (the server itself, a cron job of your hosting provider or another service, with shortened IP address). **Delete recording** removes everything straight away.

## Step by step
### Get the current status
1. Tap **Reload status**.

### Install a new version
1. Tap **Check for updates**. The app also checks by itself once a day.
2. If there is a new version, tap **Update to … now**. This takes a few minutes, longer with a backup in Google Drive. A bar shows the step (load package, back up data, backup in Google Drive, install), and for backing up and uploading also the MB; the size of the backup is estimated from the last one (“approx.”).
3. The app then reloads.

Leave the page open until the app reloads. If the connection drops in between, the update continues on the server and the app waits for it. If an update is already running when you open the page (e.g. the automatic one), it shows its progress.

The app bridges short outages at Google (e.g. “HTTP 502”) itself: it retries the upload up to four times after a pause. If the backup in Google Drive fails before the update (e.g. because the storage is full), the app asks: if you confirm, it installs the update anyway; the backup on the server is then there. If you cancel, the previous version stays.

### Decide on a postponed update
Nobody can be asked before an automatic update. If the backup in Google Drive then fails, the app doesn’t install the update and shows the reason under **Updates**.
1. Tap **Update anyway** to install it with the backup on the server, or
2. **Cancel**: then the app tries again at the next daily check, with a backup in Google Drive.

If you don’t decide, the app also tries again the next day; if it works then, the note disappears.

If the new version can’t run on your server (e.g. PHP or WordPress version too old, missing write permissions), the reason is shown above it and the button is locked. This has to be fixed first.

### Install a new major version
1. Read the notes on the version (under **What’s new** or from the app’s maintainers).
2. Tap **Install major version** and confirm the question.

The app never installs a new major version automatically. It backs up the data first. Going back to the previous major version afterwards isn’t possible with a button, only with the maintainers’ help.

### Go back to an older version
1. Tap **Go back to an older version**.
2. Under **Version**, choose an earlier version of the same major version.
3. Tap **Roll back** and confirm the question.

The app then no longer installs the version you came from automatically, only the next newer one.

### Upload an update as a file
This is how you install a particular version without the server downloading it itself, e.g. a pre-release for testing or if the server can’t download the packages.
1. Download the package of the version: `kompanie-app-standalone.zip` for the standalone app, `kompanie-app-wordpress.zip` for the WordPress plugin (from your app’s download page or from the maintainers). Don’t unzip it.
2. Tap **Upload update package**, then **Choose package …** and choose the file. A counter shows how far the upload has got.
3. The app says which version is in the file and whether it is older than the installed one or not yet released to everyone. Confirm the question.

After that it continues as with a normal update: first back up, then install, then the app reloads. The app only installs an unchanged package that is in your app’s checked update list. It rejects a changed file, a package of another app or a withdrawn version.

### Talk to your hosting provider about response times
1. Turn on **Record requests (7 days)**.
2. After a few days, look at the overview: how many requests come from crawlers instead of browsers? Which addresses are slow? Who calls wp-cron.php?
3. Pass the figures on to your hosting provider when they ask about the cause of the load.

### Send the error log to the maintainers
1. Look through the log briefly: error texts may contain names or content.
2. Tap **Copy** and paste the text into a message to the maintainers. If copying doesn’t work, the app shows the text for selecting.
3. Once the problem is fixed, you can delete the log with **Clear**.

## Good to know
- **Automatic updates:** as long as **Install updates automatically (recommended)** is on, the app installs new versions of the same major version itself. It checks once a day and installs a suitable version straight away; there is no fixed time. Without a cron job at the hosting provider this only happens when someone opens the app (with WordPress: the website); if it is rarely opened, updates come correspondingly later (see **For advanced users** below). With WordPress the website can block automatic updates; then this is shown here, and you install new versions by hand.
- **Precaution:** before every update (also when rolling back) the app automatically backs up the data on the server; the last five of these backups are kept. If the [backups in Google Drive](seite:admin-updates-und-sicherung) are switched on, the same backup also goes there; the last five are kept there too. If the backup on the server fails, there is no update. If only Google Drive fails, the app asks (see above). If an update fails, the previous version keeps running and the reason is shown here.
- **Withdrawn version:** if a faulty version is withdrawn, the app switches to a checked version by itself (unless automatic updates are blocked); **Switch to … now** does it straight away. If there is no checked version yet, contact the app’s maintainers.
- **Maintenance:** your app receives new versions as long as maintenance runs; the day given still counts. After that the app keeps running with the installed version, without restriction. New versions, including security updates, are only available after an extension; then the current version comes at the next check. You can still go back to an older version. To extend, contact the app’s maintainers.
- **Pre-releases:** with **Receive pre-releases (for testing)** this installation gets every new version as soon as it is built, even before it is released to everyone. This is meant for a test site. If you switch it off, the current version stays installed until a newer one is released to everyone.
- **Status reports:** if you agree, the app sends the maintainers a short sign of life every hour (among other things version, colour of the traffic light per row, error codes with cause, e.g. “Calendar: HTTP 404”, and usage figures without names: how many accounts there are, how many of them were active in the last 24 hours, 7 and 30 days and how many people used which section) and reports serious errors immediately. Names, content and error texts are never sent. What exactly is sent is shown under **What exactly is sent (example: sign of life)**; above it is when the last report went. If you withdraw consent, the counted errors are deleted too.
- **Error log:** it stays on your server and isn’t sent, even without consent to the status reports. Identical errors directly after each other are only listed once, with number and period. Addresses are shortened to the server; this way, e.g., a secret calendar address stays secret.
- If your installation doesn’t get updates via the app, the **Updates** section only shows the installed version.
- **Request log:** it stays on your server and is not sent. For each request it stores the time, the address without parameter values (`?s=search` becomes `?s`), duration, status code, whether someone was signed in, the user agent and the shortened IP address (e.g. `203.0.113.x`): this way networks of crawlers can be recognised, individual visitors cannot. Names, accounts and content are not stored. Each day is deleted after 14 days. What the web server delivers without PHP (images, app files, pages from a page cache) does not appear in the log; only your hosting provider’s access log shows that. Mention the log in your privacy policy while it is running.

> **For advanced users:** A *cron job* is a job in your hosting provider’s customer menu that calls an address regularly. Without it, the app does its background tasks (syncing the {bereich.gallery} and calendar, updates) after page visits. A cron job that calls the address shown under **Background tasks** every 15 minutes is more reliable. With WordPress this row doesn’t exist; there the cron job replaces WP-Cron, as described in the guide to installing with WordPress.

What you can do about typical messages is described under [Common problems](seite:haeufige-probleme).

## See also
- [What’s new](seite:admin-neuerungen)
- [Backup](seite:admin-updates-und-sicherung)
