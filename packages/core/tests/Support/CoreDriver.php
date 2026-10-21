<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests\Support;

use KompanieApp\Contract\ApiDriver;
use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Http\Request;

/** Führt das API-Szenario direkt gegen den Kern aus. */
final class CoreDriver implements ApiDriver
{
    private ?string $preview = null;

    public function __construct(private readonly App $app, private readonly FakeUsers $users)
    {
    }

    public function request(string $method, string $path, array $body = [], array $query = []): array
    {
        // Über JSON, damit Typen wie bei einer echten Anfrage ankommen.
        $body = json_decode((string) json_encode($body), true) ?? [];
        $res = $this->app->api()->handle(new Request($method, $path, $query, $body, [], '', $this->preview));
        $data = json_decode((string) json_encode($res->data), true);
        return [$res->status, $data, array_change_key_case($res->headers)];
    }

    public function actAs(?int $userId): void
    {
        $this->users->current = $userId;
    }

    public function preview(?string $value): void
    {
        $this->preview = $value;
    }

    public function createUser(string $login, string $level): int
    {
        return $this->users->add($login, $level);
    }

    public function deleteUser(int $id): void
    {
        unset($this->users->users[$id]);
    }

    public function setPassword(int $id, string $password): void
    {
        $this->users->setPassword($id, $password);
    }

    public function checkPassword(int $id, string $password): bool
    {
        return $this->users->verifyPassword($id, $password);
    }

    public function canAssignAdmin(): bool
    {
        return $this->users->canAssignAdmin();
    }
}
