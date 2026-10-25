// Editor zum Schreiben formatierter Texte mit „Signatur einfügen“: am Schwarzen Brett und für E-Mails aus der Verwaltung
// derselbe Editor (TipTap, RichTextEditor), erst geladen, wenn jemand schreibt.

import { Suspense, useEffect, useState } from 'react';
import { api } from './api';
import { SIGNATURE_RETURN_KEY } from './board';
import { navigate } from './router';
import { lazyView, Loading } from './ui';

const RichTextEditor = lazyView(() => import('./richtext/RichTextEditor'));

/** Eigene Signatur (für „Signatur einfügen“); undefined, solange sie lädt. */
function useSignature(): string | null | undefined {
  const [html, setHtml] = useState<string | null | undefined>(undefined);
  useEffect(() => {
    let alive = true;
    api
      .signature()
      .then((r) => alive && setHtml(r.signature?.content ?? null))
      .catch(() => alive && setHtml(null));
    return () => {
      alive = false;
    };
  }, []);
  return html;
}

/** Zur Einrichtung der Signatur (Konto); von dort führt ein Knopf zurück zu `returnTo`, wo der gesicherte Entwurf wartet. */
function setupSignature(returnTo: string, saveDraft?: () => void): void {
  saveDraft?.();
  try {
    sessionStorage.setItem(SIGNATURE_RETURN_KEY, returnTo);
  } catch {
    // ohne Speicher: kein Weg zurück, der Entwurf bleibt trotzdem
  }
  navigate('meins/konto/signatur');
}

export function ComposeEditor(props: {
  value: string;
  onChange: (html: string) => void;
  label: string;
  id: string;
  /** Seite, zu der die Signatur-Einrichtung zurückführt (z. B. „brett“) */
  returnTo: string;
  /** Entwurf sichern, bevor es zur Signatur-Einrichtung geht */
  saveDraft?: () => void;
}) {
  const signature = useSignature();
  return (
    <Suspense fallback={<Loading />}>
      <RichTextEditor
        value={props.value}
        onChange={props.onChange}
        label={props.label}
        id={props.id}
        signature={
          signature === undefined ? undefined : { html: signature, onSetup: () => setupSignature(props.returnTo, props.saveDraft) }
        }
      />
    </Suspense>
  );
}
