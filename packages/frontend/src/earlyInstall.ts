// Angebot „App installieren“ (Android, beforeinstallprompt) schon vor dem Start der App abfangen: Es kommt oft früh, und
// die App (install.ts) lädt erst nach dem Katalog der Sprache (boot.ts). Ohne Abhängigkeiten, damit es sofort läuft.

let early: Event | null = null;
let taken = false;

if (typeof window !== 'undefined') {
  window.addEventListener('beforeinstallprompt', (e) => {
    if (taken) return; // danach kümmert sich install.ts selbst darum
    e.preventDefault(); // eigenen Knopf statt der kleinen Browser-Leiste zeigen
    early = e;
  });
  window.addEventListener('appinstalled', () => {
    early = null;
  });
}

/** Das bis jetzt abgefangene Angebot (oder null); danach fängt install.ts selbst ab. */
export function takeEarlyInstallPrompt(): Event | null {
  taken = true;
  const e = early;
  early = null;
  return e;
}
