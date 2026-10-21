---
titel: Mitglieder anlegen
reihenfolge: 70
rechte: [create_users]
---
Wer Mitglieder anlegen darf ({rolle.admin} oder eine Rolle mit diesem Recht), findet in der App den Bereich **Verwaltung** (unten in der Leiste, wenn es mehrere Bereiche gibt).

## Neues Mitglied
1. **Verwaltung → Mitglieder → + Mitglied anlegen** öffnen.
2. **Vorname** und **Nachname** eintragen. Nur der Vorname ist Pflicht; ohne Nachnamen geht es z. B. für ein Funktionskonto wie „Kassenwart“. **Benutzername** und **E-Mail** sind freiwillig; ohne Benutzernamen bildet die App ihn aus dem Namen.
3. Bei **Rolle** {rolle.member} oder {rolle.candidate} wählen. Ist der Bereich {bereich.birthdays} eingeschaltet, kannst du gleich den **Geburtstag** eintragen (freiwillig, das Jahr auch). Wer auch {bereich.points} bzw. {bereich.ranks} verwalten darf, kann für Mitglieder gleich den Anfangsbestand an {bereich.points} (mit Datum) und den {begriff.rank} eintragen – gedacht für die Übernahme bestehender Mitglieder.
4. Nach dem Anlegen zeigt die App die Zugangsdaten: den Link zur Installationsanleitung, Benutzername und Startpasswort. Das Startpasswort steht **nur dieses eine Mal** da: **Ausdrucken** (ein Zettel je Person), **Zugangsdaten kopieren** oder **Teilen** und persönlich weitergeben.

## Mehrere auf einmal
Unter **+ Mitglied anlegen → Mehrere auf einmal anlegen** trägst du je Person Vor- und Nachnamen und, wenn gewünscht, Benutzername, E-Mail und Anfangsbestand an {bereich.points} ein; die Rolle gilt für alle. Die App prüft zuerst alle Einträge: Ist einer ungültig (z. B. ein vergebener Benutzername), wird niemand angelegt, und die Meldung nennt die Zeile. Danach stehen die Zugangsdaten aller neuen Konten auf einer Seite, zum Ausdrucken als Zettel je Person oder zum Kopieren.

Andere Rollen vergibt danach, wer Rollen vergeben darf.

## Mitgliederliste sortieren
Unter **Verwaltung → Mitglieder** wählst du oben die **Sortierung**: nach Nachname, nach Vorname oder (wer {bereich.points} vergeben darf) nach {bereich.points}. Der Knopf daneben dreht die **Reihenfolge** um: bei Namen A → Z oder Z → A, bei {bereich.points} die meisten oder die wenigsten zuerst. Eine neu gewählte Sortierung beginnt mit A → Z bzw. mit den meisten {bereich.points}. Nach Nachname sortiert, steht in der Liste „Nachname, Vorname“. Dein Gerät merkt sich die Wahl.

Konten aus der Zeit, als es nur ein Namensfeld gab, hat die App beim Update geteilt: Das letzte Wort ist der Nachname, der Rest der Vorname. Bei Namen wie „Maria von Berg“ stimmt das nicht; dann unter **Angaben ändern** korrigieren. Mit WordPress bleiben Vor- und Nachnamen, die schon im Profil gepflegt waren.

## {rolle.candidate} aufnehmen
{rolle.candidate} nehmen vor der Aufnahme schon am Kompanieleben teil: Sie sehen den internen Kalender, aber nicht den Mitgliederbereich. In der Mitgliederliste erscheinen sie, wenn **Auch {rolle.candidate} anzeigen** angehakt ist. Ist es so weit, im Mitglied unter **Konto und Rolle** auf **Als {rolle.member} aufnehmen** tippen. Das geht nur von {rolle.candidate} zu {rolle.member}.

## Angaben ändern und Konto löschen
Im Mitglied unter **Konto und Rolle**: **Angaben ändern** für Vor- und Nachname, E-Mail und (nicht mit WordPress) Benutzername. Mit WordPress stehen Vor- und Nachname auch im Profil des Benutzers der Webseite.

**Konto löschen** entfernt alle eigenen Daten der Person: {bereich.points}, {bereich.penalties}, Anwesenheit, {begriff.rank} mit Verlauf, Titel und Benachrichtigungen; alle Geräte werden abgemeldet. Was die Person für andere eingetragen hat, bleibt; dort steht dann „eingetragen von: gelöschtes Konto“. Zur Sicherheit fragt die App nach und lässt den Namen (Vor- und Nachname) eintippen. Mit WordPress heißt der Knopf **Aus der App entfernen**: Der Benutzer der Webseite bleibt bestehen, hat aber keine Rolle in der App mehr. Ein Konto ganz löschen geht dort im WordPress-Backend unter *Benutzer*; auch dann löscht die App alle seine Daten.

## Passwort vergessen
Im Mitglied unter **Konto und Rolle** auf **Passwort vergessen? Neues Startpasswort erzeugen** tippen. Das bisherige Passwort gilt danach nicht mehr, und das Mitglied wird auf allen Geräten abgemeldet. Darunter steht der Link zur Installationsanleitung, falls die App noch nicht auf dem Handy ist.

> Wer kein {rolle.admin} ist, setzt nur Passwörter (und ändert bzw. löscht nur Konten) von {rolle.candidate} und von Personen mit Mitgliederbereich zurück, die keine weiteren Rechte als er selbst haben und weder „Mitglieder anlegen“ noch „Rollen vergeben“ dürfen. Passwörter von Admins setzen nur Admins zurück. Mit WordPress gilt für Konten, die die Webseite bearbeiten oder Benutzer verwalten dürfen, das Recht aus WordPress.
