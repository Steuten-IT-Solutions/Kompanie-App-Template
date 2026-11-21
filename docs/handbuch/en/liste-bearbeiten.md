---
titel: Edit a list
---
Here you put a list together: title, columns and rows. At the bottom you see it as it will be printed, and print it or download it as a file for Excel.

![A list with title, subtitle, date and print format](bild:liste-bearbeiten)

## How to open this page
Tap **Admin** at the bottom, then **{bereich.lists}**. Tap **Open** next to a list or create a new one.

## What you see here
- **‹ All {bereich.lists}**: back to the overview. If you haven’t saved yet, the app asks whether to discard the changes.
- **List**: **Title**, **Subtitle / note (optional)** (e.g. “Please fill in by Friday”), **Date (optional)** and **Print** (**Portrait** or **Landscape**).
- **Columns**: the list’s columns, in their order.
- **Rows**: who goes into the list, the list’s rows with number and name, and **Sort by**.
- **Save**: appears at the bottom as soon as you have changed something.
- **Output**: **Download PDF** (arrow pointing down with “PDF”), **Download CSV** (arrow pointing down with “CSV”), **Print** (printer) and below it the preview.

## Step by step
### Set up columns
There are two kinds of columns:
- **Data columns** are filled by the app with the members’ details: No. (counts the rows), name, first name, surname, role, {begriff.rank}, {bereich.titles}, birthday, address (the full address in one column), street and no., postcode, town, country, mobile number, email, {bereich.points} and {bereich.penalties} (open).
- **Empty columns** stay blank for filling in on paper, e.g. “Paid” or “Size”.

1. Under **Data column**, choose the detail and tap **+ Data column**. For a column to fill in, tap **+ Empty column**.
2. Enter a **Heading** if needed. Without its own heading the app uses the data column’s name.
3. Choose the **Width**: **narrow**, **normal**, **wide** or **automatic**. It decides how much space the column gets in the printout. **automatic** makes the column as wide as its longest entry or its heading; the other columns share the rest.
4. If an empty column should have a box in every row, tick **Checkbox to tick**.
5. To change the order, drag a column by the handle on the left (six dots) to the desired position. **Remove** takes a column out.
6. Tap **Save**.

![Columns with heading, width and checkbox to tick](bild:liste-bearbeiten-spalten)

### Bring the members into the list
1. If there are roles without the members’ area (e.g. candidates), choose under **Include in the list** who belongs in the list: **Members** and/or these roles. At least one stays ticked.
2. Under **Rows**, tap **Update members**. The app saves unsaved changes first.
3. The app shows which rows will be added and which deleted. If the list is already up to date, it just says so.
4. Tap **Apply**. **Cancel** leaves everything as it is.

![Update members: the app shows what changes](bild:liste-bearbeiten-zeilen)

### Add your own rows
1. Tap **+ Row** or **+ 5 rows**, e.g. for guests who sign in on the spot.
2. Write a name in the row or leave it empty.
3. Tap **Save**.

You move rows by the handle on the left. **✕** removes a row.

### Sort the list
1. Under **Sort by**, choose **Name (surname, first name)** or a data column, e.g. first name, birthday or {bereich.points}.
2. Choose the **Direction**: **ascending** or **descending**.
3. Tap **Sort** and then **Save**.

The app sorts numbers by their value, birthdays by the day in the year (January first). Rows without an entry in the column go to the end.

### Print or download
1. Look at the preview under **Output**.
2. Tap **Download PDF** (arrow pointing down with “PDF”). The app saves the list as a PDF file, without a print dialogue.
3. To print, tap **Print** (printer).
4. Or tap **Download CSV**. You open the file with Excel or another spreadsheet program.

![The output with print buttons and preview](bild:liste-bearbeiten-ausgabe)

## Good to know
- **View only:** without the permission to create and edit, you only see the title at the top and below it **Output** with preview, PDF, CSV and print. You then don’t change title, columns and rows.
- **Update members** creates a row for every {rolle.member} account that doesn’t have one yet, and likewise for the roles you ticked under **Include in the list**. It deletes the rows of accounts that no longer exist or no longer belong. {rolle.guest} accounts and former members never go into the list, roles without the members’ area (e.g. candidates) only if you tick them. The app sorts new members in between the existing ones by surname.
- Your own rows stay when updating. If you remove a member’s row, the next **Update members** creates it again.
- If an account is deleted, its row stays without a name until you update the list. The app points this out.
- Only data columns whose section is switched on and whose details you may see in the app anyway are offered. You only see the role, for example, if you see the admin area’s member list, {bereich.points} only if you may see them (see {bereich.points} of all members). Birthdays only appear in the list if the person has released them for others. They are written out (“9 March”), the year of birth only with the permission **See personal details** (“9 March 1990”). You only see address, mobile number and email with the permission **See personal details**.
- For mail merges or address labels, use the separate columns **Street and no.**, **Postcode**, **Town** and **Country** instead of **Address** and download the list as CSV; each detail is then in its own column. In the CSV the mobile number has 00 instead of + (e.g. 0049 171 1234567) so that Excel takes it over unchanged.
- If someone opens the list who may not see a data column, it stays empty for them, also in the printout and in the file.
- A list has at most 20 columns and 500 rows.
- If someone else has changed the list in the meantime, the app doesn’t save but tells you. Reload the list and enter your changes again.
- What is printed and downloaded is the state on the screen, even if you haven’t saved it yet.
- The printout has a bar at the top in the company’s colour with logo, title and subtitle, below it the company’s name, the list’s date and the status (today’s date). The table header is on every page, and the page number at the bottom right. In narrow columns (except No.) the heading is slanted so that longer headings fit too. Columns with names are at least as wide as the longest name.
- The PDF looks like the printout but uses a standard font. Letters it doesn’t know (e.g. “ł”) appear without the accent or as “?”. If that matters, print via **Print** and choose “Save as PDF” there.
- The app suggests the list’s title as the file name.

## See also
- [{bereich.lists}](seite:listen)
