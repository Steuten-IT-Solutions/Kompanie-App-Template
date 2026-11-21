---
titel: Roles
---
Here you decide which roles there are and what each role may do in the app. You choose who gets which role on the person’s page in the admin area (see [Account and role](seite:rollen-vergeben)).

![The Roles page with the list of all roles](bild:admin-rollen-und-mitglieder)

## How to open this page
Tap **Admin** at the bottom, then **Settings** and **Roles**.

## What you see here
At the top you switch between **Roles** (the list) and **Who sees what** (the overview).

- **List of roles**: per role the name, how many people have it (of which as an additional role) and its permissions. Custom roles also show **for new accounts** or **Candidate role** if that is set. If the {bereich.points} factor differs from 1, it is shown as a badge next to the name, e.g. “×0.5”.
- **Handle** to the left of each role: for changing the order.
- **View the app as …** (eye): shows the app as this role sees it (see [View the app as a role](seite:admin-vorschau)). For {rolle.admin} the button is missing.
- **Edit** (pencil): opens the role for changing.
- **+ New role**: creates another role.
- **View the app without logging in**: shows the app as visitors without an account see it. How a particular person sees the app with all their roles is shown by **View the app as …** on their page under **Account and role**.
- **{bereich.attendance}: groups in the count** (only if {bereich.attendance} is on): what each role counts as in the counter above the member list. Without a setting, all roles that belong to the company count as {rolle.member}, the others (e.g. candidates) each on their own.
- **Who sees what**: a table with all permissions as rows and all roles as columns. This way you see at a glance which role sees which button, and change it directly.

## Step by step
### Create a role
1. Tap **+ New role**.
2. Enter the **Name of the role** (at most 30 characters).
3. Under **Permissions**, tick everything the role sees and may do. A new role at first sees what {rolle.member} sees. The permissions are in groups; tap a group’s name to expand or collapse it. The tick in front of the group sets or removes all permissions of the group at once. Sections that can be viewed and changed, e.g. **{bereich.points}**, are on one row with the two ticks **Read** and **Write**; below it says what each allows. **Write** also sets **Read**.
4. If needed, set under **Accounts** whether the role is **Available when creating new accounts** (then also for everyone who may only create members; anyone who may assign roles can choose any role they may assign when creating anyway) and whether it is a **Candidate role**. Then anyone allowed to create members admits people with this role as {rolle.member} under *Admin → Admissions*. This works with the role as the person’s role (it becomes {rolle.member} on admission) and as an additional role to {rolle.member} (it is removed on admission).
   For a candidate role you set the **Requirement for admission** below. It consists of conditions: **+ Attendances** (at least so many attendances (“present”) at events of a type) and **+ Duration** (at least so many months as a candidate). At the top you choose how they relate: **All must be met** (AND) or **One is enough** (OR). With **+ Group** you add an indented group with its own choice, e.g. “12 months as a candidate **and** (6 attendances at meetings **or** 10 attendances at work duty)”; groups can contain groups again. Below, the app reads out the whole requirement in one sentence. Up to 20 conditions and 20 groups are possible; without a condition there is no requirement. You create the event types under *Settings → Calendar*. Anyone who meets the requirement is at the top under *Admin → Admissions*.
5. If you use {bereich.points} and the role belongs to the company, enter the **{bereich.points} factor** (e.g. 0.5 for half).
6. Tap **Create**.

![A new role “Candidate” with the internal calendar, not yet created](bild:admin-rollen-und-mitglieder-neu)

### Change or delete a role
1. Tap **Edit** on the role.
2. Change the name, permissions, accounts or {bereich.points} factor and tap **Save**. With **Cancel** you discard the changes.
3. To delete a custom role, tap **Delete** instead. If people still have the role, first choose under **On deletion, the … people get the role** which role they get instead. Confirm the question.

You enter a role’s name in English (if your app has English switched on) by choosing **English** at the top under **Set up in:** and then editing the role. More on this: [Languages](seite:admin-sprachen).

