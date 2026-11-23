---
titel: Set up the calendar
---
Here you decide where the internal {bereich.calendar} for members gets its events from: directly from the app, from your Google Calendar or via an iCal address. The app shows these events to logged-in members. The internal calendar is separate from the public events.

![The Calendar page in the settings](bild:admin-kalender)

## How to open this page
Tap **Admin** at the bottom, then **Settings** and, under **Events and calendar**, **Calendar**. Below it, if the section is on, is the **{bereich.birthdays}** section. In the [setup assistant](seite:admin-einrichtung) the same section is in the step for the calendar.

## What you see here
- **Calendar for members**: the section for the internal calendar.
- A line showing how the calendar is currently set up, e.g. **✓ The events are maintained in the app.**, **✓ Set up via the iCal address.** or **No calendar set up yet.**
- **Source**: where the events come from, **Maintain in the app**, **Google Calendar (calendar IDs)** or **iCal address**. Exactly one source always applies.
- **Google Calendar IDs**: only with the Google Calendar source.
- **iCal address** (or, if one is already saved, **New iCal address (replaces the previous one)**): only with the iCal address source.
- **Refresh every … minutes**: only with Google Calendar and iCal address; how long the app shows the loaded events before asking again (default 15). For the notifications about new and changed events, the app asks every 15 minutes regardless.
- **Test calendar** and **Remove**: appear with Google Calendar and iCal address as soon as the calendar is set up.
- **Save**: applies what you entered.
- **Accept and decline**: a separate section below with its own **Save**. **Accept and decline for events** (off at first) switches on the **Accept** and **Decline** buttons on the events. Only then do the reminder settings appear: **Remind people to accept or decline** (on at first) sends a notification to everyone who hasn’t answered for an event yet. **Reminder … days before the event** sets how long before (1 to 30, 3 at first).
- **Event types**: a separate section with its own **Save**, e.g. **Versammlung** (meeting) or **Arbeitsdienst** (work duty) (empty at first). **+ Event type** adds a line, **×** removes one. Under each type is the tick box **Show as a label on the event** (on at first).

## Step by step
### Maintain events directly in the app (without Google)
1. Under **Source** choose **Maintain in the app**.
2. Tap **Save**. The app creates the role **Manage {bereich.calendar}** if it doesn’t exist yet.
3. Give the role or the **Maintain calendar** permission to everyone who should enter internal events (see [Roles and members](seite:admin-rollen-und-mitglieder)). As {rolle.admin} you may do it anyway.
4. You then create the events under **{bereich.events}** → **Manage internal calendar**.

### Connect a calendar via an iCal address (without Google access)
1. In Google Calendar, on the company’s calendar open **⋮ → Settings and sharing** and there **Integrate calendar**.
2. Copy the **Secret address in iCal format**.
3. In the app, under **Source** choose **iCal address**, paste the address into **iCal address** and tap **Save**.

### Connect a calendar via Google access (also for private calendars)
1. First set up the [Google access](seite:admin-google). With “Connect with Google” the box for Google Calendar must be ticked; a service account needs the calendar shared with it (“See all event details”).
2. In Google Calendar, under **⋮ → Settings and sharing → Integrate calendar**, copy the **Calendar ID**. For an account’s main calendar this is its email address.
3. In the app, under **Source** choose **Google Calendar (calendar IDs)** and enter the ID in **Google Calendar IDs**. Separate several with commas.
4. Tap **Save**.

### Change the source
1. Under **Source** choose another source and add what it needs.
2. Tap **Save**. From then on the app only shows the events of the new source. The details and the events maintained in the app for the previous source stay saved and appear again if you switch back.
3. If you switch away from **Maintain in the app**, the role **Manage {bereich.calendar}** disappears if nobody has it.

### Set how quickly changes appear
1. Under **Refresh every … minutes** enter a number from 1 to 1440 (1440 minutes are one day).
2. Tap **Save**. The new value applies straight away, and the events are reloaded.

