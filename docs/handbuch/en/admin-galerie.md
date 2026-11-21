---
titel: 'Set up the {bereich.gallery}'
---

Here you decide where the {bereich.gallery} takes its photos from: from a folder in Google Drive (the app adds new photos by itself) or only from your own server. In both cases you can create albums in the app and upload photos yourself.

![The {bereich.gallery} page in the settings](bild:admin-galerie)

## How to open this page

Tap **Admin** at the bottom, then **Settings** and **{bereich.gallery}**. In the [setup assistant](seite:admin-einrichtung) the same section is in the step for the {bereich.gallery}.

## What you see here

- **Source**: **Google Drive (plus the app’s albums)** or **Own server (only the app’s albums)**.
- **Storage location**: where submitted photos and videos and those uploaded in the app end up.
  - **Google Drive**, if the source is Google Drive and the [Google access](seite:admin-google) has full access to Drive. If files are still waiting on the server, it says how many; if moving them fails, the message appears below.
  - Otherwise **own server**, with the space used. If the connection may only read Drive, it says how to reconnect it.
- With Google Drive as the source, also:
- **It’s read via …**: which Google access is used, with a link to **Google access**. If there is none yet, it says so here.
- **Link to the gallery folder**: the address of the folder in Google Drive.
- **Last synchronisation: …**: appears if something went wrong during the last synchronisation, with the message.
- **Synchronise now**: reads the folder straight away (appears as soon as a folder is entered).
- **… images could not be loaded – show**: appears only if images could not be loaded.
- **Show download buttons**: If ticked, everyone who sees the {bereich.gallery} can download photos and videos: each one in the full view and each album as a ZIP. Without the tick (the default) these buttons don’t exist.
- **Allow members to submit images and videos**: If ticked, everyone with the _Submit photos and videos_ permission can suggest photos and videos. Without the tick there is no button for submitting in the {bereich.gallery}, and the app accepts no submissions. Newly set-up apps start without the tick; anyone who already used the {bereich.gallery} before the tick existed has it ticked.
- **Save**: applies the source, the link and the ticks for the download buttons and for submitting.
- **Albums and own photos**: all albums with the number of photos from Drive and of uploaded ones, below them **New album in the app** with **Title**, **Year** and **Create album**.

## Step by step

### Prepare the folder in Google Drive

Create a gallery folder in Google Drive. Inside it goes one folder per year, and inside that one folder per album with the photos and videos. Photos and videos in an album’s subfolder `intern` are seen by members only. You don’t need to share the folder: the app plays videos through the Google access too.

### Connect the folder

1. First set up the [Google access](seite:admin-google). With a service account, first share the gallery folder with its email address (role Viewer).
2. Open the gallery folder in Google Drive and copy the address from the browser’s address bar (e.g. `https://drive.google.com/drive/folders/…`).
3. Paste it into **Link to the gallery folder** and tap **Save**.
4. Tap **Synchronise now**. The app reads the albums, processes the photos and shows the progress, at the end for example “Done: 3 albums, 12 images and videos.”

![After synchronising: how many albums and photos were found](bild:admin-galerie-abgleich)

### Use the gallery without Google Drive

1. Under **Source** choose **Own server (only the app’s albums)** and tap **Save**. If Google Drive was entered before, its albums and photos disappear from the app; uploaded photos stay. If you switch back, the next synchronisation brings them back.
2. Create an album under **New album in the app**: enter **Title** and **Year**, tap **Create album**.

### Upload photos and videos

1. Under **Albums and own photos**, tap an album. It expands. This works with albums from the app and from Google Drive.
2. Tick **Members only (internal)** if only members should see the photos and videos.
3. Tap **Upload photos or videos** and choose one or more files: photos as JPG, PNG or WebP, videos as MP4 or WebM with at most 1 GB per video. The app shows how many are already up, for a video also as a percentage, e.g. “Uploading … 40%”. Keep the page open meanwhile.
4. Photos and videos appear in the {bereich.gallery} shortly afterwards. With **Delete** next to them you take them out again.

### Make an uploaded photo internal or public

Tick or untick **members only** next to the photo. With the tick only {rolle.member} see it, without it everyone does. The photo is briefly processed again and then has a new address. Anyone who already viewed or saved it keeps that copy. The same works in the full view of the {bereich.gallery}. You change photos from Google Drive in Drive: move them into the album’s subfolder `intern` or out of it.

For an album of the app you change **Album title** and **Album year** below it (**Save album**) or delete it with **Delete album**, together with all photos in it.

Moving, deleting several at once and editing albums also works directly in the {bereich.gallery}, also for everyone with the “Manage {bereich.gallery}” permission. How that works is described in the handbook under “Manage the {bereich.gallery}”.

### Allow downloading

1. Tick **Show download buttons** and tap **Save**.
2. Each album then has a round button with a downward arrow to the right of the title (whole album as a ZIP), and the full view has the same button at the top right next to **✕** for the single photo or video.
3. To switch it off, untick it again and tap **Save**.

