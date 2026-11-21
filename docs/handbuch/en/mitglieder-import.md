---
titel: Import members
---
If you already have a member list, e.g. in Excel, you take everyone over here at once, including candidates and former members if you like. Afterwards the login details of all new accounts are on one page.

![“Import members” page](bild:mitglieder-import)

## How to open this page
Tap **Admin** at the bottom, then **Members**, **+ Create member** and at the top **Import from file (Excel or CSV)**. The same step also comes up when setting up the app.

## What you see here
- **‹ Create one by one**: back to the form for one person.
- **Download template (CSV for Excel)**: a table with all columns and examples.
- **Choose file (Excel .xlsx or CSV)**.
- **Or paste a table (e.g. copied from Excel)**: a field for copied cells. After choosing a file, its content appears there.
- **Preview** and, after the preview, **Apply**.

## Step by step
### Import a list
1. Tap **Download template (CSV for Excel)** and enter your members there, or use your own table. The first row contains the column names.
2. Choose the file or copy the cells into the field.
3. Tap **Preview**. It shows each row with what the app understood, and errors per row.
4. Correct errors in the file and choose it again.
5. If no row has an error, tap **Apply**.
6. Print or copy the login details and hand them over in person.

![Preview of the import](bild:mitglieder-import-vorschau)

## Good to know
- The column names and keywords are German, as in the template. Only the column **Vorname** (first name) is required. The app also knows: **Nachname** (surname, also “Name”), **Status** (also “Rolle”), **Benutzername** (username), **E-Mail**, **Handynummer** (mobile number, also “Handy”, “Mobil”), the address with **Straße** (street), **Hausnummer** (house number), **Adresszusatz** (address line 2), **PLZ** (postcode), **Ort** (town) and **Land** (country, name or code such as “AT”; empty means Germany), **Geburtstag** (birthday), **Eintritt** (date of joining), **Punkte** (points), **Dienstgrad** (rank), **Titel** (title), **Zugang** (access), **Zusatzrollen** (additional roles) and for former members **Ausgeschieden** (left) and **Grund** (reason). The order of the columns and upper or lower case don’t matter.
- **Status**: empty or {rolle.member}, another role you may assign when creating members (as when [creating one member](seite:mitglieder-anlegen), e.g. candidate or board), or **ehemalig** (former).
- **Zusatzrollen**: only if you may assign roles; the names of the roles separated by commas, e.g. “Vorstand, Kassierer”. Only for roles that belong to the company, and not for former members.
- **Zugang**: **nein** (no) creates the person as a member without access, e.g. for chronicles: without a starting password and with the label **without access** (see [Create member](seite:mitglieder-anlegen)). Empty or **ja** (yes) means with a starting password as usual. The column doesn’t count for former members.
- **Geburtstag** as DD.MM.YYYY or without a year DD.MM. (only with {bereich.birthdays}); **Eintritt** as DD.MM.YYYY or just the year, for former members also “Gründer” (founder); **Grund**: ausgetreten (left), verstorben (deceased) or sonstiges (other).
- The app only takes over **Punkte**, **Dienstgrad** and **Titel** (several separated by commas) for roles that belong to the company, and only if you may assign them. The {begriff.rank} and {bereich.titles} must already exist in the app.
- The app skips other columns (e.g. Telefon for a landline number) and names them in the preview, as well as columns for areas the company doesn’t use.
- Save older Excel files (.xls) as .xlsx first; the app reads the first worksheet.
- Anyone who already has an account with exactly this first name and surname is skipped; a second import of the same file therefore doesn’t create anyone twice. Two people with the same name in the file are an error: make them distinguishable, e.g. with “I” and “II” after the surname.
- At most 500 people at once. You add photos afterwards one by one on each member’s page.
- The starting passwords are shown **only this one time**. Members without access are not listed there. Former members get an account without login (see **Former members** in the handbook, with the permission **Manage former members**).
- The file contains personal data: delete it after the import if you no longer need it.

## See also
- [Create member](seite:mitglieder-anlegen)
- [Create several members](seite:mitglieder-mehrere-anlegen)
