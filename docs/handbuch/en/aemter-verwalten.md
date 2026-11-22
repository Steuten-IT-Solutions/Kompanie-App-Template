---
titel: "Manage {bereich.offices}"
---
Here you record who held which office when – including former holders without an account. What you enter appears in the timeline under **{bereich.directory} → {bereich.offices}** and is visible to everyone, even without logging in.

![{bereich.offices} in the admin area](bild:aemter-verwalten)

## How to open this page
Tap **Admin** at the bottom, then **Setup** and **{bereich.offices}**. **‹ Setup** takes you back.

## What you see here
- **Import spreadsheet**: take over many terms of office at once.
- **View timeline**: opens the view everyone sees.
- **Link names to members**: suggestions as to which people without a profile already exist in the app as a member or former member.
- **Groups**: for each group the **Joint terms of office** switch and below it the icons **Edit** (pencil) and **Delete** (bin), below that **New group** with **Create group**.
- **The offices** per group (and under **No group**), with the number of terms and, if linked, the {bereich.titles} the office awards.
- **New office** with **Name**, **Group** and **Create**.
- On the left of groups and offices a handle (six dots) for moving.

## Step by step
### Create a group or an office
1. Under **New group**, enter a name, e.g. “Board”, and tap **Create group**.
2. At the bottom, under **New office**, enter the **Name**, choose the **Group** (or “No group”) and tap **Create**.

### Change the order
1. Hold a group or an office by the handle on the left.
2. Drag it to the desired position. You move offices within their group. On a computer it also works with Tab and the arrow keys.

### Enter a term of office
1. Tap the office. It opens with all its terms.
2. Tap **Enter term of office**.
3. Choose the **Type**: **Period (from–to or until today)** or **Single year**.
4. Enter **From (year)** and, if the term is over, **To**. Empty means: still running.
5. Under **People**, choose at **Profile** who the person is: **No profile** (no account, e.g. earlier or deceased members; then you enter the **Name**), **Member** or **Former member** (then you select them). **Another person** adds more. With **No profile** you can upload a picture of the person with **Photo** (JPG, PNG or WebP); **Remove photo** takes it out again. If someone with exactly this name already had a photo in an earlier term, the app offers **Use earlier photo**.
6. If the person has a companion who doesn’t hold the office themselves (e.g. a partner), tap **+ Companion (e.g. partner)** below them and enter them like a person, without a profile or as member or former member. **Another companion** adds one more (up to 5), **✕** removes them. In the timeline the companion appears set off below the person: “with …”.
7. If you like, enter an **Addition**, e.g. “Under …” or “Honorary chair”.
8. Tick **Honour** if it is an honour (e.g. honorary member). This works with both types. In the timeline it then carries a star and the label **Honour**. A single year without the tick (e.g. a year as king) appears as an ordinary year.
9. Tap **Save**.

![Terms of an office](bild:aemter-verwalten-amt)

![“New term of office” form](bild:aemter-verwalten-amtszeit)

### Change or end a term of office
1. In the office, tap **Edit** (pencil) next to the term (or **Delete** (bin)).
2. When an office ends, enter the year under **To** for the term and tap **Save**.
3. If someone is entered as a former member, their running terms end by themselves with the year of leaving (see *Former members*). You can change such a term here like any other; afterwards a change of the leaving date no longer moves it.

### Rename a group
1. Tap **Edit** next to the group.
2. Change the **Name**. It appears as the heading above the group, usually in the plural, e.g. “Royal households”.
3. If you like, enter the **Singular**, e.g. “Royal household”. It appears above each single joint term (“Royal household 2026–2028”). If empty, the app shows the name there.
4. Tap **Save**.

### Maintain a group’s joint terms of office
If all offices of a group always have the same years, e.g. a royal household or a board, you enter them together – with a group photo.
1. Switch on **Joint terms of office** for the group. The app merges existing terms of the offices with exactly the same years.
2. Below the group’s offices you now find **Joint terms of office**. Tap **New joint term of office**.
3. Choose the **Type** and enter the years as for a single term, plus an **Addition** if you like.
4. Under **Group photo**, choose a photo with **Choose image** (JPG, PNG or WebP). **Remove image** takes it out again.
5. Enter the people for each office, with **+ Companion (e.g. partner)** if needed. An office without people stays empty in this term.
6. Tap **Save**. **Edit** changes years, picture and people for all offices at once; **Delete** deletes the joint term with all terms in it and the picture.

### Rename or delete an office
1. Open the office and, under **Edit office**, change **Name**, **Singular** or **Group**; tap **Save**.
2. **Delete office** deletes the office with all its terms. **‹ All {bereich.offices}** takes you back.

