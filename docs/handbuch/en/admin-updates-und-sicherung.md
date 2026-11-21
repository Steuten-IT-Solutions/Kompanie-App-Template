---
titel: Backup
---

Here you download a backup of all the app’s data, restore a backup, set up a password for backups or have the app store a backup in Google Drive or on your own server every week.

![The Backup page](bild:admin-updates-und-sicherung)

## How to open this page

Tap **Admin** at the bottom, then **Settings** and **Backup**.

## What you see here

- **Backup**: what the backup contains, with the buttons **Download backup** (with a checked backup password: **Download backup encrypted**; before the first check unencrypted, because a mistyped password would make the backup useless) and **Restore backup …**.
- **Backup password**: whether one is set up, with fingerprint and the buttons **Check password**, **Edit** and **Remove**.
- **Backups in Google Drive**: the switch **Back up weekly to Google Drive**, below it when the last backup was made, **Open folder**, how long the last upload took (see below) and **Back up to Google Drive now**. If the necessary connection to Google is missing, it says here what to do instead.
- **Backups on the server**: the switch **Back up weekly on the server**, below it when the last backup was made, the **Back up on the server now** button and the list of all backups on the server (weekly and before updates) with date, version and size. For each backup: the download icon, **Restore** and the delete icon.

## Step by step

### Set up a backup password

With this password, downloaded backups and all backups in Google Drive are encrypted: anyone who finds the file can’t read anything in it without the password.

1. Think of a long password (at least 12 characters, a sentence is fine) and keep it safe, e.g. in a password manager or printed out in the company’s safe.
2. Enter it twice under **Backup password**, tick **I have stored the password safely** and tap **Set up password**.
3. Check straight afterwards and from time to time with **Check password** that you still have it. Only after the first correct check (or a restore with this password) does the app delete older, unencrypted backups in Google Drive; those with an earlier password then rotate out like the others.

> **Important:** The app doesn’t store the password. **If it is lost, no encrypted backup can be restored any more, not even by the app’s maintainers.** A new password only applies to new backups; older ones still need the previous one.

### Download a backup

1. Tap **Download backup** or **Download backup encrypted**.
2. The browser saves a file `sicherung-<date>.json` (encrypted: `sicherung-<date>.json.enc`). Keep it in a safe place, not only on the server.

### Switch on backups in Google Drive

So that an up-to-date backup is somewhere else if the server is lost, the app regularly stores a backup in its own folder in the connected Google Drive. Don’t share this folder with others.

1. Connect the app under **Google access** via **Connect with Google** and tick the box for your own files in Google Drive (see [Google access](seite:admin-google)).
2. Recommended: set up a **Backup password** (see above). Then the backups go to Google Drive encrypted; without a password unencrypted (readable for anyone who can open the folder).
3. Switch on **Back up weekly to Google Drive** here.
4. If you want a backup straight away, tap **Back up to Google Drive now**.

### Switch on backups on the server

The app stores the backups in a protected folder on your own web space, where the backups before updates also are. They can’t be downloaded from the internet; only you as an {rolle.admin} can download them in the app.

1. Under **Backups on the server**, switch on **Back up weekly on the server**.
2. If you want a backup straight away, tap **Back up on the server now**.

> **Important:** The backups on the server help if data was accidentally deleted or changed. If the server or web space is lost, they are gone with it. So keep downloading a backup from time to time or also switch on the backups in Google Drive.

### Restore a backup from the server

1. In the list under **Backups on the server**, tap **Restore** on the backup you want.
2. Confirm the question. The backup replaces **all** the app’s data; the app then reloads.

With the download icon you save a backup from the server on your device, with the bin you delete it from the server.

### Restore a backup

1. Tap **Restore backup …**.
2. Choose the backup file. If it is in Google Drive, download it from there first.
3. Confirm the question. If the backup is encrypted, enter the backup password that applied when the backup was made and tap **Restore**.
4. The backup replaces **all** the app’s data; the app then reloads. In a freshly installed app, setup then counts as done: sections and connections come from the backup, the **Start setup** note disappears.

## Good to know

