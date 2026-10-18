import { execSync } from 'node:child_process';

// Vor jedem Lauf die App-Daten der Test-Instanz zurücksetzen, damit die Tests wiederholbar sind.
// KOMPANIE_WP_CLI: Befehl für WP-CLI der Test-Instanz, z. B.
//   "php .wp-test/wp-cli.phar --allow-root --path=.wp-test/wp" (relativ zum Repo).
// Ohne Angabe wird nichts zurückgesetzt (z. B. in der CI mit frischer Instanz).
export default function globalSetup(): void {
  const cli = process.env.KOMPANIE_WP_CLI;
  if (!cli) return;
  execSync(`${cli} eval-file tests/wordpress/reset-e2e.php`, { cwd: new URL('../../..', import.meta.url).pathname, stdio: 'inherit' });
}
