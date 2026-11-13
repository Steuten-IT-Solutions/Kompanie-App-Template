<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests\Support;

use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Port\WebsiteHost;

/** Entry file of the website for tests: state as set, installEntry() moves missing/outdated to installed. */
final class FakeWebsiteHost implements WebsiteHost
{
    public string $state = 'missing';
    public ?string $file = null;

    public function entryStatus(): array
    {
        return ['state' => $this->state, 'url' => $this->state === 'root' ? null : 'https://example.org/', 'file' => $this->file, 'folder' => 'app', 'content' => "<?php\n// test\n"];
    }

    public function installEntry(): void
    {
        if (!in_array($this->state, ['missing', 'outdated'], true)) {
            throw new ApiError(409, 'conflict', 'Nicht möglich.');
        }
        $this->state = 'installed';
    }

    public function stylesheetUrl(string $version): string
    {
        return 'https://example.org/app/index.php?r=/webseite.css&v=' . $version;
    }

    public function iconUrl(string $file): string
    {
        return 'https://example.org/app/app/icons/' . $file;
    }
}
