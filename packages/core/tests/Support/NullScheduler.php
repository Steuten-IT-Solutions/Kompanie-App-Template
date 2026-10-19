<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests\Support;

use KompanieApp\Core\Port\Scheduler;

final class NullScheduler implements Scheduler
{
    /** @var array<string, int> */
    public array $scheduled = [];

    /** Anzahl der Aufrufe von schedule/unschedule */
    public int $calls = 0;

    public function schedule(string $task, int $intervalSeconds): void
    {
        $this->calls++;
        $this->scheduled[$task] = $intervalSeconds;
    }

    public function unschedule(string $task): void
    {
        $this->calls++;
        unset($this->scheduled[$task]);
    }

    /** @var string[] */
    public array $soon = [];

    public function runSoon(string $task): void
    {
        if (isset($this->scheduled[$task])) {
            $this->soon[] = $task;
        }
    }

    public string $kind = 'real';

    public function kind(): string
    {
        return $this->kind;
    }
}
