// Editor für formatierte Inhalte (Issue #172): Nachrichten am Schwarzen Brett und Signaturen, später auch andere Bereiche.
//
// TipTap (MIT) auf ProseMirror (MIT), nur frei lizenzierte Pakete, keine Pro-Erweiterungen. Eigene Werkzeugleiste,
// Einzug (indent.ts), Bilder über den Bildspeicher der App (POST /rich-images, im Inhalt als data-image) und die
// Aufbereitung beim Einfügen aus Word/Outlook (wordPaste.ts). Formatierung als Inline-Styles (wie in Mails).
//
// Wird nur bei Bedarf geladen (lazyView), damit die App für alle anderen nicht größer wird.

import { useRef, useState, type ReactNode } from 'react';
import { EditorContent, useEditor, type Editor } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import { TextStyleKit } from '@tiptap/extension-text-style';
import TextAlign from '@tiptap/extension-text-align';
import Image from '@tiptap/extension-image';
import { TableKit } from '@tiptap/extension-table';
import FileHandler from '@tiptap/extension-file-handler';
import { api, type RichImageLimits } from '../api';
import { formatBytes } from '../format';
import { Indent } from './indent';
import { cleanPastedHtml, isOfficeHtml, rtfImages } from './wordPaste';

const IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/webp'];
/** Anfangsbreite eingefügter Bilder (nur die Anzeige; gespeichert wird das Bild in Originalgröße, die Breite ist änderbar). */
const IMAGE_WIDTH = 480;

/** Bild mit der Kennung aus dem Bildspeicher (data-image); nur solche Bilder behält der Server. */
const RichImage = Image.extend({
  addAttributes() {
    return {
      ...this.parent?.(),
      image: {
        default: null,
        parseHTML: (el: HTMLElement) => el.getAttribute('data-image'),
        renderHTML: (attrs: { image?: string | null }) => (attrs.image ? { 'data-image': attrs.image } : {}),
      },
    };
  },
});

const FONTS = [
  ['', 'Schriftart'],
  ['Arial, Helvetica, sans-serif', 'Arial'],
  ['Calibri, Carlito, sans-serif', 'Calibri'],
  ['Verdana, Geneva, sans-serif', 'Verdana'],
  ['Tahoma, Geneva, sans-serif', 'Tahoma'],
  ['"Trebuchet MS", Helvetica, sans-serif', 'Trebuchet MS'],
  ['Georgia, serif', 'Georgia'],
  ['"Times New Roman", Times, serif', 'Times New Roman'],
  ['"Courier New", Courier, monospace', 'Courier New'],
];
const SIZES = ['8pt', '9pt', '10pt', '11pt', '12pt', '14pt', '16pt', '18pt', '20pt', '24pt', '28pt', '36pt'];

let limits: Promise<RichImageLimits> | null = null;
/** Grenzen des Servers, einmal je Sitzung geladen. */
function serverLimits(): Promise<RichImageLimits> {
  limits ??= api.richImageLimits().catch((err) => {
    limits = null;
    throw err;
  });
  return limits;
}

