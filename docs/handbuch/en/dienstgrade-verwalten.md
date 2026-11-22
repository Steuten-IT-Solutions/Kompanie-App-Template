---
titel: "Manage {bereich.ranks}"
---
Here you decide which {bereich.ranks} you have, in which order, from how many {bereich.points} they can be reached and which badge they have. Who has which {begriff.rank} you change on the member’s page.

![The list of {bereich.ranks} with thresholds and badges](bild:dienstgrade-verwalten)

## How to open this page
Tap **Admin** at the bottom, then **Setup** and the **{bereich.ranks}** button. **‹ Setup** takes you back.

## What you see here
- The {bereich.ranks} from lowest (top) to highest (bottom), each with badge, name, threshold (“from … {bereich.points}” or “no threshold”) and how often it has been given. Deactivated ones carry the label “inactive”.
- From two {bereich.ranks} on, a handle (six dots) on the left of each for moving it.
- For each {begriff.rank} the buttons **Edit**, **Deactivate** (or **Activate**) or **Delete** (bin).
- Below it the badge: a preview on a light and a dark background (“light”, “dark”) with **Replace** and **Remove**; without a badge the **Upload badge** button.
- At the bottom the **New {begriff.rank}** form with **Name**, **{bereich.points} threshold (optional)** and **Create**.

![A {begriff.rank} with its buttons and the badge preview](bild:dienstgrade-verwalten-abzeichen)

## Step by step
### Create a {begriff.rank}
1. Under **New {begriff.rank}**, enter the **Name**.
2. If needed, enter the **{bereich.points} threshold**: from how many {bereich.points} it can be given.
3. Tap **Create**. The new {begriff.rank} is at the very bottom, i.e. the highest.

![Filled-in form for a new {begriff.rank}](bild:dienstgrade-verwalten-neu)

### Change the order
1. Hold the handle on the left of the {begriff.rank} and drag it to the new position: up is lower, down is higher.
2. Let go. The order is saved immediately.
3. With a keyboard: tap the handle and move the {begriff.rank} with the arrow keys.
4. If the new order doesn’t fit the thresholds, the {begriff.rank} jumps back, and the top of the page says which {bereich.ranks} don’t fit.
5. While you are editing a {begriff.rank}, nothing can be moved.

### Change name or threshold
1. Tap **Edit** next to the {begriff.rank}.
2. Change **Name** or **{bereich.points} threshold**. Empty means: no threshold.
3. Tap **Save** (or **Cancel**).

### Enter the name in English
If your app has English switched on besides German, an admin enters the English names of the {bereich.ranks} under **Settings → Languages** in the **Translations** section. Here you maintain the German name. Those using the app in English see the English name, including in notifications; if it is missing, the German one.

### Upload a badge
1. Tap **Upload badge** next to the {begriff.rank} and choose the image file.
2. Check in the preview whether the badge is easy to see on both a light and a dark background.
3. **Replace** takes a different image, **Remove** takes it away completely.

### Delete or retire a {begriff.rank}
- If nobody has the {begriff.rank}, tap **Delete** and confirm. Its badge is deleted with it.
- If someone has the {begriff.rank}, only **Deactivate** is possible: it is no longer given, and those who have it keep it. **Activate** undoes this.

## Good to know
- The name has at most 60 characters, and each exists only once.
- **Thresholds**: a whole number from 0, empty = none. They must rise with the order; otherwise the app says which {bereich.ranks} don’t fit. Thresholds only take effect if you also use {bereich.points}; otherwise the field says “(only applies with {bereich.points})”.
- **Rules with a threshold** (they apply to everyone, including {rolle.admin} accounts): promoting is only possible once the member’s {bereich.points} reach the threshold of the new {begriff.rank}. Several steps at once are allowed. Demoting is always possible. No rules apply to {bereich.ranks} without a threshold; if not all have one yet, the page points this out at the top.
- **Badge**: PNG, JPG, WebP or SVG, at most 1 MB (PNG, JPG and WebP at most 4096 × 4096 pixels). About 256 pixels on the longer edge is enough; the app scales it down itself.
- A badge with a **transparent background** (PNG, WebP or SVG) works best. The app puts no background behind it, and the background is light or dark depending on the phone’s setting. A light edge around dark parts helps. JPG always has its own background.
- The badge appears wherever the {begriff.rank} is shown: under **My area**, in the admin area’s member list, on a member’s page and under **Promotions**. Anyone who has seen it once sees it without a connection too.
- For a promotion, the notification shows the new badge as a picture. For an SVG, the app draws a picture for this when uploading; if that fails, “No image in notifications” appears next to the {begriff.rank}. Then upload the SVG again, e.g. on another device, or use a PNG.
- SVG files are cleaned when uploaded: scripts, external references, embedded images and foreign content are dropped. A normal SVG from a graphics program looks the same afterwards. The app rejects files with a DOCTYPE declaration.
- A deactivated {begriff.rank} keeps its badge.

## See also
- [A member’s {begriff.rank}](seite:mitglied-dienstgrad)
