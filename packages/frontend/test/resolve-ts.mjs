// Für node --test: Importe ohne Endung (wie im Code für Vite) auf die .ts-Datei auflösen, z. B. './i18n' → './i18n.ts'.
import { register } from 'node:module';

register(
  'data:text/javascript,' +
    encodeURIComponent(`
export async function resolve(specifier, context, next) {
  if (/^\\.{1,2}\\//.test(specifier) && !/\\.[cm]?[jt]sx?$|\\.json$/.test(specifier)) {
    try {
      return await next(specifier + '.ts', context);
    } catch {}
  }
  return next(specifier, context);
}
`),
  import.meta.url,
);
