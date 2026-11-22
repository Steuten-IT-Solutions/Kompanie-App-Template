// Browser auf Android erkennen (src/androidBrowser.ts) – mit echten Kennungen der Browser.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { androidBrowser, shortcutOnly } from '../src/androidBrowser.ts';

const UA = {
  chrome: 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/158.0.0.0 Mobile Safari/537.36',
  samsung:
    'Mozilla/5.0 (Linux; Android 14; SM-S921B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/27.0 Chrome/125.0.0.0 Mobile Safari/537.36',
  edge: 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/158.0.0.0 Mobile Safari/537.36 EdgA/158.0.0.0',
  firefox: 'Mozilla/5.0 (Android 14; Mobile; rv:145.0) Gecko/145.0 Firefox/145.0',
  opera: 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36 OPR/92.0.0.0',
  webview:
    'Mozilla/5.0 (Linux; Android 14; Pixel 8 Build/AP2A; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/158.0.0.0 Mobile Safari/537.36',
  facebook:
    'Mozilla/5.0 (Linux; Android 14; SM-A546B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/158.0.0.0 Mobile Safari/537.36 [FB_IAB/FB4A;FBAV/480.0.0.0;]',
  mi: 'Mozilla/5.0 (Linux; U; Android 13; de-de; 2201117TG) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/112.0.0.0 Mobile Safari/537.36 XiaoMi/MiuiBrowser/14.5.0-gn',
};

test('erkennt die gängigen Browser', () => {
  assert.equal(androidBrowser(UA.chrome), 'chrome');
  assert.equal(androidBrowser(UA.samsung), 'samsung');
  assert.equal(androidBrowser(UA.edge), 'edge');
  assert.equal(androidBrowser(UA.firefox), 'firefox');
  assert.equal(androidBrowser(UA.opera), 'opera');
  assert.equal(androidBrowser(UA.webview), 'webview');
  assert.equal(androidBrowser(UA.facebook), 'webview');
  assert.equal(androidBrowser(UA.mi), 'other');
});

test('Brave meldet sich wie Chrome und wird über navigator.brave erkannt', () => {
  assert.equal(androidBrowser(UA.chrome, true), 'brave');
});

test('nur Chrome legt eine echte App an; Samsung ist ein eigener Fall', () => {
  assert.equal(shortcutOnly('chrome'), false);
  assert.equal(shortcutOnly('samsung'), false);
  assert.equal(shortcutOnly('webview'), false);
  for (const b of ['edge', 'firefox', 'opera', 'brave', 'other'] as const) assert.equal(shortcutOnly(b), true, b);
});
