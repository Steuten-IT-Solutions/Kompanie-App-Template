---
titel: Sections
---
Here you decide which sections the app has for everyone, for example {bereich.points}, {bereich.documents} or {bereich.inventory}. Sections that are switched off disappear from the app; their data is kept.

![The Sections page with one switch per section](bild:admin-bereiche)

## How to open this page
Tap **Admin** at the bottom, then **Settings** and **Sections**.

## What you see here
One switch per section with its name and a sentence on what it is for: {bereich.events}, {bereich.calendar}, {bereich.news}, {bereich.gallery}, {bereich.points}, {bereich.penalties}, {bereich.ranks}, {bereich.titles}, {bereich.attendance}, {bereich.documents}, {bereich.board}, {bereich.polls}, {bereich.birthdays}, {bereich.inventory}, {bereich.directory}, {bereich.offices}, {bereich.lists}, {bereich.mail}, {bereich.companies}, {bereich.sponsors}, {bereich.pages} and **Passive members**, in the standalone version also **Website**.

Below a switch it also says, if necessary:
- **Needs …**: the section can only be switched on when the one named is on. This concerns {bereich.polls}; they need the {bereich.board}. The **Website** needs {bereich.pages}.
- **Not set up yet.** with **Set up now**: the section is on but not connected yet (for {bereich.calendar}, {bereich.gallery} and {bereich.news}; for {bereich.mail}, sending emails under **Email** is missing). **Set up now** opens the matching settings page.

## Step by step
### Switch a section on
1. Tap the section’s switch.
2. The app saves straight away and reports “… switched on”. The section appears immediately for everyone allowed to see it.

### Switch a section off
1. Tap the section’s switch.
2. Confirm the question. The section disappears from the app for everyone.

## Good to know
- Sections that are switched off keep their data. If you switch them on again, everything is back.
- {bereich.calendar}, {bereich.gallery}, {bereich.news} and {bereich.mail} only appear for the others once they are also set up ({bereich.mail}: sending emails under **Email**).
- Sections with their own tasks bring a role when switched on, for example “Manage {bereich.points}”. If a role with this name already exists, the app doesn’t create a second one. If you switch the section off and nobody has the role, it disappears again. {bereich.calendar}, {bereich.birthdays} and {bereich.directory} don’t bring their own role, {bereich.news} only with the source **Write in the app** or, with WordPress, **Posts of this WordPress site** (“Manage {bereich.news}”, see [Set up {bereich.news}](seite:admin-neuigkeiten)). More under [Roles](seite:admin-rollen-und-mitglieder).
- If you switch on a section with its own notifications (e.g. {bereich.board}, {bereich.polls}, {bereich.birthdays} or {bereich.news}), devices already registered for notifications get these notifications too. Anyone who switched a type off themselves doesn’t get it back.
- With WordPress you can also switch the sections on and off on the app’s page in the WordPress back end.

## See also
- [Setup assistant](seite:admin-einrichtung)
- [Settings](seite:admin-einstellungen)