/** Adresse für einen Link: https ergänzen, E-Mail-Adressen als mailto:; null, wenn nicht erlaubt. */
export function normalizeLink(input: string): string | null {
  const v = input.trim();
  if (v === '') return null;
  if (/^(https:\/\/|mailto:|tel:)/i.test(v)) return v;
  if (/^http:\/\//i.test(v)) return 'https://' + v.slice(7);
  if (/^[^\s@/]+@[^\s@/]+\.[^\s@/]+$/.test(v)) return 'mailto:' + v;
  if (/^\+?[\d\s()/-]{5,}$/.test(v)) return 'tel:' + v.replace(/[^\d+]/g, '');
  if (/^[\w-]+(\.[\w-]+)+(\/\S*)?$/.test(v)) return 'https://' + v;
  return null;
}

export interface SignatureAction {
  /** Signatur als HTML; null: noch keine eingerichtet */
  html: string | null;
  /** zur Einrichtung springen (Entwurf vorher sichern) */
  onSetup: () => void;
}

interface Props {
  /** Anfangsinhalt (HTML); spätere Änderungen von außen setzen den Inhalt nicht neu */
  value: string;
  onChange: (html: string) => void;
  /** Bezeichnung für Bildschirmleser */
  label: string;
  signature?: SignatureAction;
  /** Kennung für Tests und Verweise */
  id?: string;
}

function Button({
  editor,
  label,
  title,
  active,
  disabled,
  onClick,
  children,
}: {
  editor: Editor;
  label: string;
  title?: string;
  active?: boolean;
  disabled?: boolean;
  onClick: (e: Editor) => void;
  children: ReactNode;
}) {
  return (
    <button
      type="button"
      className={`rich-tool${active ? ' active' : ''}`}
      aria-label={label}
      title={title ?? label}
      aria-pressed={active === undefined ? undefined : active}
      disabled={disabled}
      onMouseDown={(e) => e.preventDefault()}
      onClick={() => onClick(editor)}
    >
      {children}
    </button>
  );
}

function Toolbar({ editor, onImage, signature }: { editor: Editor; onImage: () => void; signature?: SignatureAction }) {
  const style = editor.getAttributes('textStyle') as { fontFamily?: string; fontSize?: string; color?: string; backgroundColor?: string };
  const inTable = editor.isActive('table');
  const setLink = () => {
    const current = (editor.getAttributes('link').href as string | undefined) ?? '';
    const input = window.prompt('Adresse des Links (https://…, E-Mail-Adresse oder Telefonnummer). Leer entfernt den Link.', current);
    if (input === null) return;
    if (input.trim() === '') {
      editor.chain().focus().extendMarkRange('link').unsetLink().run();
      return;
    }
    const href = normalizeLink(input);
    if (href === null) {
      window.alert('Erlaubt sind Adressen mit https://, E-Mail-Adressen und Telefonnummern.');
      return;
    }
    if (editor.state.selection.empty && !editor.isActive('link')) {
      editor
        .chain()
        .focus()
        .insertContent({ type: 'text', text: input.trim(), marks: [{ type: 'link', attrs: { href } }] })
        .run();
    } else {
      editor.chain().focus().extendMarkRange('link').setLink({ href }).run();
    }
  };
  return (
    <div className="rich-toolbar" role="toolbar" aria-label="Formatierung">
      <div className="rich-group">
        <select
          className="rich-select"
          aria-label="Schriftart"
          value={FONTS.some(([v]) => v === style.fontFamily) ? style.fontFamily : ''}
          onChange={(e) =>
            e.target.value ? editor.chain().focus().setFontFamily(e.target.value).run() : editor.chain().focus().unsetFontFamily().run()
          }
        >
          {FONTS.map(([v, l]) => (
            <option key={l} value={v}>
              {l}
            </option>
          ))}
        </select>
        <select
          className="rich-select rich-size"
          aria-label="Schriftgröße"
          value={SIZES.includes(style.fontSize ?? '') ? style.fontSize : ''}
          onChange={(e) =>
            e.target.value ? editor.chain().focus().setFontSize(e.target.value).run() : editor.chain().focus().unsetFontSize().run()
          }
        >
          <option value="">Größe</option>
          {SIZES.map((s) => (
            <option key={s} value={s}>
              {s.replace('pt', '')}
            </option>
          ))}
        </select>
      </div>
      <div className="rich-group">
        <Button editor={editor} label="Fett" active={editor.isActive('bold')} onClick={(e) => e.chain().focus().toggleBold().run()}>
          <b>F</b>
        </Button>
        <Button editor={editor} label="Kursiv" active={editor.isActive('italic')} onClick={(e) => e.chain().focus().toggleItalic().run()}>
          <i>K</i>
        </Button>
        <Button
          editor={editor}
          label="Unterstrichen"
          active={editor.isActive('underline')}
          onClick={(e) => e.chain().focus().toggleUnderline().run()}
        >
          <u>U</u>
        </Button>
        <Button
          editor={editor}
          label="Durchgestrichen"
          active={editor.isActive('strike')}
          onClick={(e) => e.chain().focus().toggleStrike().run()}
        >
          <s>S</s>
        </Button>
        <label className="rich-tool rich-color" title="Textfarbe">
          <span aria-hidden="true" style={{ borderBottomColor: style.color ?? 'currentColor' }}>
            A
          </span>
          <input
            type="color"
            aria-label="Textfarbe"
            value={toHex(style.color) ?? '#000000'}
            onChange={(e) => editor.chain().focus().setColor(e.target.value).run()}
          />
        </label>
        <label className="rich-tool rich-color" title="Hintergrundfarbe (markieren)">
          <span aria-hidden="true" className="rich-highlight" style={{ backgroundColor: style.backgroundColor ?? 'transparent' }}>
            ab
          </span>
          <input
            type="color"
            aria-label="Hintergrundfarbe"
            value={toHex(style.backgroundColor) ?? '#ffff00'}
            onChange={(e) => editor.chain().focus().setBackgroundColor(e.target.value).run()}
          />
        </label>
        <Button editor={editor} label="Formatierung entfernen" onClick={(e) => e.chain().focus().unsetAllMarks().unsetTextAlign().run()}>
          ⌫
        </Button>
      </div>
      <div className="rich-group">
        {(
          [
            ['left', 'Linksbündig', '⇤'],
            ['center', 'Zentriert', '↔'],
            ['right', 'Rechtsbündig', '⇥'],
            ['justify', 'Blocksatz', '≡'],
          ] as const
        ).map(([align, label, icon]) => (
          <Button
            key={align}
            editor={editor}
            label={label}
            active={editor.isActive({ textAlign: align })}
            onClick={(e) => e.chain().focus().setTextAlign(align).run()}
          >
            {icon}
          </Button>
        ))}
        <Button
          editor={editor}
          label="Aufzählung"
          active={editor.isActive('bulletList')}
          onClick={(e) => e.chain().focus().toggleBulletList().run()}
        >
          •
        </Button>
        <Button
          editor={editor}
          label="Nummerierte Liste"
          active={editor.isActive('orderedList')}
          onClick={(e) => e.chain().focus().toggleOrderedList().run()}
        >
          1.
        </Button>
        <Button editor={editor} label="Einzug verkleinern" onClick={(e) => e.chain().focus().outdent().run()}>
          ⇠
        </Button>
        <Button editor={editor} label="Einzug vergrößern" onClick={(e) => e.chain().focus().indent().run()}>
          ⇢
        </Button>
      </div>
      <div className="rich-group">
        <Button editor={editor} label="Link" active={editor.isActive('link')} onClick={setLink}>
          🔗
        </Button>
        <Button editor={editor} label="Bild einfügen" onClick={onImage}>
          🖼
        </Button>
        <Button editor={editor} label="Trennlinie" onClick={(e) => e.chain().focus().setHorizontalRule().run()}>
          ―
        </Button>
        <Button
          editor={editor}
          label="Tabelle einfügen"
          onClick={(e) => e.chain().focus().insertTable({ rows: 2, cols: 2, withHeaderRow: false }).run()}
        >
          ▦
        </Button>
        {inTable && (
          <>
            <Button editor={editor} label="Zeile darunter einfügen" onClick={(e) => e.chain().focus().addRowAfter().run()}>
              +Z
            </Button>
            <Button editor={editor} label="Spalte rechts einfügen" onClick={(e) => e.chain().focus().addColumnAfter().run()}>
              +S
            </Button>
            <Button editor={editor} label="Zeile löschen" onClick={(e) => e.chain().focus().deleteRow().run()}>
              −Z
            </Button>
            <Button editor={editor} label="Spalte löschen" onClick={(e) => e.chain().focus().deleteColumn().run()}>
              −S
            </Button>
            <Button editor={editor} label="Tabelle löschen" onClick={(e) => e.chain().focus().deleteTable().run()}>
              ✕▦
            </Button>
          </>
        )}
      </div>
      <div className="rich-group">
        <Button editor={editor} label="Rückgängig" disabled={!editor.can().undo()} onClick={(e) => e.chain().focus().undo().run()}>
          ↶
        </Button>
        <Button editor={editor} label="Wiederholen" disabled={!editor.can().redo()} onClick={(e) => e.chain().focus().redo().run()}>
          ↷
        </Button>
      </div>
      {signature &&
        (signature.html !== null ? (
          <button
            type="button"
            className="btn btn-small btn-secondary rich-signature"
            onMouseDown={(e) => e.preventDefault()}
            onClick={() => {
              const end = editor.state.doc.content.size;
              editor
                .chain()
                .focus()
                .insertContentAt(end, '<p></p>' + (signature.html ?? ''))
                .run();
            }}
          >
            Signatur einfügen
          </button>
        ) : (
          <button type="button" className="btn btn-small btn-link rich-signature" onClick={signature.onSetup}>
            Noch keine Signatur – jetzt einrichten
          </button>
        ))}
    </div>
  );
}

/** Farbe als #rrggbb für input type=color (aus „rgb(…)“ oder „#…“), sonst null. */
function toHex(color: string | undefined): string | null {
  if (!color) return null;
  if (/^#[0-9a-f]{6}$/i.test(color)) return color;
  const m = color.match(/^rgba?\((\d+),\s*(\d+),\s*(\d+)/);
  if (!m) return null;
  return '#' + [m[1], m[2], m[3]].map((n) => Number(n).toString(16).padStart(2, '0')).join('');
}

export default function RichTextEditor({ value, onChange, label, signature, id }: Props) {
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState(0);
  /** Größte Bilddatei, die der Server annimmt (nach dem ersten Hochladen bekannt, zur Anzeige) */
  const [maxBytes, setMaxBytes] = useState<number | null>(null);
  const fileInput = useRef<HTMLInputElement>(null);
  const editorRef = useRef<Editor | null>(null);
  const changeRef = useRef(onChange);
  changeRef.current = onChange;

  /** Bilder hochladen und einfügen; meldet technische Grenzen des Servers verständlich. */
  async function upload(files: File[], pos?: number): Promise<{ uid: string; url: string; width: number }[]> {
    const ok = files.filter((f) => IMAGE_TYPES.includes(f.type));
    if (ok.length < files.length) setNotice('Erlaubt sind Bilder als JPG, PNG oder WebP.');
    const out: { uid: string; url: string; width: number }[] = [];
    setBusy((n) => n + 1);
    try {
      const max = (await serverLimits().catch(() => null))?.max_image_bytes ?? null;
      setMaxBytes(max);
      for (const file of ok) {
        if (max !== null && file.size > max) {
          setNotice(
            `„${file.name}“ ist ${formatBytes(file.size)} groß; der Server nimmt Bilder bis ${formatBytes(max)} an. Bitte ein kleineres Bild wählen.`,
          );
          continue;
        }
        try {
          const img = await api.uploadRichImage(file);
          out.push({ uid: img.uid, url: img.url, width: Math.min(img.width, IMAGE_WIDTH) });
        } catch (err) {
          setNotice((err as Error).message);
        }
      }
    } finally {
      setBusy((n) => n - 1);
    }
    const editor = editorRef.current;
    if (editor && pos !== -1) {
      const content = out.map((i) => ({ type: 'image', attrs: { src: i.url, image: i.uid, width: i.width } }));
      if (content.length > 0) {
        if (pos === undefined) editor.chain().focus().insertContent(content).run();
        else editor.chain().focus().insertContentAt(pos, content).run();
      }
    }
    return out;
  }
  const uploadRef = useRef(upload);
  uploadRef.current = upload;

  const editor = useEditor({
    shouldRerenderOnTransaction: true,
    extensions: [
      StarterKit.configure({
        code: false,
        codeBlock: false,
        heading: { levels: [1, 2, 3, 4] },
        link: {
          openOnClick: false,
          autolink: true,
          defaultProtocol: 'https',
          protocols: ['mailto', 'tel'],
          isAllowedUri: (url) => /^(https:|mailto:|tel:)/i.test(url),
          HTMLAttributes: { target: '_blank', rel: 'noopener noreferrer' },
        },
      }),
      TextStyleKit,
      TextAlign.configure({ types: ['heading', 'paragraph'] }),
      RichImage.configure({ inline: true, resize: { enabled: true, alwaysPreserveAspectRatio: true, minWidth: 16, minHeight: 16 } }),
      TableKit.configure({ table: { resizable: false } }),
      Indent,
      FileHandler.configure({
        allowedMimeTypes: IMAGE_TYPES,
        onPaste: (_e, files) => void uploadRef.current(files),
        onDrop: (_e, files, pos) => void uploadRef.current(files, pos),
      }),
    ],
    content: value,
    editorProps: {
      attributes: {
        class: 'rich-text rich-editor-content',
        'aria-label': label,
        role: 'textbox',
        'aria-multiline': 'true',
        ...(id ? { id } : {}),
      },
      // Word und Outlook (Windows): Listen umwandeln, Ballast entfernen, Bilder aus dem RTF-Teil übernehmen.
      handlePaste: (_view, event) => {
        const html = event.clipboardData?.getData('text/html') ?? '';
        if (!isOfficeHtml(html)) return false;
        event.preventDefault();
        const images = rtfImages(event.clipboardData?.getData('text/rtf') ?? '');
        const cleaned = cleanPastedHtml(html, (h) => new DOMParser().parseFromString(h, 'text/html'), images.length);
        void (async () => {
          let out = cleaned.html;
          if (images.length > 0 && out.includes('data-paste-image')) {
            const files = images.map(
              (img, i) => new File([img.bytes], `bild-${i + 1}.${img.mime === 'image/png' ? 'png' : 'jpg'}`, { type: img.mime }),
            );
            const uploaded = await uploadRef.current(files, -1);
            let k = 0;
            out = out.replace(/<img([^>]*?)data-paste-image="(\d+)"([^>]*)>/g, (_m, a: string, _i: string, b: string) => {
              const img = uploaded[k++];
              return img ? `<img${a}src="${img.url}" data-image="${img.uid}"${b}>` : '';
            });
          }
          editorRef.current?.chain().focus().insertContent(out).run();
          if (cleaned.missingImages > 0) {
            setNotice(
              cleaned.missingImages === 1
                ? 'Ein Bild konnte nicht übernommen werden. Bitte einzeln einfügen (über 🖼 oder per Kopieren nur des Bilds).'
                : `${cleaned.missingImages} Bilder konnten nicht übernommen werden. Bitte einzeln einfügen (über 🖼 oder per Kopieren nur des Bilds).`,
            );
          }
        })();
        return true;
      },
      // Bilder aus anderen Quellen (fremde Adressen) übernimmt der Server nicht: gleich weglassen und darauf hinweisen.
      transformPastedHTML: (html) => {
        const doc = new DOMParser().parseFromString(html, 'text/html');
        let dropped = 0;
        doc.querySelectorAll('img').forEach((img) => {
          if (!img.getAttribute('data-image')) {
            img.remove();
            dropped++;
          }
        });
        if (dropped > 0) setNotice('Bilder aus anderen Quellen werden nicht übernommen. Bitte das Bild speichern und über 🖼 einfügen.');
        return doc.body.innerHTML;
      },
    },
    onUpdate: ({ editor: e }) => changeRef.current(e.isEmpty ? '' : e.getHTML()),
  });
  editorRef.current = editor;

  if (!editor) return null;
  return (
    <div className="rich-editor" data-testid={id ? `${id}-editor` : undefined}>
      <Toolbar editor={editor} onImage={() => fileInput.current?.click()} signature={signature} />
      <EditorContent editor={editor} />
      <input
        ref={fileInput}
        type="file"
        accept={IMAGE_TYPES.join(',')}
        multiple
        hidden
        onChange={(e) => {
          const files = Array.from(e.target.files ?? []);
          e.target.value = '';
          if (files.length > 0) void upload(files);
        }}
      />
      {busy > 0 && (
        <p className="muted small">
          Bild wird hochgeladen …{maxBytes !== null && ` (der Server nimmt Bilder bis ${formatBytes(maxBytes)} an)`}
        </p>
      )}
      {notice && (
        <p className="form-error small rich-notice" role="status">
          {notice}{' '}
          <button type="button" className="btn btn-link btn-small" onClick={() => setNotice(null)}>
            OK
          </button>
        </p>
      )}
    </div>
  );
}
