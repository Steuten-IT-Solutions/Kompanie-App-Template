---
titel: Manage passive members
---
Here you keep the internal list of your passive members: surname, first name, address, phone, email address, date of birth, joining date and a note, plus one member each as contact person. You take over a list kept in Excel so far with **Import spreadsheet**. You also record who has paid the fee for a year and get reminders of special birthdays. Passive members are not accounts. They never get access to the app, are not in any member list and have no {bereich.points}, {bereich.penalties} or {bereich.attendance}.

## How to open this page
Tap **Admin** at the bottom and then the **Passive members** button.

The button exists as soon as the **Passive members** section is switched on under **Settings → Sections**, and only for everyone with the permission **Manage passive members**. When the section is switched on, the app creates the role **Manage passive members**, which you can assign as an additional role.

## What you see here
- **Counter** at the top: how many passive members are recorded, how many of them have paid the fee for the chosen year, how many are still open and how many aren’t members yet in that year (joining only later).
- **Upcoming birthdays**: passive members who will reach one of the chosen ages in the next few days, with date and age. The card only appears if ages are set, such a birthday is coming up and you may see personal details.
- **Annual fee (suggestion)** and **Payment methods**: the amount already in the field when you record a payment, and the payment methods to choose from. With **Edit** you set up both.
- **Birthday reminder**: at which ages and how many days before a reminder comes, or **off**. You set this with **Set up** or **Edit**.
- **Filter by fee** (to expand): under **Fee for** you choose the year that the counter and filter apply to. The current year is the default; the next year and earlier years can also be chosen. Under **Show** you choose all passive members, only those with the fee open or only those who have paid. If a filter is set, the button shows **active**.
- **+ Add passive member**: opens the form for a new entry.
- **Import spreadsheet**: takes over many passive members at once from an Excel file or CSV, with the fee of one year if you like.
- **Search**: finds passive members by first name and surname and by the name of the contact person.
- **List**: per passive member only the name (surname, first name). Tapping opens the passive member’s card.

On a passive member’s **card** you see:
- at the top **‹ All passive members** back to the list, next to it the arrows to the previous and next passive member in the list (as currently filtered). You can also swipe left or right.
- the name and the contact person.
- **Fees**: the current year and every year with a payment, each with amount, date and payment method or **open**. Before the joining year it says **not a member yet**, a future year without a payment is **not due yet**.
- **Details**: joining date and note (if entered) and, if you may see personal details, address, phone, email address and date of birth. Tapping the address opens the place in the maps app, tapping the number makes a call.
- at the bottom the **Edit** (pencil) and **Delete** (bin) buttons.

## Step by step
### Add a passive member
1. Tap **+ Add passive member**.
2. Enter the **First name** and **Surname**. Both are required.
3. Under **Contact person**, choose a member or leave **no contact person**.
4. If needed, enter the **Joining date**, e.g. 2023 or vor 2014 (before 2014), and a **Note**. Both are optional.
5. If you may maintain personal details, enter the address, phone, email address and date of birth. All of it is optional.
6. Tap **Save**.

### Import passive members from a spreadsheet
1. Tap **Import spreadsheet**.
2. Under **Choose file (Excel .xlsx or CSV)**, choose the Excel file (.xlsx) or a CSV file. The app reads the first worksheet. You can also copy the table from Excel and paste it into the field below.
3. Check under **Fee for the year** the year that the amounts in the list apply to, and under **Date for payments without a date** the date a payment gets if the list has none.
4. Tap **Preview**. The app looks for the header row (above it there may be e.g. a title or a date) and assigns the columns by their names, e.g. Vorname, Nachname, Straße, PLZ, Ort, Telefon, E-Mail, Geburtsdatum, Eintritt, Bemerkung and Betreuer. The column names are German.
5. Check under **Assign columns** whether each column has been recognised correctly. Change what doesn’t fit, e.g. choose under **Paid on** the column containing the date of payment, and tap **Preview** again.
6. Read the preview: per row what the app takes over, notes and errors. Rows with an **Error** are not taken over. If these are e.g. totals below the list, tick **Skip rows with errors**. Otherwise correct the file and choose it again.
7. Tap **Apply**. The app creates all passive members of the preview at once.

