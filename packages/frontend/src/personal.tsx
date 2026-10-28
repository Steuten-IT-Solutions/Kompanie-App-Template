import { useState, type FormEvent } from 'react';
import { api, can, type PersonalInfo } from './api';
import { addressInput, EMPTY_ADDRESS, formatAddress } from './address';
import { normalizeEmail } from './email';
import { normalizePhone } from './phone';
import { AddressFields, EmailField, PhoneField } from './ui';

/**
 * Mitgliedsdetails → Persönliche Angaben: E-Mail-Adresse, Handynummer und Adresse der Person. Sehen mit view_personal
 * (sonst schickt der Server sie nicht), ändern mit manage_personal. Mit birthday steht oben der Geburtstag; Antippen
 * öffnet sein eigenes Fenster (Sichtbarkeit und Alter stellt nur die Person selbst ein). Eine neue eigene E-Mail-Adresse
 * nur mit dem aktuellen Passwort (wie unter Meins → Konto).
 */
export function PersonalPanel({
  id,
  personal,
  self,
  birthday,
  onChanged,
  onToast,
}: {
  id: number;
  personal: PersonalInfo;
  self: boolean;
  /** Geburtstag wie angezeigt (null ohne Eintrag) und was Antippen öffnet; fehlt, wenn er hier nicht steht */
  birthday?: { text: string | null; onOpen: () => void };
  onChanged: () => void;
  onToast: (msg: string) => void;
}) {
  const [editing, setEditing] = useState<PersonalInfo | null>(null);
  // Stand beim Öffnen: Gespeichert wird nur, was sich daran geändert hat. Sonst schriebe ein Formular, das noch vor dem
  // Neuladen der Seite geöffnet wurde, ältere Werte zurück.
  const [start, setStart] = useState<PersonalInfo | null>(null);
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const emailChanged = editing !== null && start !== null && normalizeEmail(editing.email).toLowerCase() !== start.email.toLowerCase();
  const ownEmailChanged = self && emailChanged;
  const lines = formatAddress(personal.address);

  async function save(e: FormEvent) {
    e.preventDefault();
    if (!editing) return;
    setFormError(null);
    if (ownEmailChanged && !password) return setFormError('Bitte zur Bestätigung das aktuelle Passwort eingeben.');
    const phone = normalizePhone(editing.phone);
    const address = addressInput(editing.address);
    const before = start ?? personal;
    setBusy(true);
    try {
      await api.updatePersonal(id, {
        ...(emailChanged ? { email: normalizeEmail(editing.email) } : {}),
        ...(phone !== normalizePhone(before.phone) ? { phone } : {}),
        ...(JSON.stringify(address) !== JSON.stringify(addressInput(before.address)) ? { address } : {}),
        ...(ownEmailChanged ? { current_password: password } : {}),
      });
      setEditing(null);
      setStart(null);
      setPassword('');
      onToast('Gespeichert');
      onChanged();
    } catch (err) {
      setFormError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  function cancel() {
    setEditing(null);
    setStart(null);
    setPassword('');
    setFormError(null);
  }

  return (
    <section className="member-personal">
      <h3 className="section-title">Persönliche Angaben</h3>
      {editing ? (
        <form className="form card account-card" onSubmit={save}>
          <EmailField value={editing.email} onChange={(email) => setEditing({ ...editing, email })} />
          <PhoneField value={editing.phone} onChange={(phone) => setEditing({ ...editing, phone })} />
          <AddressFields value={editing.address ?? EMPTY_ADDRESS} onChange={(address) => setEditing({ ...editing, address })} />
          {ownEmailChanged && (
            <label className="field">
              <span>Aktuelles Passwort (zur Bestätigung der neuen E-Mail-Adresse)</span>
              <input type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} />
            </label>
          )}
          {formError && (
            <p className="form-error" role="alert">
              {formError}
            </p>
          )}
          <div className="button-row">
            <button className="btn" type="submit" disabled={busy}>
              {busy ? 'Speichert …' : 'Speichern'}
            </button>
            <button className="btn btn-secondary" type="button" onClick={cancel} disabled={busy}>
              Abbrechen
            </button>
          </div>
        </form>
      ) : (
        <div className="card account-card">
          <dl className="contact-list">
            {birthday && (
              <>
                <dt>Geburtstag</dt>
                <dd>
                  <button type="button" className="link-button" onClick={birthday.onOpen}>
                    {birthday.text ?? 'nicht hinterlegt'}
                  </button>
                </dd>
              </>
            )}
            <dt>E-Mail-Adresse</dt>
            <dd>
              {personal.email ? <a href={`mailto:${personal.email}`}>{personal.email}</a> : <span className="muted">nicht hinterlegt</span>}
            </dd>
            <dt>Handynummer</dt>
            <dd>
              {personal.phone ? (
                <a href={`tel:${personal.phone.replace(/[^\d+]/g, '')}`}>{personal.phone}</a>
              ) : (
                <span className="muted">nicht hinterlegt</span>
              )}
            </dd>
            <dt>Adresse</dt>
            <dd className="address-lines">{lines.length > 0 ? lines.join('\n') : <span className="muted">nicht hinterlegt</span>}</dd>
          </dl>
          {can('manage_personal') && (
            <button
              className="btn btn-secondary btn-block"
              onClick={() => {
                setEditing(personal);
                setStart(personal);
              }}
            >
              Ändern
            </button>
          )}
        </div>
      )}
    </section>
  );
}
