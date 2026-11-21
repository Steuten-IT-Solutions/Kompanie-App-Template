---
titel: Former members
---
Anyone who leaves, dies or departs for another reason doesn’t have to be deleted. Entered as a former member, the account keeps all its data: {bereich.points}, {bereich.penalties}, attendance, {begriff.rank} with history, {bereich.titles}, {bereich.offices}, birthday and photo. Under **Former members** you find all these people. Everything on this page may be done by anyone with the permission **Manage former members**.

![List of former members in the admin area](bild:ehemalige-mitglieder)

## How to open this page
Tap **Admin** at the bottom and then **Former members**.

## What you see here
- **Counter** at the top: how many have left, died or departed for another reason.
- **+ Add former member**: enter someone who left before the app existed (see [Add former member](seite:ehemalige-anlegen)). From there you can also import many at once.
- **Search …**: filters the list by name.
- **Filter and sorting**: collapsed at first. In it you filter by reason under **All reasons** and sort by **Surname**, **First name**, **Joining date** or **Leaving date**, with **Order** next to it. If a filter is set, **active** is shown next to it.
- **The list**: per person the name, below it “Founding member”, joining and leaving, as far as entered, and open {bereich.penalties} if you can see them.

## Step by step
### Enter someone as a former member
1. Open the person’s page from the member list.
2. Below the tiles, tap **Leaving date** (or under **Account and role** **Enter as former member …**). The **Leaving date** window opens.
3. Choose the **Reason for leaving**: **Left**, **Deceased** or **Other**.
4. If known, enter **Left on** (for deceased members **Died on**) and the **Joining date**, each as DD.MM.YYYY or just the year. A joining date that has already been entered is shown there.
5. Tick **Founding member** if it applies.
6. If the person holds current {bereich.offices}, it says below which ones end with the departure and with which year, e.g. “Ending with the departure: Flag (since 2006) – end 2026.”
7. Tap **Enter as former member**.

![Leaving date window](bild:ehemalige-mitglieder-eintragen)

### Open a former member
1. Tap the person in the list. Their page opens as for active members.
2. **‹ Former members** takes you back. Swipe (or use **‹** and **›** at the top right) to go to the next or previous person; after the last one comes the first again.

### Change details or readmit
1. On the person’s page, tap **Joining date** or **Leaving date**, change the reason, dates or **Founding member** and tap **Save**.
2. To readmit someone (e.g. when they rejoin), expand **Account and role** and tap **Readmit** under **Former member**.
3. Then generate a new starting password under **Account and role** so that they can log in again.

### Take over former members from a table
1. Tap **+ Add former member** and then at the top **Import table (e.g. chronicle)**. **‹ Create one by one** takes you back to the form.
2. Paste the table into the **Spreadsheet** field or choose a file under **Or choose a file (Excel .xlsx or CSV)**. One row per person, columns separated by semicolon or tab: `vorname;nachname;eintritt;ausgeschieden;grund` (first name; surname; joined; left; reason). The column names and keywords are German.
3. Tap **Preview**. It shows each row with what the app understood, and errors per row.
4. If no row has an error, tap **Apply**.

![Preview when importing former members](bild:ehemalige-mitglieder-import)

### Set a retention period ({rolle.admin} accounts)
Personal data should not be stored longer than necessary. At the bottom of the page, an {rolle.admin} sets under **Retention period** after how many years since leaving the app deletes the personal details of former members. The chronicle stays.
1. Under **Years after leaving**, enter the number of years (empty or 0: off, which is the default).
2. Tap **Save**. Before that, the app names who the next run affects (when switching on a few minutes after saving, otherwise within a day at the latest; daily after that); it only saves once you confirm. Afterwards the page shows who the next run affects and who will be added in the next 30 days.

