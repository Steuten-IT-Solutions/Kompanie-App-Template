// Zeigt eine PDF seitenweise als Bild an (pdf.js).
//
// Warum nicht einfach <iframe> oder ein neuer Tab: In der installierten App zeigt
// das iPhone PDFs im iframe nur mit der ersten Seite, und ein neuer Tab läuft am
// Service Worker vorbei – dann wäre das Dokument offline nicht verfügbar. pdf.js
// lädt die Datei per fetch (über den Service Worker) und funktioniert überall gleich.
// Das Modul wird erst beim Öffnen eines Dokuments nachgeladen.
//
// Issue #18: Jede Seite wird erst gezeichnet, wenn sie in die Nähe des sichtbaren Bereichs kommt
// (IntersectionObserver), immer nur eine gleichzeitig, und als JPEG statt PNG abgelegt.
// Bis dahin hält ein leerer Platzhalter im Seitenverhältnis der Seite den Platz frei.
//
// Legacy-Build von pdf.js mit Absicht: Der moderne Build nutzt ohne Ersatz Promise.withResolvers (iOS 17.4)
// und URL.parse (iOS 18). Die App läuft aber auch auf älteren iPhones (Build-Ziel ES2020, Safari 14;
// Benachrichtigungen ab iOS 16.4). Gemessen: modern wären es 19,8 kB (Anzeige) bzw. 16,4 kB (Worker) gzip weniger.

import { useEffect, useRef, useState } from 'react';
import { GlobalWorkerOptions, getDocument, type PDFDocumentProxy } from 'pdfjs-dist/legacy/build/pdf.mjs';
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

interface Page {
  /** Seitengröße in PDF-Punkten (Seitenverhältnis des Platzhalters) */
  width: number;
  height: number;
  /** gezeichnete Seite (Blob-Adresse), solange noch nicht gezeichnet: null */
  src: string | null;
}

/** Ausschnitt um den sichtbaren Bereich, in dem Seiten schon gezeichnet werden (etwa eine Bildschirmhöhe voraus). */
const AHEAD = '100% 0px';
/**
 * JPEG statt PNG: kodiert schneller und ist kleiner; Transparenz braucht es nicht (pdf.js zeichnet auf Weiß). Gemessen
 * je Seite in 780 px: Text 327 → 306 kB, gescannte Seite 399 → 285 kB. WebP wäre noch ein Drittel kleiner, kodiert
 * aber deutlich langsamer (20 Textseiten 3,1 statt 1,0 s), und Safari kann es nicht erzeugen.
 */
const IMAGE_TYPE = 'image/jpeg';
const QUALITY = 0.85;

/** Zeichnet eine Seite und gibt sie als Blob zurück (null, wenn der Browser kein Bild liefert). */
async function renderPage(pdf: PDFDocumentProxy, n: number, targetWidth: number): Promise<Blob | null> {
  const page = await pdf.getPage(n);
  const viewport = page.getViewport({ scale: targetWidth / page.getViewport({ scale: 1 }).width });
  const canvas = document.createElement('canvas');
  canvas.width = Math.floor(viewport.width);
  canvas.height = Math.floor(viewport.height);
  try {
    await page.render({ canvas, viewport }).promise;
    return await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, IMAGE_TYPE, QUALITY));
  } finally {
    // Speicher der Zeichenfläche sofort freigeben (wichtig auf dem iPhone).
    canvas.width = 0;
    canvas.height = 0;
    page.cleanup();
  }
}

