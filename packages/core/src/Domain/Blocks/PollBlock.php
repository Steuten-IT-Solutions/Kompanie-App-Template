<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain\Blocks;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Domain\Input;
use KompanieApp\Core\Domain\PollService;
use KompanieApp\Core\Http\ApiError;

/**
 * Umfrage einbinden: Titel und Stand einer vorhandenen Umfrage mit dem Weg zum Abstimmen. Nur für alle, die an Umfragen
 * teilnehmen (vote_polls) oder sie verwalten; für andere, ohne Bereich Umfragen und bei gelöschter Umfrage fällt der
 * Block weg (die Pflege sieht ihn mit poll null).
 */
final class PollBlock implements BlockType
{
    public const TYPE = 'poll';

    public function __construct(private readonly App $app)
    {
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function validate(array $in): array
    {
        $id = Input::integer($in['poll_id'] ?? null);
        if ($id === null || $id <= 0 || $this->app->polls()->repository()->find($id) === null) {
            throw ApiError::invalid('Bitte eine Umfrage auswählen.');
        }
        return ['poll_id' => $id];
    }

    public function render(array $data, ?User $viewer, bool $manager): ?array
    {
        $poll = $this->poll($data, $viewer);
        if ($poll === null && !$manager) {
            return null;
        }
        return ['poll_id' => (int) ($data['poll_id'] ?? 0), 'poll' => $poll];
    }

    /** „Umfrage: Titel (bis …)“ mit der Adresse in der App. */
    public function plain(array $data, ?User $viewer = null): string
    {
        $poll = $this->poll($data, $viewer);
        if ($poll === null) {
            return '';
        }
        return 'Umfrage: ' . $poll['title'] . ($poll['status'] === 'ended' ? ' (beendet)' : '') . ' – ' . $this->app->env->appUrl() . '#/umfragen/' . $poll['id'];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{id: int, title: string, status: string, ends_at: string|null, has_voted: bool}|null
     */
    private function poll(array $data, ?User $viewer): ?array
    {
        if (!$this->app->features()->enabled('polls') || !(PollService::isParticipant($viewer) || PollService::canManage($viewer))) {
            return null;
        }
        /** @var User $viewer */
        try {
            $p = $this->app->polls()->get($viewer, (int) ($data['poll_id'] ?? 0));
        } catch (ApiError) {
            return null;
        }
        return ['id' => (int) $p['id'], 'title' => (string) $p['title'], 'status' => (string) $p['status'], 'ends_at' => $p['ends_at'], 'has_voted' => (bool) $p['has_voted']];
    }
}
