---
titel: "Attendance list “…”"
---
In an attendance list you enter for an event who was **present**, who was **excused** and who was **absent**. If you may also award {bereich.points} or {bereich.penalties}, you book them from here for everyone present or absent.

![An attendance list with counters and ticks](bild:anwesenheitsliste)

## How to open this page
Tap **Admin** at the bottom, then **{bereich.attendance}**. There, tap an event or a list under **Recent lists**.

## What you see here
- **‹ {bereich.attendance}**: back to the overview.
- **Title and date**: for an event from the {bereich.calendar} they are fixed. For another occasion they are in fields you can change.
- **Event types**: below it the list’s chosen types (or “none”). Tapping **Event types** opens the ticks, one per type, e.g. **Meeting** and **Work duty**; several are possible (only if an {rolle.admin} has set up event types). A new list takes over the types of its event in the {bereich.calendar}; if it has none, the app suggests matching ones from the title. Each tick is saved immediately, without saving your ticks for the people.
- **Counter**: e.g. “3 present · 0 excused · 1 absent · 5 not selected”. **Not selected** are the people with no box ticked yet.
- **Search for a person …**: only shows people whose name matches.
- **All present**: sets all people shown to present (with a search only those found).
- **The list**: everyone with a role, except {rolle.guest}. For each person there is a box for **present**, **excused** and **absent**. Next to the name is the role if it isn’t {rolle.member}. **{bereich.points} booked** means: this person has already received {bereich.points} for this list.
- **accepted** or **declined** below the name: the person’s reply to the event, with their note (only for lists of events from the {bereich.calendar} and if accepting and declining is switched on). As long as replies are still possible, there is a selection with which you enter the reply for the person.
- **Apply declines**: sets everyone who declined and has no tick yet to **excused**. The button only appears if there are such people.
- **Save**: saves your changes. Once everything is saved, it says **Saved**. Without a connection it says **Saved on this device** (see “Record without internet” below).
- **Not sent yet**: a note above the list as long as changes saved offline haven’t reached the server. **Send now** makes the app try immediately.
- **{bereich.points} for … attendees** and **Entry for … absentees**: only if you may award {bereich.points} or {bereich.penalties}.
- **Delete list**: at the very bottom.

## Step by step
### Record
1. Tap the matching box for each person. If you tap a set tick again, it is removed.
2. If almost everyone was there, first tap **All present** and then only change the exceptions.
3. Tap **Save**.

![Setting ticks in the list](bild:anwesenheitsliste-eintragen)

### Excuse in advance (upcoming event)
1. Open the list via **Upcoming events** (or create another occasion with a date in the future).
2. Above the list it says “The event is still to come. Until then you can only record “excused”.” The boxes for **present** and **absent** are locked, and **All present** and the booking buttons are missing.
3. Tick **excused** for each excused person and tap **Save**.
4. From the day of the event you enter the others in the same list as usual.

### Take over declines as excused
1. Open the list for an event from the {bereich.calendar}.
2. Anyone who declined and has no entry yet is already preselected as **excused**. Above the list it then says “Anyone who declined is preselected as “excused”.”
3. Check the selection. If you removed individual ticks, **Apply declines** sets them all again.
4. Tap **Save**. Only then is it recorded.

### Accept or decline for someone
If someone cancels e.g. by phone:
1. Open the list for the event.
2. Below the name, choose **accepted**, **declined** or **no reply**. This is saved immediately and appears in the change log.
3. For a decline, **excused** is preselected. Tap **Save** to record it.

### Book {bereich.points} for those present
1. Tap **{bereich.points} for … attendees**. The app saves unsaved changes first.
2. The {bereich.points} form opens. Selected are all present {rolle.member} accounts who don’t have {bereich.points} for this list yet. Reason and date come from the list.
3. Check the selection, choose the {bereich.points} and book them as usual under **Admin** → **{bereich.points} +**. **‹** and the list’s title at the top take you back.

![The booking buttons below the list](bild:anwesenheitsliste-buchen)

### Create an entry for those absent
1. Tap **Entry for … absentees**. The app saves unsaved changes first.
2. The {bereich.penalties} form opens with all {rolle.member} accounts who were absent. The reason is “Absent:” and the list’s title.
3. Check the selection and record the entry as usual under **Admin** → **{bereich.penalties} +**.

### Record without internet
1. Open the list while you still have a connection. The app then remembers its state, including the overview.
2. Without a connection, set the ticks as usual and tap **Save**.
3. “No connection to the server. Saved on this device; the app will send it as soon as it can.” appears. Above the list it says **Not sent yet**, and the overview shows this for the list too.
4. Once you have a connection again, the app sends the changes by itself (when the app starts, as soon as the phone is online again and when the list is opened). Then “Attendance saved offline has been sent” appears.

### Delete a list
1. Tap **Delete list** at the very bottom.
2. Confirm.

## Good to know
- The list also includes people whose role doesn’t belong to the company (e.g. candidates). But only people who belong to the company receive {bereich.points} and {bereich.penalties}.
- If you may also create members, the app shows at the top after saving who newly meets the requirement for admission as {rolle.member} as a result (see *Admin → Admissions*).
- Anyone who already has {bereich.points} for this list doesn’t get them a second time. If everyone present already has some, it says “{bereich.points} already booked for everyone present.”
- For {bereich.penalties} the list doesn’t remember who already has an entry. So don’t create it twice.
- If you delete a list, the {bereich.points} already booked are kept.
- If you move another occasion into the future, first remove the ticks for **present** and **absent**. Otherwise the list can’t be saved.
- At the bottom it shows who last saved the list.
- Offline saving is only on this device and only for you. Only the people you changed are sent. If someone else has changed the same person in the meantime, whatever reaches the server last applies.
- Without a connection no new list can be created, not even for an event without a list. For points, penalties and accepting or declining the app still needs a connection.
- The app also saves on the device if the server doesn’t respond within 20 seconds, is disrupted or in maintenance mode, or your login has expired.
- If the server rejects changes saved offline (e.g. because the list has since been deleted), **Not sent** appears at the top of the app with the list and the reason until you dismiss the note with ✕.
- If you log out before everything has been sent, the app asks: changes not sent are lost when you log out.
- An acceptance records nothing: whether someone was there is only known at the event.
- Accepting or declining for someone only works for people who may accept and decline themselves, and only until the same deadline (start or registration deadline). After that you only see the replies. A note from the person is kept.
- If an event from the {bereich.calendar} is deleted, its list no longer shows replies. The list’s entries remain.
- Everyone sees their own entries under **My area** → **{bereich.attendance}**.
- **View only:** with the permission **{bereich.attendance}: see lists per event and the {bereich.attendance} of members** but without **{bereich.attendance}: keep lists per event**, you only see the existing lists under **{bereich.attendance}**. A list then shows for each person whether they were present, excused or absent; you can’t change, create or delete anything.

## See also
- [Record {bereich.attendance}](seite:anwesenheit-erfassen)
