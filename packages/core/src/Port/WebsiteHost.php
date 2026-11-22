<?php

declare(strict_types=1);

namespace KompanieApp\Core\Port;

/**
 * Where the website is served (Issue #273): the entry file in the folder above the program, which shows the website at
 * the address of the association (e.g. https://example.org/ while the App runs at https://example.org/app/). Only the
 * standalone version has one; WordPress keeps managing its own pages, so there the website area is not available.
 */
interface WebsiteHost
{
    /**
     * State of the entry file.
     *
     * - state: installed (our entry file is there), missing (none yet, it can be created), outdated (ours, but for
     *   another program folder; it can be written again), occupied (another start page
     *   is there, e.g. an older website; it is never overwritten), not_writable (PHP may not create files there),
     *   root (the program itself is at the top of the address, so there is no folder above it for the website)
     * - url: address of the website (null for root)
     * - file: name of the other start page for occupied, otherwise null
     * - folder: name of the program folder, e.g. app
     * - content: what the entry file contains (for uploading it by hand)
     *
     * @return array{state: string, url: string|null, file: string|null, folder: string, content: string}
     */
    public function entryStatus(): array;

    /**
     * Creates the entry file. Only when entryStatus() says missing or outdated; never replaces another file.
     *
     * @throws \KompanieApp\Core\Http\ApiError 409 when the state is neither missing nor outdated
     */
    public function installEntry(): void;

    /** Address of the website's stylesheet; $version changes when its content changes (caching). */
    public function stylesheetUrl(string $version): string;

    /** Address of an App icon, e.g. icon-192.png or favicon-32.png (built from the association's logo). */
    public function iconUrl(string $file): string;
}