### Allow submitting

1. Tick **Allow members to submit images and videos** and tap **Save**.
2. Anyone with the _Submit photos and videos_ permission sees the **Submit photos and videos** button (upward arrow) at the top of the {bereich.gallery}. You grant the permission under [Roles](seite:admin-rollen-und-mitglieder).
3. To switch it off, untick it again and tap **Save**. Photos and videos already submitted stay and can still be reviewed.

### View photos that could not be loaded

1. Tap **… images could not be loaded – show**. For each photo the list shows the file name, album, number of attempts, the error message and **Open in Google Drive**. The error messages come from the server and are in German.
2. Put the photo as a JPG into the same folder in Google Drive and remove the old one.
3. Tap **Retry all**. The app tries all of them once more and then synchronises.

## Good to know

- When uploading, the server removes metadata such as the location (GPS) and camera and saves the photo again as a JPG with at most 8192 pixels on the longer edge. How large a photo may be depends on the web hosting; if it is too large, the app says why.
- With Google Drive as the storage location everything first goes to the server (where it removes the metadata) and then to Drive in the background, large videos over several runs. Open submissions are in the folder **Einreichungen …** (submissions) in the drive of the connected Google account, outside the gallery folder, and so can’t be seen in the {bereich.gallery}. When approving, the app moves them into the album’s folder, for _Members only_ into its **intern** folder (if there is none, it creates it). Rejected, withdrawn and removed photos go to the Drive bin and can be restored there for 30 days. Photos uploaded in the app into albums from Drive move into the album’s folder after processing and then count like photos from Drive. Albums previously created only in the app get a folder in the year folder once; their photos move there too. The connected Google account needs edit rights on the gallery folder.
- Otherwise uploaded photos are stored on your own server and included in the [backup](seite:admin-updates-und-sicherung). Uploaded videos are stored there too but, because of their size, are not in the backup; keep their originals yourself. The app sets no limit on storage space; how much is used is shown here and under [Status and updates](seite:admin-zustand).
- Synchronising with Google Drive doesn’t change or delete uploaded photos. If an album you uploaded photos into disappears from Drive, it stays with these photos.
- Members with the _Submit photos and videos_ permission suggest photos and videos for any album as soon as submitting is ticked; anyone with _Review submissions_ approves them. You grant both permissions under [Roles](seite:admin-rollen-und-mitglieder); with the {bereich.gallery} comes the role _Manage {bereich.gallery}_ with the permission to review. Approved photos appear here like uploaded ones.
- Whatever isn’t finished during synchronisation, the app keeps processing hourly in the background. New photos in Google Drive are also added hourly.
- The {bereich.gallery} shows videos as MP4 or WebM. Other formats (e.g. MOV from an iPhone, AVI) appear in the list as “Videoformat nicht unterstützt” (video format not supported). Remedy: save the video as MP4 (many phones offer this when sharing or exporting) and put it in the same folder.
- The app doesn’t store videos from Google Drive on the server; it plays them from Google Drive when they are viewed. Only their preview image comes from Google and is stored next to the photos.
- Videos uploaded in the app are stored on your own server; the app doesn’t convert them. So that even large videos stay below the web hosting’s limit per request, the app uploads them in pieces. As the preview image, the browser takes a still from the first second when uploading. If it can’t play the video, it appears without a preview image, with ▶. If uploading stops, the server deletes what was started after a day at the latest. If there is too little free space on the server, the app says so beforehand.
- Videos start faster if they are saved “optimised for the web”. Many phones put a video’s table of contents at the end of the file; the browser then first has to jump there before starting. Remedy: save the video again before uploading with a program such as HandBrake (tick “Web Optimized”). Short videos and a lower resolution (e.g. 1080p instead of 4K) also load faster.
- If the server can’t read a format (e.g. RAW, TIFF, HEIC), the app takes the JPEG embedded in RAW files or Google’s preview image. What remains in the list is mostly damaged files.
- Every photo is downloaded as a JPG in full resolution (at most 8192 pixels), without metadata such as the location. Videos come unchanged from Google Drive; the app passes them through piece by piece, as when playing, and doesn’t store them. The ZIP is created in the browser; above 4 GB the app uses the ZIP64 extension. Older unzipping programs sometimes don’t know it; then e.g. 7-Zip helps. As when playing, the web hosting only delivers small pieces, so its time limits don’t matter. Internal photos and videos can only be downloaded by {rolle.member}, just as only they can see them. Anyone who has downloaded a file can pass it on; only switch the buttons on if that is fine for your photos.
- The {bereich.gallery} appears for the others only when the section is on and a folder is entered or the source **Own server** is chosen (see [Sections](seite:admin-bereiche)).
- How the last synchronisation went is also shown by the traffic light under [Status and updates](seite:admin-zustand).
- With WordPress the same setting is also in the WordPress back end on the app’s page (field “Gallery folder”, button **Save gallery**).

## See also

- [Google access](seite:admin-google)
