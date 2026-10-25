// Einzug für Absätze und Überschriften (Issue #172). TipTap hat dafür kein offizielles Paket. Gespeichert als
// margin-left in Pixeln (je Stufe INDENT_STEP), damit es in Mails ohne zusätzliches CSS gleich aussieht. In Listen
// rückt der Einzug den Listenpunkt ein bzw. aus (sinkListItem/liftListItem).

import { Extension } from '@tiptap/core';

export const INDENT_STEP = 40;
const MAX_LEVEL = 10;
const TYPES = ['paragraph', 'heading'];

declare module '@tiptap/core' {
  interface Commands<ReturnType> {
    indent: {
      indent: () => ReturnType;
      outdent: () => ReturnType;
    };
  }
}

/** Stufe aus margin-left (px, pt, em, cm), z. B. beim Einfügen aus Word. */
export function indentLevel(marginLeft: string): number {
  const m = marginLeft.trim().match(/^(-?[\d.]+)(px|pt|em|rem|cm|mm|in)?$/);
  if (!m) return 0;
  const n = parseFloat(m[1]);
  const px = { px: 1, pt: 4 / 3, em: 16, rem: 16, cm: 37.8, mm: 3.78, in: 96 }[m[2] ?? 'px'] ?? 1;
  return Math.max(0, Math.min(MAX_LEVEL, Math.round((n * px) / INDENT_STEP)));
}

export const Indent = Extension.create({
  name: 'indent',

  addGlobalAttributes() {
    return [
      {
        types: TYPES,
        attributes: {
          indent: {
            default: 0,
            parseHTML: (el: HTMLElement) => indentLevel(el.style.marginLeft || ''),
            renderHTML: (attrs: { indent?: number }) => (attrs.indent ? { style: `margin-left: ${attrs.indent * INDENT_STEP}px` } : {}),
          },
        },
      },
    ];
  },

  addCommands() {
    const change =
      (delta: number) =>
      ({
        editor,
        tr,
        dispatch,
      }: {
        editor: import('@tiptap/core').Editor;
        tr: import('@tiptap/pm/state').Transaction;
        dispatch?: (tr: import('@tiptap/pm/state').Transaction) => void;
      }) => {
        if (editor.isActive('listItem')) {
          return delta > 0 ? editor.commands.sinkListItem('listItem') : editor.commands.liftListItem('listItem');
        }
        const { from, to } = tr.selection;
        let changed = false;
        tr.doc.nodesBetween(from, to, (node, pos) => {
          if (!TYPES.includes(node.type.name)) return true;
          const level = Math.max(0, Math.min(MAX_LEVEL, (Number(node.attrs.indent) || 0) + delta));
          if (level !== node.attrs.indent) {
            tr.setNodeMarkup(pos, undefined, { ...node.attrs, indent: level });
            changed = true;
          }
          return false;
        });
        if (changed && dispatch) dispatch(tr);
        return changed;
      };
    return {
      indent: () => change(1),
      outdent: () => change(-1),
    };
  },
});
