---
titel: Set up email
---
Here you enter the company address and the mail server. The app uses them to send emails, e.g. messages from the **{bereich.board}** section to all {rolle.member} accounts and the emails from the **{bereich.mail}** section in the admin area. Without these details there is no **Send email** tick when writing on the board and no form in the **{bereich.mail}** section.

![The Email page in the settings](bild:admin-email)

## How to open this page
Tap **Admin** at the bottom, then **Settings** and **Email**.

## What you see here
- **Company address (sender)**: the address the emails come from, e.g. info@… Replies and undeliverable emails land in this mailbox.
- **Sender name**: the name shown as the sender. If the field stays empty, it is the name of {kompanie.name}.
- **Mail server (SMTP)**, **Port**, **Encryption**, **Username**, **Password**: the login details for this mailbox’s outgoing mail.
- **Sending in batches**: for hosting providers that only allow a certain number of emails per hour.
- **Check connection** and **Send test email**: appear as soon as the address and mail server are saved.
- Below: how large an email may be at most (if the mail server reports it) and how many emails are still waiting to be sent.

## Step by step
### Set up sending emails
1. Enter the **Company address**.
2. Enter the **Mail server (SMTP)**, **Port** and **Encryption**. These details are provided by the mailbox provider (hosting or email provider), usually under “SMTP” or “outgoing mail server”. Common are port 587 with STARTTLS or port 465 with SSL/TLS.
3. Enter the mailbox’s **Username** (usually the email address) and **Password**.
4. Tap **Save**.
5. Tap **Check connection**. If it works, it shows up to what size the mail server accepts emails.
6. Tap **Send test email**. Without an address it goes to your own; check your mailbox.

### Set up sending in batches
1. Tap **Sending in batches**.
2. Under **Emails per batch**, enter how many emails the app sends at once, and under **Pause (minutes)** how long it waits afterwards.
3. Tap **Save**.

0 means: all at once or without a pause. This is the initial setting.

## Good to know
- The app never shows the password again. If you leave the field empty, the saved password stays.
- The app sends the emails in the background, each one individually to one person. If an email fails, it tries again later (up to five times). Anyone writing a message on the board sees in the **History** who it went to.
- Each person can switch off emails from the board under **My area → Account**. Anyone without an email address gets no email.
- When sending in batches, the app checks every 15 minutes; a pause therefore lasts at least until the next time.
- The recipients’ mail servers may have smaller limits than your own; rejected emails come back as bounces to the company address.

> **For advanced users:** The app sends via SMTP with PHPMailer, in WordPress with the copy that WordPress ships (not via wp_mail()). It reads the maximum size from the server’s response (SIZE according to RFC 1870).

## See also
- [Settings](seite:admin-einstellungen)
