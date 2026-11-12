<?php

declare(strict_types=1);

namespace KompanieApp\Core\ChangeLog;

use KompanieApp\Core\Http\Request;

/**
 * Was eine Route ändert, für das Änderungsprotokoll (Subjects): wie die betroffenen Einträge vor und nach der Anfrage
 * geladen werden. Http\Api lädt sie vor dem Ausführen und danach und gibt beide Stände an Domain\ChangeLogService.
 *
 * - ids: betroffene IDs aus der Anfrage (vorher und nachher) und aus der Antwort (nachher, z. B. die ID eines neuen Eintrags).
 * - all: alle IDs (nur bei Routen, die Einträge anlegen, ohne die ID zu nennen, z. B. mehrere Mitglieder auf einmal): was
 *   nachher dazugekommen ist, gilt als angelegt.
 * - event: kein vergleichbarer Stand (z. B. Fehlerprotokoll geleert, Passwort neu erzeugt): ein Eintrag mit der Aktion,
 *   ohne Werte bzw. mit einem geheimen Feld.
 */
final class Subject
{
    /**
     * @param \Closure(string): ?Item $load
     * @param \Closure(Request, mixed): list<string> $ids $result ist vor dem Ausführen null
     * @param (\Closure(): list<string>)|null $all
     * @param 'create'|'update'|'delete'|null $event
     * @param string|null $secretField geheimes Feld, das sich mit jeder Anfrage ändert (Passwort): steht als „geändert“ im
     *        Eintrag, ohne Werte
     */
    public function __construct(
        public readonly \Closure $load,
        public readonly \Closure $ids,
        public readonly ?\Closure $all = null,
        public readonly ?string $event = null,
        public readonly ?string $secretField = null,
    ) {
    }

    /** IDs aus dem Platzhalter der Adresse ({id} bzw. {key}) und, nach dem Ausführen, eine id in der Antwort. */
    public static function fromPath(string $param = 'id'): \Closure
    {
        return static function (Request $r, mixed $result) use ($param): array {
            $ids = [];
            if (isset($r->params[$param]) && (is_string($r->params[$param]) || is_int($r->params[$param]))) {
                $ids[] = (string) $r->params[$param];
            }
            if (is_array($result) && isset($result['id']) && (is_int($result['id']) || is_string($result['id']))) {
                $ids[] = (string) $result['id'];
            }
            return array_values(array_unique($ids));
        };
    }

    /** IDs aus einer Liste im Inhalt der Anfrage (z. B. ids beim Löschen mehrerer Bilder). */
    public static function fromBody(string $key): \Closure
    {
        return static function (Request $r) use ($key): array {
            $v = $r->body[$key] ?? null;
            $list = is_array($v) ? $v : [$v];
            $ids = [];
            foreach ($list as $id) {
                if (is_int($id) || (is_string($id) && $id !== '')) {
                    $ids[] = (string) $id;
                }
            }
            return array_values(array_unique($ids));
        };
    }
}
