// E-Mail-Felder (src/email.ts): Eingaben glätten, prüfen und vertippte Anbieter vorschlagen.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { emailProblem, emailSuggestion, normalizeEmail, tidyEmail } from '../src/email.ts';

test('beim Tippen: Leerzeichen raus, Domain klein, Komma zu Punkt; der Name vor dem @ bleibt', () => {
  assert.equal(tidyEmail('Max.Muster @ GMX,de '), 'Max.Muster@gmx.de');
  assert.equal(tidyEmail('max＠web.de'), 'max@web.de');
  // Ein Punkt am Ende bleibt beim Tippen stehen (es folgt noch die Endung).
  assert.equal(tidyEmail('max@gmx.'), 'max@gmx.');
  assert.equal(tidyEmail('Max, Anna'), 'Max,Anna');
});

test('nach der Eingabe: mailto, Name <…>, doppelte und äußere Punkte', () => {
  assert.equal(normalizeEmail('  mailto:Anna@Web.DE '), 'Anna@web.de');
  assert.equal(normalizeEmail('Anna Muster <anna@gmx.de>'), 'anna@gmx.de');
  assert.equal(normalizeEmail('anna@gmx..de.'), 'anna@gmx.de');
  assert.equal(normalizeEmail(''), '');
});

test('prüfen', () => {
  assert.equal(emailProblem(''), null);
  assert.equal(emailProblem('anna@gmx.de'), null);
  assert.equal(emailProblem('anna.b+verein@t-online.de'), null);
  assert.equal(emailProblem(' Anna@GMX,DE '), null);
  assert.equal(emailProblem('anna'), 'Es fehlt das @.');
  assert.equal(emailProblem('@gmx.de'), 'Vor dem @ fehlt der Name.');
  assert.equal(emailProblem('anna@'), 'Hinter dem @ fehlt der Anbieter, z. B. gmx.de.');
  assert.equal(emailProblem('anna@gmx'), 'Hinter „gmx“ fehlt die Endung, z. B. .de oder .com.');
  assert.equal(emailProblem('an@na@gmx.de'), 'Bitte nur ein @ verwenden.');
  assert.equal(emailProblem('anna..b@gmx.de'), 'Die E-Mail-Adresse ist ungültig.');
  assert.equal(emailProblem('anna@gmx.d'), 'Die E-Mail-Adresse ist ungültig.');
  assert.equal(emailProblem('anna@-gmx.de'), 'Die E-Mail-Adresse ist ungültig.');
  assert.equal(emailProblem('jörg@gmx.de'), 'Die E-Mail-Adresse ist ungültig.');
  assert.match(emailProblem('a'.repeat(95) + '@gmx.de') ?? '', /zu lang/);
});

test('vertippte Anbieter', () => {
  assert.equal(emailSuggestion('anna@gmial.com'), 'anna@gmail.com');
  assert.equal(emailSuggestion('anna@gmx.dee'), 'anna@gmx.de');
  assert.equal(emailSuggestion('Anna@Hotmal.com'), 'Anna@hotmail.com');
  assert.equal(emailSuggestion('anna@tonline.de'), 'anna@t-online.de');
  assert.equal(emailSuggestion('anna@gmail.com'), null);
  assert.equal(emailSuggestion('anna@schuetzen-musterstadt.de'), null);
  // Kurz und nah an mehreren Anbietern (gmx.de, gmx.at …): kein Vorschlag.
  assert.equal(emailSuggestion('anna@gmx.d'), null);
  assert.equal(emailSuggestion('anna'), null);
});
