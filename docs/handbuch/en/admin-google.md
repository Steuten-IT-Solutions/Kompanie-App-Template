---
titel: Google access
---

Through Google access the app reads the photos of the {bereich.gallery} from Google Drive and, if you like, a private Google Calendar. It can also use it to store a backup in Google Drive every week. Here you connect the app with Google once.

![The Google access page](bild:admin-google)

## How to open this page

Tap **Admin** at the bottom, then **Settings** and **Google access**. In the [setup assistant](seite:admin-einrichtung) the same section is in the step for the {bereich.gallery} or the calendar.

## What you see here

- **Redirect URI (enter it in the OAuth client at Google)**: the address you enter at Google, with the **Copy address** button (two sheets) to the right of it.
- **Client ID** and **Client secret**: the two values from Google, with the **Save and connect to Google** button.
- **✓ Connected to Google as …**: shown instead of the fields as soon as the connection exists, with **Disconnect**. After it, it says whether the app may also read the calendar.
- **Alternative: service account with JSON key**: to expand, for the second way (see below).

> For the calendar alone you don’t need Google access: the secret address in iCal format works without it (see [Set up the calendar](seite:admin-kalender)).

## Step by step

**Connect with Google** is recommended. For this you create a small project at Google once, enter two values in the app and sign in at Google. This takes about 20 minutes and costs nothing.

The steps at Google describe Google’s pages in English. If your Google account uses German, the buttons there have German names.

> **For advanced users:** Google calls what you create an _OAuth client_: an access with which your app may read on behalf of your Google account after you have agreed once. It is created in the _Google Cloud Console_, Google’s admin page for such accesses.

### 1. Create a project

1. Open [console.cloud.google.com](https://console.cloud.google.com/) and sign in with the company’s Google account. The first time, you accept the terms of service.
2. Click the **project picker** at the top left and then **New project**. Enter a name, e.g. “Kompanie-App”, and click **Create**.
3. Check that the new project is selected at the top. All further steps apply to this project.

### 2. Enable the interfaces

In the project, open these pages and click **Enable** on each:

- for the {bereich.gallery} and the backups: [Google Drive API](https://console.cloud.google.com/apis/library/drive.googleapis.com)
- for a private calendar: [Google Calendar API](https://console.cloud.google.com/apis/library/calendar-json.googleapis.com)

### 3. Set up the Google Auth Platform

1. Open the [Google Auth Platform](https://console.cloud.google.com/auth/overview) and click **Get started**.
2. **App information:** enter e.g. the company’s name as the **App name** and choose your address under **User support email**.
3. **Audience:** **Internal** only if the project belongs to a Google organisation (Google Workspace) and the connected account belongs to it. With a normal Google account, choose **External**.
4. **Contact information:** enter an email address, agree to the policies and click **Create**.
5. Only for **External:** open **Audience** on the left, click **Publish app** under “Publishing status” and confirm. The status is then **In production**. If it stays on “Testing”, the connection expires after 7 days.

> Enter **nothing** under **Data access**. Permissions entered there require a review by Google. The app requests its permissions itself when connecting; that is enough.

### 4. Create the access at Google

1. In the app, tap **Copy address** (two sheets next to the field). The redirect URI is different for each installation.
2. In the Google Auth Platform, open **Clients** on the left and click **Create client**.
3. Choose **Web application** as the **Application type** and enter a name, e.g. “Kompanie-App”.
4. Under **Authorised redirect URIs**, click **Add URI** and paste the copied address exactly as it is (without spaces, with `https://`). Click **Create**.
5. Google shows the **Client ID** and **Client secret**. Copy both. Google only shows the client secret this once; it’s best to download the JSON file straight away and keep it safe. If it is lost, create a new secret for the client.

> Client ID and client secret belong together like username and password. Don’t pass them on and don’t send them by email.

### 5. Connect with Google

1. In the app, enter the **Client ID** and the **Client secret**.
2. Tap **Save and connect to Google**.
3. Sign in at Google with the account that can see the gallery folder and the calendar.
4. Google warns: **“Google hasn’t verified this app”**. This is normal for your own app. Click **Advanced** and then **Go to …**.
5. Google asks for the permissions: see, edit, create and delete Google Drive files (for the {bereich.gallery} and the backups) and read Google Calendar. **Tick all boxes** and click **Continue**.
6. Google sends you back to the app. There it says **✓ Connected to Google as …** and **It may also read the calendar.**

![Client ID and client secret entered, before connecting](bild:admin-google-verbinden)

If it says instead that it may not read the calendar yet, the tick for the calendar was missing: tap **Disconnect** and connect again. The same applies if it says that it may only read Google Drive: then the tick for Google Drive was missing, or the connection comes from an older version of the app that could only read Drive. Without full access, submitted and uploaded pictures land on your own server instead of in Drive. Client ID and client secret stay saved; you can then leave the client secret field empty.

If the app reports an error, the cause is shown with it. Common: the redirect URI at Google differs, the client secret is wrong, the tick for Google Drive was missing or the sign-in was cancelled. The client ID ends in `.apps.googleusercontent.com`.

### 6. Enter the {bereich.gallery} and calendar

Then enter the gallery folder on the [Set up the {bereich.gallery}](seite:admin-galerie) page and the calendar IDs under [Set up the calendar](seite:admin-kalender).

### Alternative: service account with JSON key

Instead of “Connect with Google”, a _service account_ also works: a separate Google account just for the app that sees nothing you don’t share with it. It doesn’t need a sign-in, but a key that some Google organisations block. Backups in Google Drive don’t work with it.

1. Create a project as above and enable the interfaces (steps 1 and 2).
2. Open [IAM & Admin → Service accounts](https://console.cloud.google.com/iam-admin/serviceaccounts) and click **Create service account**. Enter a name, click **Create and continue**, skip the two optional steps and click **Done**.
3. Click the new service account. Note its **email address** (ends in `…iam.gserviceaccount.com`).
4. Open the **Keys** tab → **Add key** → **Create new key** → **JSON** → **Create**. The browser downloads a JSON file.
5. In Google Drive, share the gallery folder with this email address (role **Viewer**). For a calendar: in Google Calendar, on the calendar **⋮ → Settings and sharing → Share with specific people or groups**, permission **See all event details**.
6. In the app, expand **Alternative: service account with JSON key**, paste the content of the JSON file into **Service account key (JSON)** and tap **Save key**. Then the service account’s email address is shown there.

> The JSON file is an access key. Don’t pass it on and delete it from your computer after pasting. **“Service account key creation is disabled”?** In Google Workspace organisations a policy often blocks this. Then ask the organisation’s admin to lift the policy `iam.disableServiceAccountKeyCreation` for this project, or use “Connect with Google”.

**Replace key** enters a new key, **Remove key** deletes it.

## Good to know

- If a connection with Google exists, it takes priority over the service account.
- With “Connect with Google” the app may read everything the connected account can see in Drive and Calendar, but only reads the gallery folder and calendars entered. It may only write to files it created itself (the backups).
- **Disconnect** ends the access. It also ends if you remove the app’s access in your Google account under “Security → Third-party apps & services” or delete the service account’s key at Google.
- If the connection was made before backups in Google Drive existed, it lacks the permission for its own files. Disconnect and connect again if you want to use the backups.
- With WordPress, Google access is also available in the WordPress back end on the app’s page; the same redirect URI is shown there.

## See also

- [Set up the {bereich.gallery}](seite:admin-galerie)
- [Set up the calendar](seite:admin-kalender)
- [Backup](seite:admin-updates-und-sicherung)
