---
titel: Navigation
---
Here you decide which sections are in the bar at the bottom, in which order, and which sections you group in a **submenu**. You also give sections your own names and icons, choose the app’s start page and arrange the buttons of the [Admin](seite:verwaltung) area. A submenu is an item in the bar behind which there are buttons for several sections, as with **Members’ area**.

## How to open this page
Tap **Admin** at the bottom, then **Settings** and **Navigation**.

## What you see here
- At the very top you choose which bar you are editing: **For signed-in users** or **For guests** (see **A separate bar for guests** below).
- Below that it says whether the **standard navigation** currently applies or your own.
- **In the bottom bar**: the main items in their order, as many as you like. The more there are at the bottom, the smaller the text and icons get; from eight items (with **Admin** and **My area**) the page points out that it gets tight on narrow phones. **Admin** and **My area** are always on the far right and can’t be moved, so that nobody locks themselves out.
- For each section and each custom page: **Rename** with your own name and an icon.
- A **submenu** has a name, an icon and its sections.
- In the standard navigation: **Submenu of the standard navigation** with **Rename** for **Members’ area**.
- **Start page**: what appears when the app opens, separately **For guests** and **Signed in**.
- **Appearance**: **Show names under the icons**.
- **Admin**: the buttons of the **Admin start page** and under **Setup** in their order, the same for everyone. Only buttons of sections you use are listed. **You don’t see this yourself** means: you lack the permission for it, but others see the button.
- **Not in the navigation**: sections that don’t appear in the bar. A section added by an update is shown here with the note **New** and appears at the end of the bar until you give it a place.
- For each section you choose its **place**: in the bottom bar, in a submenu or not in the navigation.
- Only sections that are switched on (under [Sections](seite:admin-bereiche)) can be chosen; in the **For guests** bar also only sections and custom pages that guests may see. What is missing for this reason is listed below the list. A section that already has a place keeps it and is back in the list as soon as you switch it on or the {rolle.guest} role gets the permission for it.
- With the {bereich.pages} section, each custom page is its own item in the list (with the note **Custom page**). A new page appears at the end of the bar like a new section until you give it a place; a deleted one disappears by itself.

The navigation only changes where something is, not who may see it. Each person only sees the sections their role may see (see [Roles](seite:admin-rollen-und-mitglieder)). A submenu without a visible section doesn’t appear for this person. A section that is switched off appears for nobody.

## Step by step
### Change the order
1. Drag an item by the handle (six dots on the left) to its new place. With the keyboard, use the arrow keys on the handle. This is also how you arrange the sections in a submenu: they appear there as buttons in this order.
2. Tap **Save navigation**.

If you only rearrange the sections in the **Members’ area** submenu of the standard navigation, the standard navigation stays. Only when you change something else (e.g. move an item or rename the submenu) does it become your own navigation.

### A separate bar for guests
Guests are everyone who opens the app without logging in, and logged-in users with the base role {rolle.guest}, e.g. former members. Without a separate bar they see the same bar as signed-in users, each only with the sections they may see.

1. Tap **For guests** at the top.
2. Switch on **Separate bar for guests**. The bar starts as a copy of the bar for signed-in users; {bereich.directory} and {bereich.documents} are already at the bottom instead of in a submenu.
3. Arrange the items, create submenus or hide sections as described above.
4. Tap **Save navigation**.

The two bars are independent: signed-in users can stay with the standard navigation. Custom names and icons apply to both bars. You choose the **For guests** start page from what is in the guests’ bar and what guests may see. If you switch **Separate bar for guests** off again and save, guests see the same bar as signed-in users again. In the [preview](seite:admin-rollen-und-mitglieder) you see the guests’ bar without logging in, as the {rolle.guest} role and as a person with this base role.

### Arrange the admin buttons
1. Under **Admin**, drag a button by the handle to its new place, in the **Admin start page** or **Setup** list. A button stays in its list.
2. Tap **Save navigation**.