### Enter English names
If your app has English switched on besides German, an admin enters the English names of the groups and offices under **Settings → Languages** in the **Translations** section. Here you maintain the German names. Those using the app in English see the English name, including in reports; if it is missing, the German one appears. The singular exists only in German; in English the English name also applies to a single term.

### Link names to members
If someone appears in terms only with their name (**No profile**) but has since been created as a member (even without access) or former member, you link the entries to them.
1. Tap **Link names to members**.
2. For each name there is a card with the number of entries and the terms, e.g. “King 1999”. Below it are the people who might match, with the reason (**same name** or **similar name**, for former members with **former**).
3. Choose the right person and tap **Link**. Confirm.
4. All entries with this name now belong to the person. In the timeline their name can be tapped, their photo is shown, and an office with {bereich.titles} awards it to them as long as the term runs.

### Import a spreadsheet
1. Tap **Import spreadsheet**.
2. Paste the table into the **Spreadsheet** field or choose a CSV file. One row per term, columns separated by semicolon or tab: `gruppe;amt;beginn;ende;art;personen;zusatz;ehrung` (group; office; start; end; type; people; addition; honour). The column names and keywords are German.
3. Leave **Link people to the account if the name matches an account exactly** ticked if the app should connect names with accounts.
4. Tap **Preview**, check the rows and tap **Apply**.

## Good to know
- Groups organise the offices in the timeline, e.g. club and umbrella association. With **Joint terms of office**, all offices of a group have the same years and a shared group photo (e.g. a royal household); the app merges existing terms with the same years.
- The order of the terms in the timeline follows automatically: running ones first, then by year.
- Only an empty group can be deleted.
- When linking, upper and lower case, spaces, hyphens and umlauts (ä/ae, ö/oe, ü/ue, ß/ss) don’t matter. Similar means: same surname, and one first name begins with the other (e.g. “Chris” and “Christian”). A person is only preselected with the same name; nothing is linked until you tap **Link**. If the person already appears in a term, the free name stays there.
- People who don’t belong to the company (e.g. a companion from outside) stay without a profile. Don’t create them as a member, otherwise they appear in the member list.
- Joint terms: if you change the years of a term that belongs to a joint one for a single office, the app takes it out; it then stands alone. An honour never belongs to a joint term. If you switch off **Joint terms of office**, all terms and pictures are kept; the timeline then shows them per office again.
- The group photo, like the timeline, is visible to everyone, even without logging in. The app scales down large pictures when uploading.
- Photos of people without a profile are, like the timeline, visible to everyone, even without logging in. Get the person’s consent first (for deceased people that of their relatives). For members and former members the app always shows their profile photo. The app deletes a photo that no longer appears in any term.
- Former members can be chosen at **Former member** if they are entered as former members in the admin area.
- If an account is deleted, its terms remain with the name; only the link to the account is dropped.
- Companions don’t hold the office: a {bereich.titles} linked to the office only goes to the person themselves, and if a companion leaves, no term ends. If the person leaves, their companion goes into the ended term with them. Members and former members see the term on their page with the addition “(companion)”. The import doesn’t know companions; enter them by hand afterwards.
- If a partner has so far been entered as a separate person in the term, remove them there with **✕** and enter them again with **+ Companion** for the right person.
- Import: **ende** is a year, “heute” for a running term or empty for a single year. **art** is usually empty; “zeitraum” or “zeitpunkt” forces the type. You separate several **personen** with “&”. **ehrung** is “ja” for an honour, otherwise empty or “nein”. A header row is allowed. The app finds groups and offices by their name or creates them. Anything that already exists exactly like that is skipped; so you can safely import the same table again. Rows with errors must be corrected first.
- Import of older tables with only 7 columns (without **ehrung**): these produce no honours, not even with “zeitpunkt”. Previously every single year became an honour. So for honours add the column with “ja” or set the tick by hand afterwards. A row that only differs from an existing term in the honour value counts as already existing.

### Award {bereich.titles} automatically
If the company uses {bereich.titles}, you can choose a title in the office under **Award {bereich.titles}** at **Linked** (for this you must also be allowed to manage {bereich.titles}). Before you tap **Link**, the app shows who gets the title and who loses it.
- Whoever has a running term (with an account, as {rolle.member}) gets the title automatically; whoever no longer has a running one loses it. Single years and ended terms award no title, with or without the **Honour** tick.
- A linked title can no longer be awarded or removed by hand. A title belongs to at most one office.
- **Remove link** doesn’t change the titles already awarded; afterwards you award them by hand again.
- On a person’s page, on the cards of joint terms and when tapping a bar in the year view, the office’s **Singular** is shown (“Captain” instead of “Captains”). If none is entered, the name of the linked title is shown, otherwise the name. So name the office in the plural (“Company kings”) and enter the singular (“Company king”) or give it to the title.

## See also
- [{bereich.offices}](seite:aemter)