The personal details deleted are: address, mobile number, email address, login (username and password), birthday, penalties, attendance, answers in {bereich.polls}, notifications, signature, settings, drafts and the usage statistics. This cannot be undone. For the chronicle, the name, the entry as a former member (reason, joining, leaving, founding member), offices, points, rank with history, titles, photo and year of joining remain. The period applies equally to all former members, including founding members and deceased members. If you enter an address, mobile number, birthday or similar again afterwards, the app deletes them again on the next run. A newly entered email address or login stays (e.g. to readmit the person). In WordPress the website’s user (login, email address) remains; a website administrator changes or deletes it under **Users**. If you readmit such a person later, enter the username and email address again under **Edit details**; the person then sets their password with the website’s password reset. The reference date is **Left on** (only a year: 31 December), without a date the day the person was entered as a former member; deletion happens from the day after the period has expired. The period also applies to former members you enter or import later: if their leaving date is already longer ago than the period, the next run deletes their personal details. The period belongs to this installation and does not come from a backup: after a move you set it again (again with a preview); when restoring here it stays set. If you want to recover personal details of former members from a backup whose period has already expired, switch it off first, otherwise the next run deletes them again. Which period is appropriate for {kompanie.name} (e.g. because of a chronicle) is best clarified with your data protection officer.

## Good to know
- For deceased members, **left** or **Died on** is the date of death.
- Afterwards the person no longer has a role: they are no longer in the member list or in the public list of {rolle.member} accounts, get no notifications, don’t take part in {bereich.polls} and no longer appear in the {bereich.birthdays}. They are logged out on all devices and can no longer log in.
- If {bereich.penalties} are still open, the app points this out first. They stay open and are also shown in the list of former members.
- Current {bereich.offices} end with the departure, including those with the **Honour** tick. The end is the year of **Left on**; if it is empty, the current year. If the departure is before the start of a term, it ends in the year it started. Single years and terms that have already ended stay as they are. Nothing is deleted: the terms stay with the person in the timeline.
- If the person shares a current term with others (e.g. flag), they get their own ended entry; the others stay in office.
- If an office is linked to a title, the person loses this title with the departure. They keep other titles.
- If you later change **Left on**, the terms ended when entering move along: they then end with the new year. This doesn’t apply to terms that someone has since changed by hand under {bereich.offices}.
- **Readmit** doesn’t reopen ended terms. If the person still holds an office, enter a new term under {bereich.offices} or remove the end there.
- When readmitted, the person gets their previous role back, plus their additional roles as far as they still exist and you may assign them. Only an {rolle.admin} restores exceptions to the permissions. If the role no longer exists, they become {rolle.member}.
- Anyone who isn’t an {rolle.admin} can only enter (and readmit) people as former members who have no more permissions than themselves and who may neither see accounts, create members, manage former members nor assign roles.
- As for members, you only see and change former members’ personal details (address, mobile number, email address, date of birth) with **See personal details** or **Maintain personal details**. You change a former member’s photo with **Manage former members**.
- Only {rolle.admin} accounts can enter {rolle.admin} accounts as former members. You can’t enter your own account as a former member.
- Import: **eintritt** and **ausgeschieden** as DD.MM.YYYY, just the year or empty; “Gründer” as the joining date enters a founding member without a date. **grund**: ausgetreten (left, also empty), verstorben (deceased) or sonstiges (other). A header row with exactly these column names is allowed. At most 500 rows at once.
- For each imported person an account without login is created, already entered as a former member. Anyone who already has an account with exactly this first name and surname is skipped; a second import therefore doesn’t create anyone twice. Two people with the same name in the table are an error: make them distinguishable, e.g. with “I” and “II” after the surname.
- If an imported person has so far appeared in the {bereich.offices} only by name, the import links this entry to their new account if the name matches exactly (upper or lower case doesn’t matter). The preview says beforehand how many entries will be linked.
- You take over active and former members from a joint list under **Import members** (with the permission **Create members**).

> **For advanced users:** In the WordPress version the website’s user remains but no longer has a role in the app. This also applies if the retention period deletes the person: a website administrator deletes the name and email address in the WordPress back end under **Users**.

## See also
- [A member’s page](seite:mitglied-uebersicht)
