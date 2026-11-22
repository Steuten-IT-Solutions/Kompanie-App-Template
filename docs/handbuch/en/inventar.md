---
titel: "{bereich.inventory}"
---
In the {bereich.inventory} you keep track of what the company owns, how much of it there is and where it is kept. Here you create items, change quantities and move them to another place.

![The {bereich.inventory} with an opened item and its places](bild:inventar)

## How to open this page
Tap **Admin** at the bottom and then the **{bereich.inventory}** button. If this is the only button in the admin area, the {bereich.inventory} opens straight away.

## What you see here
- **+ Add entry**: opens the form for a new item.
- **Full history**: shows all changes in the {bereich.inventory} (see [History in the {bereich.inventory}](seite:inventar-verlauf)).
- **Search {bereich.inventory} …**: finds items by name, location, category or note. With a search, all hits are open.
- **The list**: each row is an item with the total across all places, e.g. “18 pieces”. Below it is the location or “in 2 places”. Identical names belong together, even with different upper and lower case. The app doesn’t add up different units but shows them side by side, e.g. “12 pieces · 3 kg”.
- **Tap an item**: opens it. You see the quantity, category and note per place.
- **−** and **+**: change the quantity at this place by 1.
- **Move**, **Edit** (pencil), **History** (clock), **Delete** (bin): buttons for the entry at this place.

## Step by step
### Create an item
1. Tap **+ Add entry**.
2. Enter the **Name** and the **Location**. Both are required.
3. Enter the **Quantity**. If left empty, 0 applies. Decimals work too, e.g. 2.5.
4. If needed, enter **Unit** (e.g. pieces, kg, m), **Category (optional)** and **Note (optional)**.
5. If needed, add a **Reason**, e.g. “Donation”. It appears in the history.
6. Tap **Create**.

![The form for a new entry, filled in](bild:inventar-anlegen)

For location, unit and category the app suggests what already exists. If you write a known location, unit or category only with different upper or lower case, the app uses the known spelling.

### Change the quantity
1. Tap the item to open it.
2. At the right place, tap **−** or **+**.

For larger changes, tap **Edit**, enter the new **Quantity**, add a reason if needed (e.g. “Stocktake”) and tap **Save**.

### Move an item
1. Open the item and, at the place it leaves, tap **Move**.
2. Enter the **Quantity**. Everything kept there is preset.
3. Enter the **Destination**.
4. Add a **Reason (optional)** if needed.
5. Tap **Move**.

![The form for moving, filled in](bild:inventar-umlagern)

### Delete an entry
1. Open the item and tap **Delete** at the place.
2. The app asks for a reason. You can leave it empty.
3. Confirm with **OK**.

## Good to know
- **View only:** with the permission **See {bereich.inventory} with storage locations and history** but without the permission to change, you see the list, the places and the history. **+ Add entry**, **−**, **+**, **Move**, **Edit** and **Delete** are then missing.
- An entry has exactly one location. If the same item is in two places, these are two entries.
- The quantity is 0 or more, with at most three decimal places. Below 0 is not possible; if less than 1 is there, **−** is locked.
- When moving: if the destination already has the same item with the same unit, the quantity is added there. Otherwise a new entry is created at the destination.
- If you move everything, the entry at the old place disappears. If there is nothing of it at the destination yet, only its location changes.
- You can’t move more than there is. The destination must be a different place. The app reports this, and nothing changes.
- If you change the location under **Edit**, you only correct the detail. Nothing is merged with the same item at the new place; use **Move** for that.
- Every change appears in the history, even for deleted entries.
- Only people with one of the two {bereich.inventory} permissions see the {bereich.inventory}. Other {rolle.member} accounts don’t see it.

## See also
- [History in the {bereich.inventory}](seite:inventar-verlauf)
