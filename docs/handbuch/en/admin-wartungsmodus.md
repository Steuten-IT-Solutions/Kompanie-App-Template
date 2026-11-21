---
titel: Maintenance mode
---
Maintenance mode temporarily locks the app for everyone except {rolle.admin} accounts, for example during a data transfer or larger corrections. Here you switch it on and off again.

![The Maintenance mode page with a reason entered](bild:admin-wartungsmodus)

## How to open this page
Tap **Admin** at the bottom, then **Settings** and **Maintenance mode**.

## What you see here
- **Reason (optional, shown on the maintenance page)**: a text for everyone else, at most 500 characters. Below it is how many characters you have used.
- **Switch on maintenance mode**: while the mode is off.
- **Save reason** and **End maintenance mode**: while the mode is on.
- **History**: to expand; who switched the mode on or off and when (the last 20 changes).

## Step by step
### Switch on maintenance mode
1. If needed, enter a **Reason**, e.g. “Switching to the new app, until about 6 pm”.
2. Tap **Switch on maintenance mode**.
3. Confirm the question. The app reports “Maintenance mode is on.”

While the mode is on, you see a red banner at the top of every page. You carry on working as normal.

![The red banner {rolle.admin} accounts see during maintenance](bild:admin-wartungsmodus-banner)

Everyone else only sees the maintenance page with your reason and the **Try again** button. Those who aren’t logged in find **Log in (admins)** there.

![The maintenance page as everyone else sees it](bild:admin-wartungsmodus-seite)

### Change the reason
1. Change the text under **Reason**.
2. Tap **Save reason**.

### End maintenance mode
1. Tap **End** in the red banner or **End maintenance mode** here.
2. The others see the app again as soon as they tap **Try again** or open the app again.

## Good to know
- Data already stored offline on the phones stays there. The maintenance page takes priority, though.
- Background tasks such as syncing the {bereich.calendar} and {bereich.gallery} pause. Updates and status reports continue. Notifications created during this time are only sent after the end.
- With WordPress the rest of the website stays reachable. You can also switch maintenance mode on and off there in the WordPress back end on the app’s page, in case the app itself can’t be reached.
- If you are viewing the app as another role, you also see the maintenance page (see [View the app as a role](seite:admin-vorschau)).

## See also
- [Settings](seite:admin-einstellungen)
