---
titel: "{bereich.events} with calendar"
---
When you are logged in, **{bereich.events}** shows all upcoming events from the calendar of {kompanie.name}, including internal ones, sorted by month. You can add an event to your own calendar.

![Events from the company’s calendar, upcoming birthdays at the top](bild:kalender)

## How to open this page
Tap **{bereich.events}** at the bottom. This is usually also the app’s start page.

## What you see here
- **Upcoming {bereich.birthdays}** (at the top, only for {rolle.member} accounts and only if {kompanie.name} uses {bereich.birthdays}): who has a birthday today or in the next few days (how many days in advance is set by an {rolle.admin}; 7 at first).
- **Manage internal calendar**: only if {kompanie.name} maintains its calendar in the app and you are allowed to.
- **Manage public events**: only if you may maintain public events.
- **Month heading**: e.g. “October 2026”; below it the events of that month.
- **Event**: the date on the left, on the right title, weekday and time and, if given, the location. If the event is not in the current year, the year is shown too. If there is no time, the event lasts all day. If the event has an event type, e.g. **Meeting**, it is shown as a small label next to the title, one label per type.
- **Accept** and **Decline** below the event, with the number of acceptances and declines: see [Accept or decline events](seite:zu-und-absagen).

## Step by step
### View an event
1. Tap the event. It opens and shows the description, if there is one.
2. Tap **Add to my calendar** to add the event to your own calendar: the app downloads a calendar file. Open it, and your phone or computer offers to add it.
3. If a location is given, **Map** shows it in the maps app: on Android you choose the app (e.g. Google Maps or Waze), on iPhone and Mac Apple Maps opens, on a PC Google Maps.
4. Tap the event again to close it.

![Opened event with description and the “Add to my calendar” button](bild:kalender-termin)

## Good to know
- The events come from the internal calendar of {kompanie.name}, up to about a year ahead. {kompanie.name} maintains it directly in the app or e.g. in a Google Calendar. Events marked as private or cancelled in such a calendar don’t appear.
- If the calendar comes from outside (e.g. Google Calendar), new or changed events don’t appear immediately but after a short time. Events maintained in the app appear immediately.
- The public events that guests see without logging in are not listed here in addition. You only see them if they are also entered in the internal calendar of {kompanie.name}.
- This view is for logged-in {rolle.member} accounts and people with access to the internal calendar (e.g. candidates). Guests who are not logged in only see the public events under **{bereich.events}**.
- If the app runs via a WordPress website, the shortcode `[kompanie_kalender]` also shows these events on a page of the website, but only for logged-in people with access to the internal calendar.
- The app can notify you about new and changed events and with a reminder the day before, see [Notifications](seite:benachrichtigungen).

## See also
- [Accept or decline events](seite:zu-und-absagen)
- [Notifications](seite:benachrichtigungen)
- [The app at a glance](seite:app-ueberblick)
