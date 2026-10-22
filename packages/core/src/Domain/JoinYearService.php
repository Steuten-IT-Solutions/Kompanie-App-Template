<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Storage\JoinYearRepository;

/**
 * Eintritt aktiver Mitglieder: das Jahr oder das ganze Datum.
 *
 * - Jedes Mitglied trägt seinen Eintritt selbst ein und wählt, wem er auf seiner Seite in der Mitgliederliste erscheint
 *   (profile: none, members, public; Standard members). Dort steht der Eintritt, wie er eingetragen ist (das ganze
 *   Datum oder nur das Jahr), wie bei den Ehemaligen.
 * - Wer Mitglieder anlegen darf (create_users), trägt den Eintritt anderer ein oder entfernt ihn; die Anzeige bleibt
 *   dabei, wie die Person sie gewählt hat.
 * - Eingabe date als TT.MM.JJJJ, JJJJ-MM-TT oder JJJJ (wie bei den Ehemaligen, FormerMemberService::date); ältere Apps
 *   schicken nur year. Ab 1800, nicht in der Zukunft (Zeitzone der Kompanie). Beim Eintragen als ehemalig schlägt die
 *   App den Eintritt vor; die Ehemaligen haben ihren eigenen Eintrag (former_members.joined_on).
 */
final class JoinYearService
{
    public const MIN_YEAR = 1800;

    private ?JoinYearRepository $repo = null;

    public function __construct(private readonly App $app)
    {
    }

    public function repository(): JoinYearRepository
    {
        return $this->repo ??= new JoinYearRepository($this->app->db);
    }

    /** Jahr prüfen: ganze Zahl ab 1800, nicht in der Zukunft. */
    public function validate(mixed $year): int
    {
        $y = Input::integer($year);
        if ($y === null) {
            throw ApiError::invalid('Bitte das Eintrittsjahr vierstellig angeben.');
        }
        if ($y < self::MIN_YEAR) {
            throw ApiError::invalid(sprintf('Das Eintrittsjahr muss ab %d liegen.', self::MIN_YEAR));
        }
        if ($y > (int) $this->app->time()->today()->format('Y')) {
            throw ApiError::invalid('Das Eintrittsjahr darf nicht in der Zukunft liegen.');
        }
        return $y;
    }

    /**
     * Eintritt aus der Eingabe: date (TT.MM.JJJJ, JJJJ-MM-TT oder JJJJ) oder, wenn date fehlt, year.
     *
     * @param array<string, mixed> $in
     * @return array{int, string} Jahr und ganzes Datum (JJJJ-MM-TT, leer mit nur dem Jahr)
     */
    public function parse(array $in): array
    {
        if (!array_key_exists('date', $in)) {
            return [$this->validate($in['year'] ?? null), ''];
        }
        $date = FormerMemberService::date($in['date'], 'Eintritt', $this->app->time()->today());
        if ($date === '') {
            throw ApiError::invalid('Bitte den Eintritt als TT.MM.JJJJ oder nur das Jahr angeben.');
        }
        return [(int) substr($date, 0, 4), strlen($date) === 10 ? $date : ''];
    }

    /** @return array{year: int, date: string, profile: string}|null date: JJJJ-MM-TT oder JJJJ */
    public function of(int $userId): ?array
    {
        $j = $this->repository()->find($userId);
        return $j === null ? null : ['year' => $j['year'], 'date' => $j['joined_on'] !== '' ? $j['joined_on'] : (string) $j['year'], 'profile' => $j['profile']];
    }

    /**
     * Eigener Eintritt mit Anzeige (PUT /me/join-year); ohne profile bleibt die Anzeige (neu: nicht zeigen).
     *
     * @param array<string, mixed> $in date (oder year), profile
     * @return array{year: int, date: string, profile: string}|null
     */
    public function saveOwn(User $user, array $in): ?array
    {
        [$year, $date] = $this->parse($in);
        $profile = isset($in['profile']) ? ProfileVisibility::parse($in['profile']) : null;
        $this->repository()->save($user->id, $year, $date, $profile, $user->id, $this->app->time()->nowUtc());
        return $this->of($user->id);
    }

    /**
     * Eintritt eines anderen Mitglieds (PUT /members/{id}/join-year, create_users): nur der Eintritt; die Anzeige bleibt,
     * wie die Person sie gewählt hat (ein mitgeschicktes profile wird nicht übernommen).
     *
     * @param array<string, mixed> $in date (oder year)
     * @return array{year: int, date: string, profile: string}|null
     */
    public function saveFor(User $actor, int $userId, array $in): ?array
    {
        $this->app->users->find($userId) ?? throw ApiError::notFound('Mitglied nicht gefunden.');
        [$year, $date] = $this->parse($in);
        $this->repository()->save($userId, $year, $date, null, $actor->id, $this->app->time()->nowUtc());
        return $this->of($userId);
    }

    public function remove(int $userId): void
    {
        $this->repository()->delete($userId);
    }

    public function removeFor(int $userId): void
    {
        $this->app->users->find($userId) ?? throw ApiError::notFound('Mitglied nicht gefunden.');
        $this->remove($userId);
    }

    /** @return array<int, array{year: int, date: string}> Benutzer-ID => Eintritt, soweit die Person ihn $viewer zeigt (eine Abfrage) */
    public function shownTo(?User $viewer): array
    {
        return $this->repository()->withProfile(ProfileVisibility::shownTo($viewer));
    }
}
