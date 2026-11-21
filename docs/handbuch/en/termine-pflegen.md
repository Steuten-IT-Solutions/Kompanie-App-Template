---
titel: Manage public events
---
Here you create, change and delete the public events of {kompanie.name}. Everyone sees these events in the app, even without logging in.

![Manage public events with the button for adding and the list of upcoming events](bild:termine-pflegen)

## How to open this page
Tap **{bereich.events}** at the bottom and then **Manage public events** at the top.

## What you see here
- **‹ To the events**: back to **{bereich.events}**.
- **+ Add public event**: opens the form for a new event.
- **Upcoming and public**: all upcoming events with date, time and location, each with the round icons **Edit** (pencil) and **Delete** (bin).

## Step by step
### Add an event
1. Tap **+ Add public event**.
2. Enter **Title** and **Date**. Both are required.
3. If the event lasts several days, enter the last day under **Until (optional)**.
4. If needed, enter **Start (optional)** and **End (optional)**. Without a time the event counts as all-day. An end also needs a start.
5. Add **Location**, **Description** and a **Link (optional)** if you like. The link must start with http:// or https://.
6. Tap **Save**. “Event created” appears.

![Filled-in “New public event” form](bild:termine-pflegen-formular)

### Change an event
1. Tap **Edit** next to the event. The **Edit event** form opens at the top.
2. Change the details.
3. Tap **Save**. **Cancel** leaves everything as it was.

### Enter an event in English too
If the admin has switched on English under **Settings → Languages**, the form has the expandable section **Write in English (optional)** below the **Description**.
1. Tap **Write in English (optional)**.
2. Enter **Title in English**, **Location in English** and **Description in English**. Empty fields show the German text in grey.
3. Tap **Save**.

Those using the app in English see the event in this version, including in the notification. Anything you leave empty appears in German, with the note *Partly only available in German* on the opened event. Date, time and link apply to all languages. If you use the app in English yourself, you still edit the German version at the top of the form.

### Delete an event
1. Tap **Delete** next to the event.
2. Confirm. The event is gone immediately.

## Good to know
- Past events disappear from the list by themselves.
- If {kompanie.name} also uses its internal calendar, logged-in {rolle.member} accounts and people with the internal calendar (e.g. candidates) see that calendar under **{bereich.events}** instead. So enter public events that they should see as well there too. The internal events don’t come from here but from that calendar.
- Those who have switched on notifications for {bereich.events} and see the public events (e.g. guests) get “{bereich.events}: new” for a new event. If you later change date, time or location, “{bereich.events}: changed” follows. Changes only to the title or description don’t trigger a notification, and nothing is sent for past events.

> **For advanced users:** if the app runs via a WordPress website, the shortcode `[kompanie_termine]` also shows the public events on the website.

## See also
- [{bereich.events}](seite:termine-und-kalender)
- [Notifications](seite:benachrichtigungen)
