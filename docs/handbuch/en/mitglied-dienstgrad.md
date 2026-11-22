---
titel: "A member’s {begriff.rank}"
---
In this window you promote or demote a member and see how their {begriff.rank} has changed so far.

![The {begriff.rank} window with the suggested next {begriff.rank}](bild:mitglied-dienstgrad)

## How to open this page
1. Tap **Admin** at the bottom and then **Members**.
2. Tap the member’s name.
3. Tap the badge below the name. If the person has no {begriff.rank} yet, tap the circle with “+” and “No {begriff.rank}”.

From the **Promotions** overview, tapping a name opens this window directly. **✕** or the back key closes it.

## What you see here
- The current {begriff.rank} with badge, otherwise “No {begriff.rank}”. With {bereich.points} it is followed by “… more {bereich.points} to …” or “Threshold for … reached since …”, with the badge of the next {begriff.rank}.
- **New {begriff.rank}**: the selection. It only offers what is allowed, with {bereich.points} each with its threshold, e.g. “(from 50)”. If the person has a {begriff.rank}, **No {begriff.rank}** is offered too.
- **Date**: the day of the change, today is preset. An earlier day is possible, a day in the future is not.
- **Reason (optional)**: shown later in the history.
- **Change {begriff.rank}**: saves the change.
- Below it the history: every “Initial rank”, “Promotion” and “Demotion” with date, by whom, with {bereich.points} the total at that time, and the reason. The pencil on an entry changes its {begriff.rank} and date, the bin deletes it.

## Step by step
### Promote or demote
1. Under **New {begriff.rank}**, choose the desired {begriff.rank}. If you come from **Promotions**, the next one is already chosen.
2. If the promotion took place on an earlier day, e.g. at a meeting, choose that day under **Date**.
3. Enter a **Reason** if needed.
4. Tap **Change {begriff.rank}**. The change appears in the history immediately, with the chosen date.

### Correct a change afterwards
1. In the history, tap the pencil (**Change entry**) on the entry.
2. Under **New {begriff.rank}**, choose the correct {begriff.rank}, and under **New date** the correct day.
3. Tap **Save**. **Cancel** leaves everything as it was.

**{begriff.rank}**: whether the entry is a promotion or a demotion follows from the {begriff.rank} before it. With {bereich.points}, a promotion must reach the threshold of the new {begriff.rank} with the total of the entry. The next entry then starts at the new {begriff.rank}; if it already goes to the same {begriff.rank}, the app refuses (delete one of the two entries instead). The {begriff.rank} before can’t be chosen (delete the entry instead). If you change the **newest** entry, the current {begriff.rank} changes too; “since” stays.

**Date**: the new date must not be in the future, nor before the previous entry or after the next one. With {bereich.points}, the entry then shows the {bereich.points} total up to the new day; for a promotion it must reach the threshold of the {begriff.rank}. A new date alone doesn’t change the current {begriff.rank}.

### Delete an entry
If a promotion or demotion was entered by mistake, delete it completely:
1. In the history, tap the bin (**Delete entry**) on the entry.
2. The app asks, e.g. _Delete the entry “Promotion” of 01/04/2026?_, and says what applies afterwards. Tap **Delete**; **Cancel** leaves everything as it was.

If you delete the **newest** entry, the previous {begriff.rank} applies again, as if the change had never happened; “since” is then the date of the entry before. If you delete the initial rank as the only entry, the person has no {begriff.rank} afterwards. An **older** entry only disappears from the history; the current {begriff.rank} doesn’t change. The deletion is recorded in the change log and can’t be undone; enter the change again if needed.

### Take the {begriff.rank} away
1. Under **New {begriff.rank}**, choose **No {begriff.rank}**.
2. Tap **Change {begriff.rank}**.

## Good to know
- With {bereich.points}: promoting is only possible once the {bereich.points} reach the threshold of the new {begriff.rank}; several steps at once are allowed. Demoting is always possible. If it says “No change is possible at the moment: promotion only from the threshold.”, the total isn’t yet enough for any {begriff.rank}.
- **Earlier date**: with {bereich.points}, only the {bereich.points} up to that day count, for the threshold and for the total in the history. If they weren’t enough back then, the app rejects the promotion (“Threshold for … not yet reached on …”). The date must not be before the last change of this person’s {begriff.rank}, nor before an initial rank given when the person was created; otherwise the order in the history would be wrong. The new {begriff.rank} is the current one immediately in any case.
- Deactivated {bereich.ranks} are not offered. When you delete the newest entry, a deactivated {begriff.rank} before it still comes back; a deleted {begriff.rank} no longer exists, so the app refuses to delete the entry and you change the {begriff.rank} instead.
- Only members get a {begriff.rank}. Anyone who is no longer a member, e.g. after switching to the {rolle.guest} role, keeps their {begriff.rank}; you can then only set it to **No {begriff.rank}**. This also applies to former members (the admin area’s **Former members** list, only for those who may create members).
- You see the {bereich.points} total here even if you may not award {bereich.points} yourself.
- Those who have switched on notifications get a message when their {begriff.rank} changes.
- Those who also create members can enter the {begriff.rank} straight away when creating. The history shows this as “Initial rank”.
- **View only:** with the permission **See promotions** but without **Maintain {bereich.ranks}, promote and demote**, the window only shows the {begriff.rank} and the history, without the form for changing it.

## See also
- [Notifications](seite:benachrichtigungen)
