import { execSync } from 'node:child_process';

// Vor jedem Lauf die App-Daten der Test-Instanz zurücksetzen, damit die Tests wiederholbar sind.
// KOMPANIE_WP_CLI: Befehl für WP-CLI der Test-Instanz, z. B.
//   "php .wp-test/wp-cli.phar --allow-root --path=.wp-test/wp" (relativ zum Repo).
// Ohne Angabe wird nichts zurückgesetzt (z. B. in der CI mit frischer Instanz).
// Eigenständige Version (KOMPANIE_VARIANT=standalone): Testinstallation frisch anlegen,
// mit KOMPANIE_DATA_DIR und KOMPANIE_BASE_URL wie beim Testserver (tests/standalone/e2e-router.php).
export default function globalSetup(): void {
  const root = new URL('../../..', import.meta.url).pathname;
  if (process.env.KOMPANIE_VARIANT === 'standalone') {
    const appUrl = (process.env.KOMPANIE_BASE_URL ?? 'http://localhost:8888').replace(/\/$/, '') + '/app/';
    execSync('php tests/standalone/e2e-setup.php', { cwd: root, stdio: 'inherit', env: { ...process.env, KOMPANIE_BASE_URL: appUrl } });
    return;
  }
  const cli = process.env.KOMPANIE_WP_CLI;
  if (!cli) return;
  execSync(`${cli} eval-file tests/wordpress/reset-e2e.php`, { cwd: root, stdio: 'inherit' });
}
