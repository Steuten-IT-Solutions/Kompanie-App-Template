import lizenzen from 'virtual:kompanie-lizenzen';
import { config } from '../api';
import { goBack } from '../router';

/**
 * Lizenzen (Issue #173), unter Hilfe für alle: Lizenz der App, wo es den Quellcode gibt, und die Drittanbieter-Bausteine
 * mit ihren Lizenztexten. Die Liste entsteht beim Bauen aus dem, was tatsächlich in der App steckt
 * (build/licenses.ts); dieselben Angaben liegen als THIRD-PARTY-NOTICES.txt in den Paketen. Eigenes Teilstück der App,
 * weil die Lizenztexte groß sind.
 */
export default function Lizenzen() {
  const { projekt, pakete } = lizenzen;
  const version = config?.version;
  return (
    <article className="hilfe-seite lizenzen">
      <button className="btn btn-link back" onClick={() => goBack('hilfe')}>
        ‹ Alle Hilfeseiten
      </button>
      <h2 className="news-headline">Lizenzen</h2>
      <div className="hilfe-text">
        <p>
          Die {projekt.name} ist freie Software unter der GNU General Public License, Version 2 oder (nach eurer Wahl) jeder späteren
          Version ({projekt.license}). Ihr dürft sie nach diesen Bedingungen nutzen, weitergeben und verändern; sie kommt ohne jede
          Gewährleistung.
        </p>
        <h3>Quellcode</h3>
        <p>
          Die Programmdateien der App sind für schnelles Laden verkleinert. Den vollständigen Quellcode
          {version ? ` (Version ${version.replace(/^(\d+\.\d+\.\d+).*/, '$1')})` : ''} gibt es unter{' '}
          <a href={projekt.repository} target="_blank" rel="noopener noreferrer">
            {projekt.repository.replace(/^https:\/\//, '')}
          </a>
          .
        </p>
        <details>
          <summary>Lizenztext der App</summary>
          <pre className="lizenz-text">{projekt.text}</pre>
        </details>
        <h3>Enthaltene Bausteine anderer Anbieter</h3>
        {pakete.length === 0 ? (
          <p className="muted">Die Liste entsteht beim Bauen der App.</p>
        ) : (
          <ul className="lizenz-liste">
            {pakete.map((p) => (
              <li key={p.name}>
                <details>
                  <summary>
                    <strong>{p.name}</strong> {p.version} <span className="muted">· {p.lizenz}</span>
                  </summary>
                  {p.homepage && (
                    <p className="small">
                      <a href={p.homepage} target="_blank" rel="noopener noreferrer">
                        {p.homepage.replace(/^https?:\/\//, '')}
                      </a>
                    </p>
                  )}
                  {p.copyright.length > 0 && <p className="small">{p.copyright.join(' · ')}</p>}
                  <pre className="lizenz-text">{p.text}</pre>
                </details>
              </li>
            ))}
          </ul>
        )}
      </div>
    </article>
  );
}
