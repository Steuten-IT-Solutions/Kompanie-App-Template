#!/usr/bin/env bash
# Findet Kompanie-Repos, deren aktueller Stand (Commit auf main und Programmcode des Kerns) noch nicht
# veröffentlicht ist (aus .github/workflows/kompanien-abgleichen.yml). Ausgabe: repos=["Org/Name", …]
#
# Umgebung: GH_TOKEN (nur Lesen), optional ONLY (nur dieses Repo), ORG (Standard Steuten-IT-Solutions).
# Die Update-Liste wird hier nur gelesen, nicht geprüft: Sie entscheidet nur, ob ein Lauf nötig ist;
# der Veröffentlichungs-Job prüft selbst noch einmal (mit Signatur) und tut nichts doppelt.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ORG="${ORG:-Steuten-IT-Solutions}"
CODE="$(php "$ROOT/scripts/package.php" --code-hash)"

if [ -n "${ONLY:-}" ]; then
  REPOS="$ONLY"
else
  REPOS="$(gh search repos --owner "$ORG" --topic kompanie-app --visibility public --limit 500 --json fullName --jq '.[].fullName')"
fi

PENDING=()
while IFS= read -r repo; do
  [ -z "$repo" ] && continue
  [[ "$repo" =~ ^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$ ]] || { echo "Übersprungen (Name): $repo" >&2; continue; }
  sha="$(git ls-remote "https://github.com/$repo.git" refs/heads/main | cut -f1)"
  if [ -z "$sha" ]; then
    echo "Übersprungen (kein main): $repo" >&2
    continue
  fi
  list="$(curl -fsSL --max-time 20 "https://raw.githubusercontent.com/$repo/gh-pages/updates.json" 2>/dev/null || true)"
  if php -r '
    $env = json_decode($argv[1], true);
    $list = is_array($env) ? json_decode((string) base64_decode((string) ($env["payload"] ?? ""), true), true) : null;
    foreach ((array) ($list["versions"] ?? []) as $e) {
        if (($e["kompanieCommit"] ?? "") === $argv[2] && ($e["coreCode"] ?? "") === $argv[3]) {
            exit(0);
        }
    }
    exit(1);
  ' "$list" "$sha" "$CODE"; then
    echo "Aktuell: $repo" >&2
  else
    echo "Neu zu veröffentlichen: $repo" >&2
    PENDING+=("$repo")
  fi
done <<< "$REPOS"

printf 'repos=%s\n' "$(php -r 'echo json_encode(array_slice($argv, 1), JSON_UNESCAPED_SLASHES);' -- "${PENDING[@]}")"
