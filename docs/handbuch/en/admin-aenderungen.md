---
titel: Changes
---
Here you see who created, changed or deleted what in the app and when, with the old and new values. This way you can find out, e.g., who deleted an event or changed a person’s role.

## How to open this page
Tap **Admin** at the bottom and then **Changes**.

## What you see here
- **Filter**: the period with **From** and **To** (at first the last 30 days, at most two years), **Who** (everyone or one person) and **Area**.
- **Download as CSV file**: all entries of the filter, one row per changed field, e.g. for Excel.
- The entries by day, newest at the top. Each entry says what it is, the time, who it was and the area.
- What was changed in one go is shown on one line, e.g. **3 × Punkte created** when you gave points to three members. Tap it to see each individual change with its values.
- Tap an entry to see the old and the new value for each field, e.g. under **Rolle** the old and the new role, separated by →. When something is created, only the new value is shown, when it is deleted only the old one.
- **Settings** (only for {rolle.admin} accounts): the switch for the log and the period after which entries are deleted.

The log records the type of entry, the field names and texts such as **System (automatisch)** in German, so they also appear in German in the English app, e.g. **Mitglied “Max Muster” changed**. Only the action (created, changed, deleted) is in English.

## What is recorded
- **Members and accounts**: creating them (also several at once, by import or by approving a request), changing details and personal details, role, additional roles and permissions, rank, title, companies, photo, date of joining, date of birth, marking as former and taking back in, generating a new password, deleting. Also what someone changes on their own account.
- Creating, changing and deleting **roles** and their order.
- **Settings** such as email, areas, navigation, notifications, maintenance mode, updates and backups. Also who installed an update or created or restored a backup. If someone changes a password or a key, e.g. the SMTP password, only **changed** is shown.
- **What happens by itself**, with **System (automatisch)** instead of a name: automatic updates, replacing a withdrawn version, automatic backups and deleting the personal details of former members after the retention period.
- **On the server**: what happens on the command line (e.g. a new admin account or password) or with `install.php`.
- **Under WordPress** also the app’s settings page in the back end and user management: creating and editing users, changing their role and deleting them.
- **Everything in the areas** that is created, changed or deleted, e.g. events, news, pages, points, penalties, ranks, titles, offices and terms of office, lists, attendance, documents, messages on the board, polls, inventory, emails and mail groups, photos, albums and submissions of the gallery. Also the order, e.g. of the ranks.
- **Bug reports**: who closed a report or forwarded it to the maintainers.

Not recorded are logging in, votes in polls, turning notifications on and off on a device, tests (e.g. a test email), syncing the gallery with Google Drive and the regular clean-up after fixed periods (e.g. expired messages on the board, old emails and requests).

## Step by step
### Find out what someone deleted
1. In **Filter**, choose the period and, under **Who**, the person.
2. Look for the entry with **deleted** and tap it. You see what the entry contained before it was deleted.

### Turn the log off or change the period
1. Right at the bottom under **Settings**, untick **Log changes** and confirm the question. All existing entries are deleted immediately.
2. Under **Delete entries after**, enter the period in days (30 to 730) and tap **Save retention period**. A shorter period deletes older entries immediately.

## Good to know
- The log is switched on at first and the period is 365 days. It contains personal data. Inform your members about it, e.g. in the privacy policy.
- You only see the values of personal details (email address, mobile number, address, date of birth) with the permission **See personal details**. Otherwise it only says that they were changed.
- Passwords, credentials and keys never go into the log; for photos, logos, badges and files only the fact that they were changed.
- For pages, news and messages on the board the content is shown as plain text. Long values are shortened.
- When an account is deleted, the entries about this person lose their values. What remains is, e.g., **Mitglied “Max Muster” deleted** with the name of whoever deleted it. What the person changed themselves stays with their name until the end of the period.
- The entries stay on your server and are not included in the backup. In the information about a person (**My details**) the entries about them are listed with values and the entries they made without values.
- Other plugins that write directly to the database bypass the app; that is not in the log.
- While you are viewing the app as another role, you can’t change anything, so no entry is created either.
- {rolle.admin} accounts and everyone whose role has the permission **View changes** may see the log.
