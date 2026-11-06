import { detectPlatform } from './install';

/** Datei auf dem Gerät sichern. iPhone: über „Teilen“ (Bild sichern, In Dateien sichern); sonst normaler Download. */
export async function saveFile(blob: Blob, name: string, opts: { title?: string; mime?: string } = {}): Promise<void> {
  const file = new File([blob], name, { type: opts.mime ?? blob.type });
  if (detectPlatform() === 'ios' && navigator.canShare?.({ files: [file] })) {
    try {
      await navigator.share({ files: [file], title: opts.title ?? name });
      return;
    } catch (e) {
      if ((e as Error).name === 'AbortError') return;
      // Teilen nicht erlaubt → normaler Download.
    }
  }
  const href = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = href;
  a.download = name;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(href), 60_000);
}