- **Content:** the backup contains all the app’s data, the settings, the access keys and the accounts (passwords only as hashes), plus the files of the {bereich.documents}, the badges of the {bereich.ranks} and the devices registered for notifications. With WordPress all users of the website are included. Treat an unencrypted file like a password.
- **Encryption:** the backup password belongs to this installation and doesn’t come back with a backup. After moving to a new server you set it up there again. The backups before updates on the server stay unencrypted: they are next to the database, and restoring should also be possible without a password.
- **Not included:** the photos of the {bereich.gallery} (the app fetches them again from Google Drive afterwards; with many photos this takes a few hours), videos uploaded in the app (too large for a backup file; keep the originals yourselves), waiting bug reports and the state of this installation (e.g. update status, error log). With WordPress, posts, pages, design and other plugins belong to WordPress itself; for those you need a backup of WordPress or from the hosting provider.
- **Automatic backups before updates:** before every update the app backs up on the server. These backups are lost with the server; if backups in Google Drive are switched on, each of them also goes into the folder there (the last five; with a backup password encrypted as `vor-update-<date>-<password ID>-<version>.json.enc`, otherwise compressed as `vor-update-<date>-<version>.json.gz`). If this doesn’t work, the app asks before the update (see [Status and updates](seite:admin-zustand)). Without Google Drive, also download these backups; you find them in the list under **Backups on the server**. The traffic light under [Status and updates](seite:admin-zustand) turns yellow if no backup has been downloaded or stored in Google Drive for 30 days.
- **On the server:** the app backs up once a week and keeps the last four weekly backups; the backups before updates (the last five) don’t count towards this and are also in the list. Like those, they are unencrypted because they are next to the database. They don’t count as a kept backup for the traffic light; if one fails, the reason is shown here and in the traffic light, and the app tries again the next day. The switch belongs to this server: restoring doesn’t change it. Each backup takes up storage on the web space, with many documents also a few hundred MB.
- **Google Drive:** the app creates its own folder in the connected Google account, uploads a backup once a week and keeps the last eight; a backup before an update counts as that week’s. It only sees and changes files there that it created itself. If uploading fails (e.g. because the storage is full), the reason is shown here and in the traffic light; the app tries again the next day. This doesn’t work with a service account. Before uploading, the app compresses each backup (gzip): it becomes about a quarter smaller, with few photos and documents considerably more, and uploading is correspondingly faster. Without a backup password the app uploads them unencrypted (`.json.gz`; they can be unpacked with any archive program, e.g. 7-Zip), with a password compressed and encrypted (`.json.enc`). To restore, choose the file from Google Drive as it is; the app unpacks it itself. Older app versions (up to 0.11.0) can’t restore compressed backups. The app deletes older, unencrypted backups in the folder as soon as the first encrypted one is there and the password has been confirmed once with **Check password**; until then it also keeps encrypted backups with an earlier password. Still don’t share the folder.
- **Upload duration:** **Last upload** shows how large the last backup was, in how many chunks (8 MB each) it went to Google Drive, how long that took and at what rate (Mbit/s). In addition there are the parts: **Connecting to Google** (a small request at the start), **Reading from disk** and the **slowest chunk**. The server uploads the backup, not your device: your internet connection plays no role. If the rate is low although connecting and reading are fast, the hosting provider’s line is usually the limit.
- **Restoring without WordPress:** also works on a new installation, e.g. when moving, but not from a backup of the WordPress version. Afterwards everyone logs in again. The devices registered for notifications only come along if the app runs at the same address as when the backup was made (moving to a new server). If you restore the backup at a different address, e.g. into a test app, restoring removes the existing registrations: otherwise messages from the test app would reach the other app’s devices. Switch notifications on again on the devices afterwards.
- **Restoring with WordPress:** only works on the same website (address). The app recreates missing accounts with their previous number; everyone logs in with their previous username and password. Existing accounts keep their login details and email and only get their role in the app from the backup. If an account number from the backup now belongs to someone else, restoring stops and names the account; nothing is changed then.
- A backup from a newer version can only be restored once the app has been updated. A restore can only be undone with another backup.

### After losing the website (WordPress)

1. Reinstall WordPress at the same address. Create the administrator with the **username and email of your previous account** (or with a name and email that didn’t exist before).
2. Install the company’s plugin and open the app.
3. Under **Settings → Backup**, restore the latest backup.

## See also

- [Status and updates](seite:admin-zustand)
- [Google access](seite:admin-google)
