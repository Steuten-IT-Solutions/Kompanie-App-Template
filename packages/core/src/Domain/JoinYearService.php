<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Storage\JoinYearRepository;

/**
 * Eintrittsjahr aktiver Mitglieder.
 *
 * - Jedes Mitglied trägt sein Jahr selbst ein und wählt, wem es auf seiner Seite in der Mitgliederliste erscheint
 *   (profile: none, members, public; Standard none).
 * - Wer Mitglieder anlegen darf (create_users), trägt das Jahr anderer ein oder entfernt es; die Anzeige bleibt dabei,
 *   wie die Person sie gewählt hat.
 * - Jahre ab 1800 (wie bei den Ehemaligen), nicht in der Zukunft (Zeitzone der Kompanie). Beim Eintragen als ehemalig
 *   schlägt die App das Jahr als Eintritt vor; die Ehemaligen haben ihren eigenen Eintrag (former_members.joined_on).
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

    /** @return array{year: int, profile: string}|null */
    public function of(int $userId): ?array
    {
        $j = $this->repository()->find($userId);
        return $j === null ? null : ['year' => $j['year'], 'profile' => $j['profile']];
    }

    /**
     * Eigenes Jahr mit Anzeige (PUT /me/join-year); ohne profile bleibt die Anzeige (neu: nicht zeigen).
     *
     * @param array<string, mixed> $in year, profile
     * @return array{year: int, profile: string}|null
     */
    public function saveOwn(User $user, array $in): ?array
    {
        $year = $this->validate($in['year'] ?? null);
        $profile = isset($in['profile']) ? ProfileVisibility::parse($in['profile']) : null;
        $this->repository()->save($user->id, $year, $profile, $user->id, $this->app->time()->nowUtc());
        return $this->of($user->id);
    }

    /**
     * Jahr eines anderen Mitglieds (PUT /members/{id}/join-year, create_users): nur das Jahr; die Anzeige bleibt, wie die
     * Person sie gewählt hat (ein mitgeschicktes profile wird nicht übernommen).
     *
     * @param array<string, mixed> $in year
     * @return array{year: int, profile: string}|null
     */
    public function saveFor(User $actor, int $userId, array $in): ?array
    {
        $this->app->users->find($userId) ?? throw ApiError::notFound('Mitglied nicht gefunden.');
        $this->repository()->save($userId, $this->validate($in['year'] ?? null), null, $actor->id, $this->app->time()->nowUtc());
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

    /** @return array<int, int> Benutzer-ID => Eintrittsjahr, soweit die Person es $viewer zeigt (eine Abfrage) */
    public function shownTo(?User $viewer): array
    {
        return $this->repository()->withProfile(ProfileVisibility::shownTo($viewer));
    }
}
