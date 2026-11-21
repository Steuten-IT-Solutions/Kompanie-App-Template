---
titel: Account and role
---
Under **Account and role** on a member’s page you decide which role and which permissions the person has, create a new initial password, enter them as a former member or change and delete their account. Those who only maintain {bereich.points}, {bereich.penalties} or similar only see the role here.

![Account and role, opened](bild:rollen-vergeben)

## How to open this page
Tap **Admin** at the bottom, then **Members**, and tap the person. At the very bottom of their page, tap **Account and role**; the section opens.

## What you see here
You only see the parts you have the permission for:
- **Main role** and **Additional roles**: for everyone who may assign roles. Those who only see accounts read role and additional roles here.
- **Permissions**: all of the person’s permissions, with **Save permissions** (for those who may assign roles; those who only see accounts see the ticks without changing them).
- **Admission** with **Admit as {rolle.member}**: only for candidates, i.e. people with a role an {rolle.admin} has set up for this (for those who may create members). It also shows **Candidate since** and whether the requirement for admission is met. If the candidate role is the person’s role, admitting makes it {rolle.member}; if it is an additional role, admitting removes it. If no joining date has been entered yet, the app sets it to the day of admission.
- **Forgotten password? Create a new initial password** (for those who may create members). For a member without access, **Set up access** is shown here instead.
- **View the app as …**: shows the app with this person’s permissions, all their roles combined (only for {rolle.admin} accounts, not for {rolle.admin} accounts and not on your own page; more in the handbook under “View the app as a role”).
- **Former member** with **Enter as former member …** (for those who may manage former members).
- **Account** with username, email and mobile number, **Edit details**, **Export data (access request)** and **Delete account** (for those who may create members; those who only see accounts see username and email without editing and deleting). On your own page **Delete account** is missing.

## Step by step
### Change the role
1. Under **Main role**, choose the new role. The change applies immediately.
2. If you make someone an {rolle.admin}, the app asks first: {rolle.admin} accounts may do everything, including changing settings.

### Give an additional role
1. Under **Additional roles**, tick the role, e.g. “Manage {bereich.points}”. The change applies immediately.
2. Remove the tick to take the additional role away again.

### Give or remove a single permission
1. Under **Permissions**, open the group by tapping its name and set or remove the tick for the permission. The tick in front of the group sets or removes all permissions of the group at once. Groups with exceptions are already open.
2. Tap **Save permissions**. Whatever differs from the roles is marked “added” or “removed”.
3. **Same as role** removes all exceptions again.

![A person’s permissions](bild:rollen-vergeben-rechte)

### Create a new initial password
1. Tap **Forgotten password? Create a new initial password** and confirm.
2. Pass the new initial password on in person. It is only shown now; below it is the link to the installation guide.

### Give a member without access an access
1. Tap **Set up access** and confirm.
2. The app shows the login details: the link to install the app, username and initial password. Pass them on in person, e.g. with **Print** or **Copy login details**.
3. The member then has access; the **without access** label disappears. If the person makes a request themselves, you can instead link it to the member when approving under **Admin → Requests**.

### Change details or delete the account
1. Under **Account**, tap **Edit details**, change first name, surname, username, email or mobile number and tap **Save**. The person can also change their email address and mobile number themselves under **My area → Account**. If you change your own email address, enter your **Current password** to confirm.
2. To delete, tap **Delete account**, type the name to confirm and then **Delete permanently**.

![Password, former member and account](bild:rollen-vergeben-konto)

## Good to know
- The person has the permissions of all their roles combined; for {bereich.points} the smallest points factor of their roles counts. Additional roles only exist on top of a main role that belongs to the company; {rolle.guest}, {rolle.member} and {rolle.admin} can only be the main role.
- Nobody changes their own role and permissions. The last {rolle.admin} can’t be downgraded.
- Anyone who is not an {rolle.admin} only gives and removes roles and permissions they have themselves – never {rolle.admin} and never “See accounts”, “Create members”, “Manage former members” or “Assign roles” – and only changes people who have none of these four permissions and no more permissions than they do.
- Exceptions in the permissions remain when the roles change; whatever the roles then cover anyway is dropped. {rolle.admin} accounts always have all permissions, {rolle.guest} accounts those of their role: there are no exceptions for them. Settings, updates and backups remain reserved for {rolle.admin} accounts.
- After a new initial password the old one no longer works, and the person is logged out on all devices. A new username also logs them out everywhere.
- **Export data (access request)** (only for those who may also see every section it contains in the app: personal details, {bereich.points}, {bereich.penalties}, {bereich.ranks}, {bereich.titles}, {bereich.attendance}, {bereich.polls}, emails, {bereich.lists}, {bereich.board} and {bereich.offices}; usually {rolle.admin} accounts) downloads all stored data of the person as a file, e.g. when someone requests access under the GDPR (Art. 15). Answers in anonymous polls are not included (they stay anonymous); the person only gets those themselves under **Account → My details**.
- **Delete account** removes all of the person’s own data: {bereich.points}, {bereich.penalties}, attendance, {begriff.rank}, {bereich.titles}, birthday, joining date, photo, poll answers and notifications. What they entered for others remains; it then says “deleted account”. Your own account and {rolle.admin} accounts can’t be deleted here. If someone has only left or died, better enter them as a former member: then all data is kept.
- Anyone who is not an {rolle.admin} only resets passwords (and only changes or deletes accounts and photos) for people who have no more permissions than they do and who may neither see accounts, create members, manage former members nor assign roles.
- For a former member, role, permissions and password can only be changed after **Readmit**.
- **View only:** with the permission **See accounts** but without **Create members** and **Assign roles**, you see the person’s role, additional roles, permissions, admission and account, but can’t change anything. The page only shows this for people you would also be allowed to change.

> **For advanced users:** in the WordPress version, the {rolle.admin} role is given and removed in the WordPress backend, not in the app. The delete button there is called **Remove from the app**: the website user remains but no longer has a role in the app. Name and email address are deleted by a website administrator in the WordPress backend under **Users** (assigning posts to another person). The username of website users (e.g. editors) can only be changed by those who may edit them in WordPress.

## See also
- [A member’s page](seite:mitglied-uebersicht)
