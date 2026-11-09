// Links nach außen (src/link.ts): https:// ergänzen und prüfen wie Input::httpsUrl auf dem Server.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { linkProblem, normalizeLink, tidyLink } from '../src/link.ts';

test('beim Tippen: Leerzeichen raus', () => {
  assert.equal(tidyLink(' beispiel.de /app '), 'beispiel.de/app');
});

test('nach der Eingabe: https:// ergänzen, Schema klein, Port bleibt', () => {
  assert.equal(normalizeLink('beispiel.de'), 'https://beispiel.de');
  assert.equal(normalizeLink('beispiel.de/app'), 'https://beispiel.de/app');
  assert.equal(normalizeLink('www.Beispiel.de'), 'https://www.Beispiel.de');
  assert.equal(normalizeLink('//beispiel.de'), 'https://beispiel.de');
  assert.equal(normalizeLink('beispiel.de:8080/x'), 'https://beispiel.de:8080/x');
  assert.equal(normalizeLink('HTTPS://beispiel.de'), 'https://beispiel.de');
  assert.equal(normalizeLink('https://beispiel.de/a?b=1#c'), 'https://beispiel.de/a?b=1#c');
  assert.equal(normalizeLink('http://beispiel.de'), 'http://beispiel.de');
  assert.equal(normalizeLink('  '), '');
});

test('prüfen', () => {
  assert.equal(linkProblem(''), null);
  assert.equal(linkProblem('beispiel.de'), null);
  assert.equal(linkProblem('beispiel.de/app'), null);
  assert.equal(linkProblem('https://bürger-verein.de/schützenfest'), null);
  assert.equal(linkProblem('https://xn--brger-verein-wfb.de'), null);
  assert.equal(linkProblem('beispiel.de:8443'), null);
  assert.equal(linkProblem('http://beispiel.de'), 'Bitte eine sichere Adresse mit https:// angeben, nicht http://.');
  assert.equal(linkProblem('beispiel'), 'Hinter „beispiel“ fehlt die Endung, z. B. .de oder .com.');
  assert.equal(linkProblem('https://'), 'Hinter https:// fehlt die Adresse, z. B. beispiel.de.');
  assert.match(linkProblem('javascript:alert(1)') ?? '', /keine Webadresse/);
  assert.match(linkProblem('mailto:a@beispiel.de') ?? '', /keine Webadresse/);
  assert.match(linkProblem('ftp://beispiel.de') ?? '', /keine Webadresse/);
  assert.equal(linkProblem('https://nutzer@beispiel.de'), 'Die Adresse ist ungültig.');
  assert.equal(linkProblem('https://1.2.3.4'), 'Die Adresse ist ungültig.');
  assert.equal(linkProblem('https://-a.de'), 'Die Adresse ist ungültig.');
  assert.equal(linkProblem('https://a..de'), 'Die Adresse ist ungültig.');
  assert.match(linkProblem('https://beispiel.de/<x>') ?? '', /Zeichen/);
  assert.equal(linkProblem('beispiel.de/' + 'x'.repeat(250)), 'Die Adresse ist zu lang (höchstens 255 Zeichen).');
});