### Switch on accepting and declining
1. In the **Accept and decline** section, tick **Accept and decline for events**.
2. Tap **Save** in this section. From now on every event in the internal calendar has the **Accept** and **Decline** buttons, and when maintaining an event there is the **Response deadline (optional)**.
3. If you untick it again, the buttons, the response deadline, the reminder and the **excused** suggestion in attendance disappear. The responses so far stay saved and appear again when you switch it on.

### Set the reminder to accept or decline
1. First switch on accepting and declining (see above).
2. In the **Accept and decline** section, tick or untick **Remind people to accept or decline**.
3. Under **Reminder … days before the event** enter how many days before the start the reminder should come.
4. Tap **Save** in this section.

### Set up event types
1. In the **Event types** section enter one type per line, e.g. **Versammlung**. **+ Event type** adds another line (at most 30, each at most 40 characters).
2. If a type shouldn’t appear as a label next to the event’s title, untick **Show as a label on the event** below it.
3. Tap **Save** in this section.
4. From now on everyone with the **Maintain calendar** permission chooses the type in the expanded event under **{bereich.events}**, and in attendance it is shown on every list.

### Name event types in English
If your app has English switched on besides German, you enter the English names of the event types under [Languages](seite:admin-sprachen) in the **Translations** section under **Event types**. If a field stays empty, everyone using English sees the German name. The English name belongs to the German one: if you rename a type here, enter the English name again afterwards. Events and lists with the old name keep showing the English name you had entered for it.

### Check whether the calendar arrives
1. Tap **Test calendar**.
2. The app shows how many upcoming events it found, e.g. “5 events found, e.g. “Schützenfest”.” If Google refuses, the message names the likely cause.

![The result of Test calendar](bild:admin-kalender-test)

### Remove the calendar
1. Tap **Remove** and confirm the question. All calendar details are deleted.

## Good to know
- Changes in Google Calendar, e.g. a deleted event, appear in the app at the latest after the time you set. Shorter times show them sooner but mean more requests to Google, and opening the events then more often takes longer. In addition, the app refreshes the calendar hourly in the background. It reloads straight away when you save and with **Test calendar**.
- The app doesn’t show the iCal address again after saving. A new address replaces the previous one.
- If you set up the calendar before the choice of source existed, what was entered applies as before until you next save: calendar IDs before the iCal address.
- If the connection with Google may not read the calendar, it says so on this page and under [Status and updates](seite:admin-zustand). Then disconnect under **Google access** and connect again, with the box for the calendar ticked.
- Accepting and declining is off at first, even after updating to the version that introduces it. Only with the tick at **Accept and decline for events** does it exist.
- If it is ticked, everyone with the **Accept or decline events in the internal calendar** permission can accept or decline every event in the internal calendar, with any source. {rolle.member} have the permission at first, {rolle.guest} don’t. Who answered how is seen only by people with the **Maintain calendar** permission, also with Google Calendar and iCal address, under **{bereich.events}** in the expanded event.
- The reminder to accept or decline comes once a day at the reminder time (under **Notifications**, usually 6 pm), on devices with notifications switched on and, if sending emails is set up, by email to everyone who hasn’t switched this off. If an event from the app has a response deadline, it comes the day before the deadline instead of the set number of days before the start. Anyone who declined doesn’t get the reminder the day before this event either.
- Event types apply with any source, also with Google Calendar and iCal address. The app stores the type itself and changes nothing in Google Calendar. An event can have several types. If the name of a type appears as a word of its own in an event’s title, the app suggests it. A new attendance list takes over the types of its event, otherwise this suggestion.
- Without a label the type stays saved on the event and still counts, e.g. for admitting candidates. Anyone who maintains events sees it in the expanded event, and in attendance it is shown on the list as before.
- If you remove an event type from the list, events and attendance lists keep it. If you create it again later, it is shown as a label on the event again.
- The {bereich.calendar} appears for the others only when the section is on and a calendar is set up (see [Sections](seite:admin-bereiche)).
- With WordPress the fields for Google Calendar and the iCal address are also in the WordPress back end on the app’s page (there with **Save and test**), as is **Refresh every … minutes**, plus a field for your own Google API key for public calendars. If it is entered, it takes precedence for calendar IDs. You choose the source only here in the app.

## See also
- [Google access](seite:admin-google)
