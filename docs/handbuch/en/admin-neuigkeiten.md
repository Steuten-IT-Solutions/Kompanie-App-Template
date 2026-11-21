---
titel: "Set up {bereich.news}"
---
Here you decide where the {bereich.news} come from: from your company’s website, from a feed or directly from the app, where you write the posts yourselves. The app then shows the posts in the {bereich.news} section.

![The {bereich.news} page in the settings](bild:admin-neuigkeiten)

## How to open this page
Tap **Admin** at the bottom, then **Settings** and **News**. If the section has a different name for you than “News”, its name appears there. In the [setup assistant](seite:admin-einrichtung) the same section is in the step for the {bereich.news}.

## What you see here
- **Source**: with WordPress **Posts of this WordPress site** (default) or **Write in the app**. Without WordPress **No news**, **Another WordPress website**, **RSS or Atom feed** or **Write in the app**.
- **Website address** or **Feed address**: appears as soon as you have chosen a website or a feed.
- **Test news**: appears when a website or a feed is saved. If you have just changed something, it first says **Save first, then test.**
- **Save**: applies the entries.

## Step by step
### Write the {bereich.news} in the app
1. Under **Source**, choose **Write in the app** and tap **Save**.
2. Under **{bereich.news}**, everyone with the permission **Write {bereich.news}** now sees the **New post** button. As an {rolle.admin} you always have the permission.
3. The app creates the role “Manage {bereich.news}” for this. Give it under **Members** to everyone who should write posts (see [Roles](seite:admin-rollen-und-mitglieder)).

### With WordPress: write the website’s posts from the app
1. Leave **Posts of this WordPress site** chosen under **Source**.
2. Under **{bereich.news}**, everyone with the permission **Write {bereich.news}** sees the **New post** button, and in an opened post **Edit in WordPress**. Both open the WordPress editor.
3. The app creates the role “Manage {bereich.news}” for this. Whoever has it may write, change, publish and delete all posts and upload pictures in the WordPress editor, but not edit pages. You don’t need a WordPress role (author, editor) for this.

### Connect the company’s website (without WordPress)
1. Under **Source**, choose **Another WordPress website** if your website runs on WordPress. Otherwise choose **RSS or Atom feed**.
2. Enter the **Website address** (e.g. `https://www.beispiel.de`) or the **Feed address** (e.g. `https://www.beispiel.de/feed`).
3. Tap **Save**.
4. Tap **Test news**. The app shows how many posts it found.

![The result of Test news](bild:admin-neuigkeiten-test)

### Switch off the {bereich.news}
1. Without WordPress: under **Source**, choose **No news** and tap **Save**. With WordPress you switch the section off under [Sections](seite:admin-bereiche).

## Good to know
- The {bereich.news} only appear for the others once the section is on and a source is set up (see [Sections](seite:admin-bereiche)).
- Exactly one source always applies; posts from the app and from the website are not mixed. If you switch the source, the app’s posts stay stored, as does the address of the website or feed. If you switch back, they are there again.
- After a switch, the app doesn’t report the posts of the new source as new; notifications only come for posts that appear afterwards.
- With WordPress and **Posts of this WordPress site**, the {bereich.news} come from the published posts of your site; there is nothing to set for this. The app doesn’t show drafts or password-protected posts.
- If the **Polylang** plugin is active on your WordPress site and English is switched on in the app (see [Languages](seite:admin-sprachen)), the app shows the German posts; anyone using the app in English sees instead the English translation you have linked and published in Polylang. If it is missing, the German post appears with the note *Only available in German*. Without Polylang all posts count as German.
- Posts from another WordPress website or from a feed are only available in German in the app; in English they carry the note *Only available in German*.

> **For advanced users:** An RSS or Atom feed is an address where a website offers its latest posts in machine-readable form. Many websites have one, often under `/feed` or `/rss`.

## See also
- [Settings](seite:admin-einstellungen)
