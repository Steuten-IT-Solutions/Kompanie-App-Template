---
titel: Usage
---
Here you see how your company uses the app: how many are active, which sections are popular and who hasn’t been in the app for a long time. Only {rolle.admin} accounts see this page.

![The Usage page with the numbers of active accounts](bild:admin-nutzung)

## How to open this page
Tap **Admin** at the bottom, then **Settings** and **Usage**.

## What you see here
- **Accounts** and **Active (24 hrs)**, **Active (7 days)**, **Active (30 days)**: how many accounts there are and how many of them did something in the app during this time.
- **Period** with **From** and **To**: for the figures below. At first it is the last 30 days, at most one year.
- **Active people per day**: one bar per day on which someone used the app.
- **Usage per area**: which section was used how often and by how many people in the last 30 days. **General** covers pages such as the admin area, your own account and the settings. A **deleted** under **General** is e.g. a deleted date of birth or signature in someone’s own account, a deleted role or a deleted member. What exactly was deleted isn’t shown here but under **Changes** in the admin area, if you have the permission **View changes**. Turning notifications on and off on a device doesn’t count as usage.
- **Download as CSV file**: all entries in the period with time, name, section and action, e.g. for Excel.
- **Members**: all accounts that were active in the chosen period, with the time they were last active. Whoever has been inactive longest is at the top.
- **Tracking per person**: the switch for whether the app records who used what and when.

## Step by step
### See who hasn’t been in the app for a long time
1. Under **Members**, tick **Only those not active for 30 days**.
2. Tap a person to see their history in the chosen period: when they viewed or changed which section.

### Take the figures with you as a file
1. Choose the start and end under **Period**.
2. Tap **Download as CSV file**.
3. The file contains names. Delete it when you no longer need it.

### Switch off tracking per person
1. Untick **Tracking per person**.
2. Confirm the question. All existing entries with names are deleted immediately, including “last active”.

After that the app only counts how many people use which section, without names. The **Members** list and the CSV file are then no longer available. You can switch tracking on again at any time. It then starts from scratch.

## Good to know
- Only the section, the type (viewed, created, changed, deleted, voted, logged in) and the time are stored. What someone entered is not stored, nor are IP addresses or details about the device.
- If someone views the same section several times within 15 minutes, it counts once. What is viewed offline without a connection doesn’t count.
- Entries per person are deleted automatically after 90 days. After that only daily figures without names remain. When an account is deleted, its entries disappear too.
- The entries stay on your server and are not included in the backup.
- Tracking per person is switched on at first. Inform your members about it, e.g. in the privacy policy. The app doesn’t show the members a separate note about it.
- If you have agreed to the status reports, only totals go to the app’s maintainers: number of accounts, active accounts and people per section, never names (see [Status and updates](seite:admin-zustand)).
- If you are viewing the app as another role, this doesn’t count as usage (see [View the app as a role](seite:admin-vorschau)).

## See also
- [Settings](seite:admin-einstellungen)