### Decide in the overview who sees what
1. Tap **Who sees what** at the top.
2. Expand a group by tapping its name, e.g. **Members’ area**.
3. Set or remove the ticks in a role’s column, e.g. for **{bereich.ranks}** for {rolle.member}. The tick in the group’s row sets all permissions of the group for this role.
4. Tap **Save**. With **Discard** you undo all changes.

### Change the order
1. Hold a role by the handle on the left and drag it up or down. On a computer this also works with the arrow keys on the handle.
2. Let go. The app saves the new order straight away.

### Decide how roles count in the counter
1. Under **{bereich.attendance}: groups in the count**, choose **Own group** or **counts as …** another role for each role.
2. Tap **Save groups**.

## The permissions
Each permission stands on its own; none includes another. The permissions are in these groups:
- **Tabs and navigation**: public {bereich.events}, {bereich.news}, the public albums of the {bereich.gallery} and **Members’ area** (the buttons under **Members’ area** collected under one button, see [Members’ area](seite:intern)).
- **Membership**: **Belongs to the company** (the person gets {bereich.points}, {bereich.penalties} and {bereich.ranks} and appears in lists and in the {bereich.attendance}), their own {bereich.points}, {bereich.penalties}, their own {begriff.rank} and their own {bereich.attendance} in **My area**, the internal calendar, the internal pictures of the {bereich.gallery}, the others’ {bereich.birthdays} and taking part in {bereich.polls}.
- **Members’ area**: one permission per button under **Members’ area**: {bereich.directory}, {bereich.offices}, former members, {bereich.documents}, read {bereich.board}, {bereich.ranks} and {bereich.catalog}.
- **Manage content** and **Manage company**: one permission per section with its own tasks, for example **Award {bereich.points}, maintain catalogue** or **Maintain public {bereich.events}**.
- **Members and roles**: **See personal details** (address, mobile number, email address and date of birth of the members on their page in the admin area), **Maintain personal details** (change these details), **Create members** and generate starting passwords, **Manage former members** (list of former members, create and import former members, enter members as former and readmit them) and **Assign roles**.

Own data in **My area** and all permissions for managing only exist together with **Belongs to the company**: if you tick one of them, the app also ticks **Belongs to the company**. If you remove it, they are dropped. The other viewing permissions also work without it, e.g. for candidates.

Many sections have a permission for viewing and one for changing, e.g. **See {bereich.points} of all members** and **Award {bereich.points}**. Anyone who may only view sees the same details but without buttons and forms for changing. The permission for changing only exists together with the one for viewing: if you tick it, the app also ticks viewing; if you remove viewing, changing is dropped too. This applies to:
- **See personal details** and **Maintain personal details**
- **See {bereich.points} of all members** and **Award {bereich.points}, maintain catalogue**
- **See {bereich.penalties} of all members** and **Issue {bereich.penalties} and mark them as paid**
- **See promotions** ({begriff.rank} of the members with history and the promotions overview) and **Maintain {bereich.ranks}, promote and demote**
- **See {bereich.titles}** and **Maintain and award {bereich.titles}**
- **See {bereich.attendance}** and **{bereich.attendance}: keep lists per event**

Each of these viewing permissions also opens the admin member list.

Settings, updates and backup always remain reserved for {rolle.admin} accounts; you can’t give this permission to any other role.

