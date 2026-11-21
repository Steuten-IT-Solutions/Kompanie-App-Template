---
titel: "Award {bereich.points}"
---
Here you enter {bereich.points} for one or more members at once, e.g. for everyone who helped with a work duty. Deductions work here too.

![The form for awarding {bereich.points}](bild:punkte-vergeben)

## How to open this page
Tap **Admin** at the bottom and then the **{bereich.points} +** button. **‹ Admin** takes you back to the admin start page.

## What you see here
- **Template from the catalogue**: frequent occasions with a fixed number of points, in the order from the [{bereich.catalog}](seite:punktekatalog). “– free text –” means: without a template.
- **{bereich.points}**: the number of points, a whole number. For a deduction, put a minus in front.
- **Date**: today is preselected.
- **Reason**: shown later in the member’s history.
- **Members**: all members to tick, with **Search members …**, **All** (or **None**) and below it how many are selected.
- **Apply the role’s {bereich.points} factor**: only appears if someone selected has a role with a factor other than 1.

## Step by step
### Award {bereich.points}
1. Choose a template under **Template from the catalogue**. It fills in **{bereich.points}** and **Reason**; you can still change both. Without a template you enter both yourself.
2. Change the **Date** if the occasion wasn’t today.
3. Tick the members. With many names the search helps; **All** ticks everyone found.
4. Tap **Award {bereich.points}**. At the bottom it briefly shows how many members received the {bereich.points}.

If you may also manage {bereich.ranks} (and the company uses {bereich.ranks}), the app checks right afterwards who has newly reached the {bereich.points} threshold for a promotion through the entry. At the top of the admin area it then shows, per member, for which {begriff.rank} the threshold has been reached. **To the promotions** takes you straight to **Promotions**, **Close** hides the note.

![Form with template and two selected members](bild:punkte-vergeben-ausgefuellt)

### Deduct {bereich.points}
1. Choose “– free text –” and enter the number of points with a minus, e.g. -2.
2. Enter the **Reason**, tick the members and tap **Award {bereich.points}**.

### Take over a suggestion from an attendance list
Those who also record {bereich.attendance} can award {bereich.points} from an attendance list. This form then opens with a suggestion:
1. At the top is the way back “‹ …” with the name of the list, below it “Suggestion: everyone present who doesn’t have {bereich.points} for … yet.” These members are already ticked.
2. **Reason** and **Date** come from the list. If you choose a template, the reason becomes “Template: name of the list”.
3. Check the selection and tap **Award {bereich.points}**. Afterwards you are back at the list.

## Good to know
- The number of points must not be 0 and can be at most 10,000 (or -10,000). The reason is required.
- Only members receive {bereich.points}, not guests.
- Nobody gets {bereich.points} twice from an attendance list: anyone who already has some for this list is skipped.
- **{bereich.points} factor**: some roles have a factor, e.g. ×0.5 for young members. It is shown in the selection after the name, e.g. “×0.5”. With **Apply the role’s {bereich.points} factor** ticked, the entry counts correspondingly less (or more); without the tick it counts in full for everyone. If someone has several roles, the smallest factor applies. An {rolle.admin} sets the factor for the roles in the settings.
- Those who have switched on notifications get a message with value and reason.
- You cancel a wrong entry on the member’s page.
- **Without internet** (or if the server is disrupted, doesn’t respond within 20 seconds or your login has expired): the app saves the entry on the device and books it by itself as soon as the connection is back. If the server then rejects it, **Not sent** appears at the top of the app until you dismiss it. Until then the form says “1 booking not sent yet”. If only the response got lost on the way, the server doesn’t book twice when it is sent again.

## See also
- [{bereich.catalog}](seite:punktekatalog)
- [A member’s {bereich.points}](seite:mitglied-punkte)
- [Notifications](seite:benachrichtigungen)
