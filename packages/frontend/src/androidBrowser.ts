// Welcher Browser auf Android? Für die Installationsanleitung (views/Installieren.tsx), Stand der Recherche 21.11.2026
// (docs/handbuch/app-installieren.md, Quellen dort):
// - Chrome legt mit Google-Diensten eine echte App an (WebAPK: in der App-Liste und unter Einstellungen → Apps) und
//   zeigt Benachrichtigungen.
// - Samsung Internet könnte das auch, aber Google Play Protect blockiert die dort erzeugte App auf Android 14+ als
//   „für eine ältere Android-Version entwickelt“ (Fehlerbericht samsunginternet/support#123, offen seit April 2026).
// - Edge, Firefox, Opera, Brave, Vivaldi und andere legen nur eine Verknüpfung an (web.dev „Installation“).
// - Eingebaute Browser anderer Apps (WebView, z. B. in WhatsApp oder Facebook) können weder installieren noch
//   Benachrichtigungen zeigen (MDN: PushManager und Notification fehlen in der Android-WebView).
// Vivaldi meldet sich wie Chrome und lässt sich nicht unterscheiden.

import { t } from './i18n';

export type AndroidBrowser = 'chrome' | 'samsung' | 'edge' | 'firefox' | 'opera' | 'brave' | 'webview' | 'other';

/** Nur für Android-Geräte sinnvoll; `brave` ist navigator.brave (gibt es nur in Brave). */
export function androidBrowser(ua: string, brave = false): AndroidBrowser {
  if (/; wv\)|FBAN|FBAV|FB_IAB|Instagram|Line\/|Snapchat|TikTok|WhatsApp/i.test(ua)) return 'webview';
  if (/SamsungBrowser/i.test(ua)) return 'samsung';
  if (/EdgA\//i.test(ua)) return 'edge';
  if (/Firefox\//i.test(ua)) return 'firefox';
  if (/OPR\/|Opera/i.test(ua)) return 'opera';
  if (brave) return 'brave';
  // Andere auf Chromium-Basis tragen einen eigenen Namen (YaBrowser, MiuiBrowser, HuaweiBrowser, DuckDuckGo, UCBrowser …).
  if (/YaBrowser|MiuiBrowser|HuaweiBrowser|HeyTapBrowser|DuckDuckGo|UCBrowser|Vivo|OppoBrowser|Silk\//i.test(ua)) return 'other';
  if (/Chrome\/\d/.test(ua)) return 'chrome';
  return 'other';
}

/** Name für den Hinweis („Dein Browser (…)“). */
export const BROWSER_NAMES: Record<AndroidBrowser, string> = {
  chrome: 'Chrome',
  samsung: 'Samsung Internet',
  edge: 'Microsoft Edge',
  firefox: 'Firefox',
  opera: 'Opera',
  brave: 'Brave',
  webview: t('der eingebaute Browser einer anderen App'),
  other: t('dieser Browser'),
};

/** Legt dieser Browser nur eine Verknüpfung an statt einer echten App? */
export function shortcutOnly(b: AndroidBrowser): boolean {
  return b === 'edge' || b === 'firefox' || b === 'opera' || b === 'brave' || b === 'other';
}