## Good to know
- **Fixed roles:** {rolle.guest} also applies to everyone who opens the app without logging in. {rolle.member} is the role of new accounts and always belongs to the company. {rolle.admin} may do everything. You can rename these three, but not delete them. For {rolle.member} and {rolle.guest} you can tick any permission, including permissions for managing, e.g. **Submit photos and videos**; every person with this role then gets it. {rolle.guest} never belongs to the company; permissions that otherwise require **Belongs to the company** also work for {rolle.guest} without it. The permissions of {rolle.admin} can’t be changed. You can change the {bereich.points} factor for {rolle.member} and {rolle.admin}.
- **{rolle.guest} without logging in:** what {rolle.guest} sees and may do, anyone who opens the app sees and may do, even without an account. So only give {rolle.guest} e.g. the {bereich.documents} or the {bereich.birthdays} if they really should be public. A permission for managing for {rolle.guest} means: anyone on the internet can do it, e.g. change or delete events. Functions that need a person (e.g. own submissions) still require logging in. The shortcodes on the website also only show what {rolle.guest} sees.
- **Personal details:** each person sees and changes their own details under **My area → Account**. Anyone allowed to create members enters them when creating, but afterwards only sees them with **See personal details**. Roles that had **Create members** before this permission existed have both permissions for personal details after the update; remove them there if the role doesn’t need them.
- **Former members:** the **Former members** tile and everything about it comes with the permission **Manage former members**. Roles that previously had **Create members** also have this permission after the update. If, e.g., the role that awards {bereich.points} should also maintain former members, tick it there. Only {rolle.admin} accounts set how long the data of former members is kept. The **Former members** permission under **Members’ area** only allows viewing in the member list.
- **Accept and decline:** the permission **Accept or decline events in the internal calendar** only exists together with **Internal calendar**, not just with **Belongs to the company**, so that candidates can also accept. After the update every role with the internal calendar except {rolle.guest} has it.
- **Viewing and changing separated:** roles and people who had a permission for changing before this separation also have the matching viewing permission after the update. If someone should, e.g., only see the {bereich.points}, remove **Award {bereich.points}**. There are separate viewing permissions for {bereich.points}, {bereich.penalties}, ranks with promotions, {bereich.titles}, {bereich.attendance}, {bereich.inventory}, {bereich.lists}, the history of {bereich.mail}, the responses to events, the passive members, the submissions to the {bereich.gallery}, the bug reports, the accounts, restricted {bereich.documents}, all results of the {bereich.polls}, the history of the {bereich.board} and the assignment to {bereich.companies}.
- **After the update:** every role at first sees exactly what it saw before. After that you can remove or add each permission individually.
- **Roles of the sections:** if you switch on a section with its own tasks, a role is added that is named like the section with “Manage” in front, e.g. “Manage {bereich.points}”. It sees what {rolle.member} sees at first and has the section’s permission (see [Sections](seite:admin-bereiche)).
- **Base role and additional roles:** each person has exactly one base role and, if desired, additional roles; they have the permissions of all their roles together. Additional roles can only be custom roles and roles of the sections. They only exist with a base role that belongs to the company.
- **{bereich.points} factor:** it applies when booking, for new bookings; old ones stay as they are. If someone has several roles, the smallest factor applies. {rolle.guest} and roles that don’t belong to the company have none because they don’t get {bereich.points}.
- **Order:** it applies everywhere roles are listed: in choice lists, for the additional roles and in the counters of the member list and the {bereich.attendance}.
- **Deleting:** anyone who has the deleted role as an additional role gets the chosen replacement role as an additional role if it is a custom role; otherwise it is dropped.
- There are at most 30 roles in total.
- With WordPress, the website’s administrators are the app’s {rolle.admin} accounts. You assign this role in the WordPress back end, not in the app.

> **Example candidates:** create a role “Candidate” with **Internal calendar** and **Read {bereich.board}**, without **Belongs to the company**, with **Available when creating new accounts** and the tick for admitting as {rolle.member}. Candidates then see the internal calendar and the {bereich.board} and are in the member list and the {bereich.attendance}, but don’t get {bereich.points} or {bereich.penalties}. The name of the role is shown in both member lists like a title next to the name, also in the public one under **{bereich.directory}**. Later, anyone allowed to create members admits them.

## See also
- [View the app as a role](seite:admin-vorschau)
- [Account and role](seite:rollen-vergeben)
- [Create member](seite:mitglieder-anlegen)
