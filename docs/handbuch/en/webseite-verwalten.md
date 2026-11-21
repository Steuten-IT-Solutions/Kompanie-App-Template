---
titel: Manage the website
---
Here you set up the website for visitors. It sits at the address of your web space, for example `your-website.com`, while the app stays in the folder below it, for example `your-website.com/app/`. The website needs no login, sets no cookies and loads nothing from other servers. As its start page it shows one of your own pages from {bereich.pages} that is visible to everyone.

## How to open this page
Tap **Admin** at the bottom, then **Setup** and the **Website** button. **‹ Setup** takes you back.

The button exists as soon as the **Website** section is switched on under **Settings → Sections**. It needs the {bereich.pages} section.

## What you see here
- **Address**: whether the entry file is already there and at which address the website can be reached. Depending on the situation there is also the **Create entry file** or **Open website** button.
- **Start page**: which page the website shows, with **Save**.

## Step by step
### Create the entry file
The entry file is a small file `index.php` in the folder above the app. It only includes the app and doesn’t change with updates.
1. Under **Address**, tap **Create entry file**.
2. The app reports “Entry file created”. Then the website’s address and the **Open website** button are shown.

If another start page is already in this folder, for example your previous website, the app never replaces it. It names the file instead. If the new website should replace the old one, back up the old file at your host, rename or delete it, and then tap **Create entry file**.

If the app may not create files in the folder, it shows the content of the file. Then create a file `index.php` with exactly this content in the folder above the app using your host’s file manager.

### Choose the start page
1. Under **Admin → Setup → {bereich.pages}**, create a page and choose **Everyone, even without logging in** at **For whom?**. For this you need the permission to maintain {bereich.pages}.
2. Here, under **Start page**, choose this page at **Page**.
3. Tap **Save**.

## Good to know
- The website shows the page as guests see it in the app. Members-only blocks are missing there, as are polls.
- If you change the page under {bereich.pages}, the website shows the change immediately.
- If you set the start page to **Members only** or delete it, the website only shows a short note with the way to the app. This page then tells you to choose another page.
- Pages with the **Contacts** block show people. Search engines are therefore asked not to index them.
- The website’s footer has the links to the legal notice and privacy policy from **Settings → Legal notice and privacy**.
- If the **Website** section is off, the website’s address forwards to the app.
- The **Status and updates** page under **Settings** shows whether the website can be reached and has a start page.
- The website only exists in the standalone version. With WordPress you keep maintaining your pages in WordPress.
