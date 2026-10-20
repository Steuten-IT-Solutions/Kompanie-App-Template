import { config } from './api';
import { pushSupport } from './push';
import { navigate } from './router';

/** Gäste ohne Konto: Knopf zu den Benachrichtigungen (Konten stellen sie unter „Mein Konto“ ein). */
export default function GuestPushButton({ label }: { label: string }) {
  if (config?.user || pushSupport() === 'unsupported') return null;
  return (
    <button className="btn btn-secondary btn-block push-guest" onClick={() => navigate('benachrichtigungen')}>
      {label}
    </button>
  );
}