The order is the default for everyone; each person still only sees the buttons they have the permission for and can arrange them differently for themselves ([Arrange tiles](seite:kacheln-anordnen)). **Default order for Admin** restores the original order; then tap **Save navigation**. A button added by an update goes behind the button it would also follow without your order.

### Set a grid with places and widths for everyone
Instead of just the order, you can also decide where each tile is and how wide it is, for the admin area, Setup, each submenu and the settings:
1. Open the page and arrange the tiles as you would for yourself ([Arrange tiles](seite:kacheln-anordnen)).
2. In the note above the tiles, tap **Set as default for everyone** and confirm.

The default applies to every person who hasn’t arranged the page themselves, including without logging in and in the **View the app as …** preview. It applies to the screen width at which you set it (2 to 5 columns); for the other widths the app takes over its order until you set one there too. For someone who may not see a tile, the narrowest tile in the same row gets wider (with equal width the left one); no tile changes row. **Delete default** removes it for all widths; after that the order from here applies again. The change is recorded in the change log.

### Create a submenu
1. Tap **Add submenu**.
2. Give it a **Name** (at most 24 characters) and choose an **Icon**.
3. For each section that should go in, choose **In submenu “…”** as the place.
4. Tap **Save navigation**.

A submenu needs at least one section. **Remove submenu** moves its sections to **Not in the navigation**; then give them a new place.

This is how you enter the English name of a submenu (if your app has English switched on): at the top under **Set up in:**, choose **English**, enter the English name for the submenu under **Name of the submenu** (the German one is in grey) and tap **Save navigation**. Creating and renaming is done in **Deutsch**; if you rename a submenu there, enter the English name again afterwards. For **Members’ area** in the standard navigation it is the same name as under **Rename**.

### Rename a section or choose a different icon
1. Tap **Rename** on the section.
2. Enter a **Custom name** (at most 24 characters) and choose an **Icon** if needed. An empty field means: as before.
3. Tap **Save navigation**. The app then reloads once.

The custom name applies everywhere in the app: in the bar at the bottom, in the headings, in the admin area, in notifications and emails and in this help. The addresses stay the same, so shared links keep working. For a custom page it is only the name in the bar; its title stays. The standard name is shown next to a custom name so that you can find the section in this help. If two items have the same name, the page points this out.

If your app has English switched on besides German, this is how you enter the English name: at the top under **Set up in:**, choose **English** and rename the section as above. More on this: [Languages](seite:admin-sprachen).

This way you can also rename **Members’ area** of the standard navigation without creating your own navigation: as long as you don’t change the order in the bar at the bottom, the standard navigation stays.

### Choose the start page
1. Under **Start page**, choose one item each for **For guests** and **Signed in**. You can choose what is in the respective bar, including submenus; for signed-in users also **My area**.
2. Tap **Save navigation**.

Anyone who may not see the chosen start page lands on the first item in the bar they may see. Shared links and notifications still open their own page. The welcome for guests and the note on access are on the start page.

### Show icons only
Under **Appearance**, switch off **Show names under the icons** and tap **Save navigation**. Only the icons are then shown at the bottom, which helps with many items. The names are still shown at the top of every page.

### Back to the standard navigation
Tap **Restore standard navigation** and confirm the question. This resets the navigation: order and submenus (including those in **Members’ area** and the bar for guests), custom names and icons, the start pages and the names under the icons. The order of the admin area stays; for that there is **Default order for Admin**. In the standard navigation, {bereich.events}, {bereich.news} and {bereich.gallery} are at the bottom, followed by {bereich.companies}, {bereich.sponsors} and each custom page, as far as they are on. Anyone with the **Members’ area** permission sees at most five items at the bottom; what doesn’t fit is at the front under **Members’ area**. Anyone with the **Members’ area** permission finds {bereich.directory}, {bereich.documents}, {bereich.board}, {bereich.ranks} and {bereich.catalog} under **Members’ area**; everyone else sees {bereich.directory} and {bereich.documents} as separate items. With your own navigation, the **Members’ area** permission no longer plays a role for the bar; if guests should find the sections differently, give them a separate bar.
