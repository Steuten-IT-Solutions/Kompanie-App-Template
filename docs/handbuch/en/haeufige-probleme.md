---
titel: Common problems
---
Here, as {rolle.admin}, you find solutions to problems that come up often: for each problem it says what usually causes it and what you can do. There is no separate screen for this.

## First: look at the traffic light
1. Tap **Admin** at the bottom and then **Settings**.
2. Choose **Status and updates**.
3. See which line is yellow or red. The text next to it usually already says what to do.

More on this under [Status and updates](seite:admin-zustand). Where each setting is located is shown in [Settings](seite:admin-einstellungen).

## A section is missing in the app
1. Open **Admin** → **Settings** → **Sections**.
2. Check whether the section is switched on, and switch it on if needed.
3. If the section says “Not set up yet.”, tap **Set up now**. {bereich.calendar}, {bereich.gallery} and {bereich.news} only appear once they are set up too.

{bereich.polls} can only be switched on together with the {bereich.board}.

## Someone doesn’t see something they should see
1. Under **Admin** → **Members**, open the person.
2. Expand **Account and role** at the very bottom and check their role.
3. Under **Settings** → **Roles**, check which permissions this role has.

Permissions only work in sections that are switched on. Besides, each role only sees what it has a permission for: a role with the internal calendar but without **{bereich.documents}** (e.g. candidates) sees the calendar but no {bereich.documents}. What each role sees is shown by **Who sees what** under *Settings → Roles*. More on this under [Roles](seite:admin-rollen-und-mitglieder).

## Someone has forgotten their password
1. Under **Admin** → **Members**, open the person.
2. Expand **Account and role** and create a new initial password.
3. Pass it on to the person; they then change it under **My area** → **Account**.

See also [Create member](seite:mitglieder-anlegen).

## Photos in the {bereich.gallery} appear grey
The app processes new photos when they are first requested and in the background. With many new photos it briefly shows a grey placeholder for some and then loads them by itself. Usually waiting is enough.

> **For advanced users:** Without WordPress this goes faster with a cron job at your host; the address is in the **Background tasks** line of the traffic light. With WordPress it helps if the website is visited regularly.

## The {bereich.gallery} no longer synchronises
1. Open **Admin** → **Settings** → **{bereich.gallery}**. The error of the last synchronisation is shown there.
2. Tap **Synchronise now** to try again straight away.

If it still doesn’t work, it is usually for one of these reasons:
- The folder in Google Drive is no longer visible to the connected Google account or the service account, e.g. because the sharing was removed. Share it there again.
- The connection to Google has expired or was revoked. Under **Settings** → **Google access** tap **Disconnect** and connect with Google again.

If the connection keeps expiring after 7 days, the Google project is still in the “Testing” status, see [Google access](seite:admin-google).

## The calendar shows nothing
1. Open **Admin** → **Settings** → **Calendar**.
2. Tap **Test calendar**. The app checks whether the calendar can be reached.
3. If the traffic light reports that the connection with Google may not read the calendar: disconnect under **Settings** → **Google access** and connect again. Also tick the box for the calendar.

## Notifications don’t arrive
- The **Push notifications** line of the traffic light shows whether the server can send notifications. If something is missing there, only the host can add it.
- If it is the device (the browser’s request was declined, iPhone without the installed app), [Notifications](seite:benachrichtigungen) helps.

## New features don’t show after an update
An app that keeps running in the background still shows the version it was started with. As soon as it has loaded the new version, the note **The app has been updated** with **Restart** appears at the top. Anyone who dismissed it sees the new version the next time they open the app. If the note doesn’t appear, close the app completely and open it again.

## Updates don’t arrive
Under **Admin** → **Settings** → **Status and updates**, the **Updates** section shows what happened last and why a new version can’t be installed. Common reasons:
- **Automatic updates are blocked** (with WordPress e.g. in the website’s settings): install new versions there by hand.
- **Too little storage space**: the **Server** line turns yellow when less than 200 MB are free. Free up space or upgrade your plan with the host.
- **PHP or WordPress version too old**: PHP can usually be changed in your host’s customer menu; update WordPress first.
- **The backup to Google Drive didn’t work** (e.g. storage full, connection to Google disconnected): automatic updates then wait for your decision. Fix the cause under **Backup** or **Google access**, or tap **Update anyway** under **Updates**.

> **For advanced users:** If WordPress asks for FTP credentials, it may not write the plugin files itself; the host has to adjust the file permissions, or you upload the update by hand. Without WordPress: if PHP may not change the program files, check the file permissions with your host or upload `install.php` again and choose **Aktualisieren / Reparieren** (update / repair); the installer is in German only.

More on this under [Backup](seite:admin-updates-und-sicherung).

## The “Background tasks” line is yellow or red (without WordPress)
Synchronisations and updates then only run when someone opens the app.

> **For advanced users:** Set up a cron job at your host that calls the address shown in the **Background tasks** line every 15 minutes.

## The “Data protection” line is red (without WordPress)
Then files with data can be retrieved from the internet. Act immediately.

> **For advanced users:** The web server ignores the lock on the data directory. Have your host lock the `data` directory, or store the data outside the web directory.

## None of this helps
- If a contact address is stored under **Help** below the traffic light, get in touch there.
- Or report the bug directly from the app, see [Report a bug](seite:fehler-melden). The report goes to your admins first.
- If you have agreed to the status reports, the maintainers often see serious errors by themselves.