export default function PdfViewer({ url, title }: { url: string; title: string }) {
  const box = useRef<HTMLDivElement>(null);
  const [pages, setPages] = useState<Page[]>([]);
  const [error, setError] = useState<string | null>(null);
  /** Seite zum Zeichnen vormerken (gesetzt, solange das Dokument geladen ist) */
  const request = useRef<((n: number) => void) | null>(null);

  useEffect(() => {
    let cancelled = false;
    let task: ReturnType<typeof getDocument> | null = null;
    let pdf: PDFDocumentProxy | null = null;
    let targetWidth = 0;
    const created: string[] = [];
    const queue: number[] = [];
    const requested = new Set<number>();
    let busy = false;
    // Worker in den Cache holen, damit geöffnete PDFs auch offline angezeigt werden.
    prefetchWorker();

    const fail = (e: Error) => {
      if (!cancelled) {
        setError(e.name === 'InvalidPDFException' ? 'Die PDF konnte nicht angezeigt werden.' : e.message);
      }
    };

    // Vorgemerkte Seiten nacheinander zeichnen: nie mehrere Zeichenflächen gleichzeitig im Speicher.
    const pump = async () => {
      if (busy || !pdf) return;
      busy = true;
      try {
        while (queue.length > 0 && !cancelled) {
          const n = queue.shift()!;
          const blob = await renderPage(pdf, n, targetWidth);
          if (!blob || cancelled) continue;
          const src = URL.createObjectURL(blob);
          created.push(src);
          setPages((list) => list.map((p, i) => (i === n - 1 ? { ...p, src } : p)));
        }
      } catch (e) {
        fail(e as Error);
      } finally {
        busy = false;
      }
    };
    request.current = (n) => {
      if (requested.has(n)) return;
      requested.add(n);
      queue.push(n);
      void pump();
    };

    (async () => {
      await whenControlled();
      if (cancelled) return;
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
      if (cancelled) return;
      // Bilder in gescannten PDFs (JBIG2, JPEG 2000) mit den JavaScript-Dekodierern neben dieser Datei (vite.config.ts).
      // Variable statt new URL('…', import.meta.url): Vite soll den Ordner nicht beim Bauen auflösen.
      const here = import.meta.url;
      task = getDocument({ data, useWasm: false, wasmUrl: new URL(__PDFJS_DIR__, here).href });
      const doc = await task.promise;
      if (cancelled) return;

      // Etwa doppelte Bildschirmauflösung: bleibt beim Heranzoomen mit zwei Fingern lesbar.
      const cssWidth = box.current?.clientWidth || 360;
      targetWidth = Math.min(1600, cssWidth * Math.max(2, window.devicePixelRatio || 1));
      // Nur die Größen aller Seiten (ohne zu zeichnen), damit die Platzhalter stimmen und nichts springt.
      const sizes = await Promise.all(
        Array.from({ length: doc.numPages }, async (_, i) => {
          const viewport = (await doc.getPage(i + 1)).getViewport({ scale: 1 });
          return { width: viewport.width, height: viewport.height, src: null };
        }),
      );
      if (cancelled) return;
      pdf = doc;
      setPages(sizes);
      void pump();
    })().catch(fail);

    return () => {
      cancelled = true;
      request.current = null;
      task?.destroy();
      created.forEach((src) => URL.revokeObjectURL(src));
      setPages([]);
      setError(null);
    };
  }, [url]);

  // Platzhalter beobachten: Kommt eine Seite in die Nähe des sichtbaren Bereichs, wird sie gezeichnet.
  const count = pages.length;
  useEffect(() => {
    const root = box.current;
    if (!root || count === 0) return;
    const placeholders = Array.from(root.querySelectorAll<HTMLElement>('[data-pdf-page]'));
    if (typeof IntersectionObserver === 'undefined') {
      placeholders.forEach((el) => request.current?.(Number(el.dataset.pdfPage)));
      return;
    }
    const observer = new IntersectionObserver(
      (entries) => {
        for (const entry of entries) {
          if (!entry.isIntersecting) continue;
          observer.unobserve(entry.target);
          request.current?.(Number((entry.target as HTMLElement).dataset.pdfPage));
        }
      },
      { rootMargin: AHEAD },
    );
    placeholders.forEach((el) => observer.observe(el));
    return () => observer.disconnect();
  }, [count]);

  return (
    <div className="pdf-viewer" ref={box}>
      {error && (
        <p className="error" role="alert">
          {error}
        </p>
      )}
      {!error && count === 0 && <Loading />}
      {pages.map((p, i) =>
        p.src ? (
          <img key={i} className="pdf-page" src={p.src} width={p.width} height={p.height} alt={`${title}, Seite ${i + 1} von ${count}`} />
        ) : (
          // Leere Zeichenfläche im Seitenverhältnis der Seite (skaliert per CSS wie das Bild), bis sie gezeichnet ist.
          <canvas
            key={i}
            className="pdf-page pdf-pending"
            data-pdf-page={i + 1}
            width={Math.max(1, Math.round(p.width / 10))}
            height={Math.max(1, Math.round(p.height / 10))}
            role="img"
            aria-label={`${title}, Seite ${i + 1} von ${count} wird aufbereitet`}
          />
        ),
      )}
    </div>
  );
}
