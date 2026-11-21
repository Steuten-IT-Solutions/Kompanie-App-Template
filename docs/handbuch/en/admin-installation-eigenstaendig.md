---
titel: Installation without WordPress
---
This is how you put the app on your own web space if your website doesn’t run on WordPress. The app then gets its own address, e.g. `your-website.de/app/`, and its own accounts.

## What you need
- **Web space with PHP 8.1 or later.** Web space is the storage at your hosting provider (the company where your website lives). PHP is the programming language the app runs in on the server; almost every web space has it. The version can usually be changed in the hosting provider’s customer menu.
- **Access to the hosting provider’s file manager** (or via FTP, a program for uploading files).
- You don’t need to create a database. The app then stores its data in its own file on the web space.

## Step by step
### Install the app
1. On the company’s download page, download the file `install.php`.
2. In the hosting provider’s file manager, create a folder `app` and upload the file into it.
3. Open `your-website.de/app/install.php` in the browser. Under **1. Server prüfen** (check server) the installer says whether everything is there and, if not, what is missing. The installer is in German.
4. Under **2. Einrichten** (set up), check the **Adresse der App** (address of the app).
5. Under **Erster Admin** (first admin), enter **Name**, **Benutzername** (username), **E-Mail (optional)** and a **Passwort** (password, at least 10 characters) and repeat the password.
6. For the database, leave **Automatisch (SQLite-Datei, empfohlen)** (automatic, SQLite file, recommended). Only if you want to use a MySQL or MariaDB database that you created in the hosting provider’s customer menu do you choose it and enter its login details.
7. Click **App installieren** (install app). The installer downloads the app, sets it up and then deletes itself. If deleting doesn’t work, it says so; then delete `install.php` in the file manager.
8. Log in with the account you just created. The app guides you through the [setup assistant](seite:admin-einrichtung).

### Make background tasks reliable (recommended)
The app does tasks in the background, e.g. syncing the {bereich.gallery} and calendar and the updates. This also works without you doing anything: after a visit to the app, it does any tasks that are due without making the visitor wait. If the app is rarely opened, though, the tasks are left undone.

It is more reliable with a **cron job**: a job in the hosting provider’s customer menu that calls an address regularly.
1. Copy the cron address. The installer shows it at the end; it is also under **Settings → Status and updates** in **Background tasks**.
2. In the hosting provider’s customer menu, create a cron job that calls this address every 15 minutes.
3. After a while, check under **Background tasks** whether it says that a cron job is doing the tasks (see [Status and updates](seite:admin-zustand)).

> **For advanced users:** The key in the cron address (`key=…`) ends up in the web server’s access log. If the cron job can send a header, that is better: `curl -s -H "X-Kompanie-Cron-Key: <key>" "<cron address without &key=…>"`. The key only triggers background tasks that are due.

### Repair or reinstall the app
Upload `install.php` into the `app` folder again and open it in the browser. After an {rolle.admin} logs in, the installer offers **Aktualisieren / Reparieren** (update / repair): the current version is installed again, the data is kept.

## Good to know
- If WordPress is already running on the web space, the installer doesn’t set anything up and recommends the plugin instead.
- After that the app installs updates itself (see [Status and updates](seite:admin-zustand)).
- The app’s data must be protected against access from the internet. The app checks this itself and shows the result under **Status and updates** in **Data protection**.
- The app should only run via `https://`, otherwise members’ passwords and data travel through the network unencrypted. The hosting provider sets up a certificate, often for free. If the app is at the root of the address (e.g. `https://app.example.org/`), it tells the browser via HTTPS to only open the address encrypted from now on (HSTS); if you don’t want that, enter `'hsts' => false` in `config.php`. In a subfolder (e.g. `https://example.org/app/`) this would also apply to the other pages under the same address; there the app only sends it with `'hsts' => true`.

> **For advanced users: servers with nginx.** Most hosting providers use the Apache or LiteSpeed web server; then the app sets everything up itself (via the `.htaccess` files). If your web space runs **nginx**, the server ignores these files. The app still runs, but ask the hosting provider to block the folders `data`, `src`, `core` and `bin` in the app folder from outside access (the installer checks this and otherwise stores the data outside the web directory). To make the app load faster, files under `app/assets/` should also be cached in the browser for a year (`Cache-Control: public, max-age=31536000, immutable`; their name changes with every version), icons and manifest under `app/` for a day, and text files (HTML, CSS, JavaScript, JSON, SVG, manifest) delivered compressed (gzip).

## See also
- [Setup assistant](seite:admin-einrichtung)
- [Status and updates](seite:admin-zustand)
