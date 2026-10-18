<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests\Support;

use KompanieApp\Core\Port\Scheduler;

final class NullScheduler implements Scheduler
{
    /** @var array<string, int> */
    public array $scheduled = [];

    public function schedule(string $task, int $intervalSeconds): void
    {
        $this->scheduled[$task] = $intervalSeconds;
    }

    public function unschedule(string $task): void
    {
        unset($this->scheduled[$task]);
    }
}
