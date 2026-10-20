// Zeigt eine PDF seitenweise als Bild an (pdf.js).
//
// Warum nicht einfach <iframe> oder ein neuer Tab: In der installierten App zeigt
// das iPhone PDFs im iframe nur mit der ersten Seite, und ein neuer Tab läuft am
// Service Worker vorbei – dann wäre das Dokument offline nicht verfügbar. pdf.js
// lädt die Datei per fetch (über den Service Worker) und funktioniert überall gleich.
// Das Modul wird erst beim Öffnen eines Dokuments nachgeladen.

import { useEffect, useRef, useState } from 'react';
import { GlobalWorkerOptions, getDocument } from 'pdfjs-dist/legacy/build/pdf.mjs';
import workerUrl from 'pdfjs-dist/legacy/build/pdf.worker.min.mjs?url';
import { whenControlled } from '../offline';
import { Loading } from '../ui';

GlobalWorkerOptions.workerSrc = workerUrl;

/** Lädt den pdf.js-Worker vorab, damit er offline im Cache liegt. */
export async function prefetchWorker(): Promise<void> {
  try {
    await fetch(workerUrl);
  } catch {
    /* offline – liegt dann hoffentlich schon im Cache */
  }
}

interface PageImage {
  src: string;
  width: number;
  height: number;
}

export default function PdfViewer({ url, title }: { url: string; title: string }) {
  const box = useRef<HTMLDivElement>(null);
  const [pages, setPages] = useState<PageImage[]>([]);
  const [total, setTotal] = useState(0);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    let task: ReturnType<typeof getDocument> | null = null;
    const created: string[] = [];
    // Worker in den Cache holen, damit geöffnete PDFs auch offline angezeigt werden.
    prefetchWorker();

    (async () => {
      await whenControlled();
      let res: Response;
      try {
        res = await fetch(url, { credentials: 'same-origin' });
      } catch {
        throw new Error('Keine Verbindung und noch nicht offline gespeichert.');
      }
      if (!res.ok) {
        throw new Error(res.status === 404 ? 'Das Dokument wurde nicht gefunden.' : `Fehler ${res.status}`);
      }
      const data = new Uint8Array(await res.arrayBuffer());
      task = getDocument({ data });
      const pdf = await task.promise;
      if (cancelled) return;
      setTotal(pdf.numPages);

      // Etwa doppelte Bildschirmauflösung: bleibt beim Heranzoomen mit zwei Fingern lesbar.
      const cssWidth = box.current?.clientWidth || 360;
      const targetWidth = Math.min(1600, cssWidth * Math.max(2, window.devicePixelRatio || 1));

      for (let n = 1; n <= pdf.numPages && !cancelled; n++) {
        const page = await pdf.getPage(n);
        const viewport = page.getViewport({ scale: targetWidth / page.getViewport({ scale: 1 }).width });
        const canvas = document.createElement('canvas');
        canvas.width = Math.floor(viewport.width);
        canvas.height = Math.floor(viewport.height);
        await page.render({ canvas, viewport }).promise;
        const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/png'));
        // Speicher der Zeichenfläche sofort freigeben (wichtig auf dem iPhone).
        canvas.width = 0;
        canvas.height = 0;
        page.cleanup();
        if (!blob || cancelled) break;
        const src = URL.createObjectURL(blob);
        created.push(src);
        setPages((p) => [...p, { src, width: viewport.width, height: viewport.height }]);
      }
      await task.destroy();
    })().catch((e: Error) => {
      if (!cancelled) {
        setError(e.name === 'InvalidPDFException' ? 'Die PDF konnte nicht angezeigt werden.' : e.message);
      }
    });

    return () => {
      cancelled = true;
      task?.destroy();
      created.forEach((src) => URL.revokeObjectURL(src));
      setPages([]);
      setTotal(0);
      setError(null);
    };
  }, [url]);

  return (
    <div className="pdf-viewer" ref={box}>
      {error && (
        <p className="error" role="alert">
          {error}
        </p>
      )}
      {!error && pages.length === 0 && <Loading />}
      {pages.map((p, i) => (
        <img key={p.src} className="pdf-page" src={p.src} width={p.width} height={p.height} alt={`${title}, Seite ${i + 1} von ${total}`} />
      ))}
      {!error && total > 0 && pages.length < total && (
        <p className="muted small pdf-progress" role="status">
          Seite {pages.length + 1} von {total} wird aufbereitet …
        </p>
      )}
    </div>
  );
}