This is how the app reads the columns:
- **Straße** (street) may contain the house number, e.g. Musterstraße 12.
- **Betreuer** (contact person) may look like this: Anna Beispiel, Beispiel, Anna or A. Beispiel. If this matches no member or several members, the app creates the passive member without a contact person and shows a note. You then add the contact person with **Edit**.
- **Fee**: either the table has the columns **Betrag** (amount) and **Zahlungsart** (payment method), or it has one column per payment method, named like a payment method that has been set up (e.g. Bar, SEPA, PayPal) and containing the amount. Abbreviations also work, e.g. “Überw.” for a payment method Überweisung. So set up missing payment methods beforehand under **Payment methods → Edit**. If only the payment method is given without an amount, the annual fee applies.
- **Bezahlt am** (paid on) may start with a date: 12.03.2026, 12.03. (then in the year of the fee) or 2026-03-12. The app doesn’t take over text after it, e.g. “per PayPal”. If there is no date in the cell, the date for payments without a date applies.
- Cells containing only dashes, e.g. “- - -”, count as empty.
- The app doesn’t take over an invalid detail, e.g. an address abroad without a Land (country) column. The passive member is still created, and the preview shows a note.
- The app doesn’t take over columns it doesn’t know (e.g. an IBAN).

Anyone already recorded as a passive member (same first name and surname) is skipped. So you can import the same table a second time without anyone being created twice. If a name appears twice in the table, that is an error.

### Change details
1. Tap the passive member in the list and **Edit** on their card.
2. Change what is necessary and tap **Save**. With **Cancel** everything stays as it was.

### Record a payment
1. Tap the passive member in the list.
2. On the card under **Fees**, tap **Record payment** next to the year. For another year, choose it under **Payment for another year** and tap **Record**.
3. Check the **Amount in €** (the annual fee is the default) and the date under **Paid on** (today is the default), and choose the **Payment method**, e.g. Bar (cash), SEPA or PayPal.
4. Tap **Save**. The year now shows the amount with date and payment method.

There is only one payment per passive member for each year. To change or delete a payment, tap **Edit** next to the year on the card and then **Save** or **Delete payment**.

### Set up the annual fee and payment methods
1. At the top next to **Payment methods**, tap **Edit**.
2. Under **Annual fee in € (suggestion)**, enter the amount, e.g. 24.00. If you leave the field empty, there is no suggestion.
3. Adjust the **Payment methods**. Bar, SEPA and PayPal are the defaults. **+ Payment method** adds one, **×** removes one. At least one must remain.
4. Tap **Save**.

Earlier payments keep their payment method even if you remove it from the choice.

### Set up the birthday reminder
1. At the top next to **Birthday reminder**, tap **Set up** (or **Edit**).
2. Under **Remind at these ages**, enter the ages separated by commas, e.g. 50, 60, 70, 75, 80. A reminder only comes when a passive member reaches exactly one of these ages.
3. Under **Days before**, enter how many days before the birthday the reminder should come (0 to 30, default 7). 0 means on the day itself.
4. Tap **Save**.

At first no ages are entered, so the reminder is off. If you empty the field again, you switch it off.

### Delete a passive member
1. Tap the passive member in the list and **Delete** on their card.
2. Confirm the question. The entry is then deleted with all details and payments.

## Good to know
- **View only:** With the permission **See passive members** but without **Manage passive members** you see the list with contact person, fee and payments. **+ Add passive member**, **Import spreadsheet**, **Record payment**, **Edit**, **Delete** and the settings for fee, payment methods and birthday reminder are then missing.
- Address, phone, email address and date of birth are personal details. Only people with the permission **See personal details** see them, and only people with **Maintain personal details** change them. Without these permissions you only see and maintain the names, the contact person, the joining date and the note, and the import skips the columns with personal details.
- The contact person sees their own passive members under **My area → My passive members** with all details, even without the permissions for personal details. They can’t change anything there.
- Payments are recorded by anyone allowed to **Manage passive members**, even without the permissions for personal details. The contact person sees under **My area → My passive members** whether the fee for the current year has been paid.
- **Joining date in the future:** if the joining date contains a year, e.g. 2027 or 01.03.2027, the fee is only due from that year. Before that the passive member neither counts as open nor appears under **open only**. With a joining date like “vor 2014” (before 2014) or without a year, the fee applies in every year.
- Only a member can be the contact person. If the contact person’s account is deleted, the passive member stays recorded without a contact person.
- A passive member’s birthday doesn’t appear in the members’ {bereich.birthdays}.
- Everyone allowed to **Manage passive members** and **See personal details** is reminded of birthdays, not the contact person. They get a notification on their device the chosen number of days before, once per birthday, from the same time as the notification about members’ birthdays (7 am by default). For this, the type **Passive members: birthdays at the chosen ages** must be on under **My area → Account → Set up notifications**. Anyone who already gets notifications has it on automatically after setup.
- If a birthday is only entered when it is fewer days away, the reminder still comes on the next run. People born on 29 February are reminded on 28 February in years without a leap day.
- Creating (also by import), changing and deleting are recorded in the change log. Only people who may also see them here see the values of personal details there.
