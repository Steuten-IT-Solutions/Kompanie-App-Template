---
titel: Set up notifications
---
Here you see how many devices are registered for notifications and set when the reminder of events arrives. You set the birthday notification under **Calendar** in the **{bereich.birthdays}** section (only if {bereich.birthdays} is on).

![The Notifications page in the settings](bild:admin-benachrichtigungen)

## How to open this page
Tap **Admin** at the bottom, then **Settings** and **Notifications**.

## What you see here
- **… devices registered**: how many devices get notifications, of which how many with an account and how many as a guest. If messages are still waiting to be sent, their number follows.
- **Reminder of next day’s events from**: the time from which the reminder of the following day’s events is sent (6 pm at first).
- **Last sent**: for each type of notification, when the app last sent it and to how many devices, newest at the top. “to no device” means: nobody it was meant for has switched this type on on their device. Below it is when the push service (e.g. from Google or Apple) last accepted a message and, if it happened, when it last rejected one and why. The section only appears once something has been sent.

## Step by step
### Change the time of the reminder
1. Choose a time under **Reminder of next day’s events from**.
2. The app saves straight away and reports “Saved”.

## Good to know
- Each person chooses on their own device what they are notified about (**My area → Account → Notifications**, see [Notifications](seite:benachrichtigungen)). At first all types are on; new types (new section, new role) are added automatically, types someone switched off stay off.
- Each person has to switch on notifications on their device themselves, because the browser needs their consent. Members whose browser has never asked about notifications see the **Never miss a thing** window after logging in.
- If a notification doesn’t arrive on a device although it is listed under **Last sent**, the push service accepted it afterwards and no messages are waiting any more, the server has done its part. Then it is usually the device: check there whether the type is switched on and whether the phone holds back the browser’s notifications (e.g. in power saving mode).
- If the server can’t send notifications, it says what it is missing instead of the number of devices. Your hosting provider can switch this on.

> **For advanced users:** What is missing concerns encryption in PHP, the programming language the app runs in on the server (e.g. the PHP extension OpenSSL). The hosting provider switches it on in your web space’s PHP settings.

## See also
- [Settings](seite:admin-einstellungen)
