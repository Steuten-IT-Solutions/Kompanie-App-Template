<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\Storage\Backup;
use KompanieApp\Core\Storage\Sql\Migrator;
use KompanieApp\Core\Storage\Sql\Schema;
use KompanieApp\Core\Tests\Support\MemorySettings;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Tables of the website (migration 38, docs/website.md): columns, unique keys and backup. */
final class SiteSchemaTest extends TestCase
{
    private const TABLES = ['site_pages', 'site_page_translations', 'site_page_revisions', 'site_menu_items'];

    /** @return array<string, array{string}> */
    public static function drivers(): array
    {
        $out = ['sqlite' => ['sqlite']];
        if (getenv('KOMPANIE_TEST_MYSQL')) {
            $out['mysql'] = ['mysql'];
        }
        return $out;
    }

    #[DataProvider('drivers')]
    public function testTablesAndUniqueKeys(string $driver): void
    {
        $db = TestApp::connection($driver);
        try {
            (new Migrator($db, new MemorySettings()))->migrate();
            foreach (self::TABLES as $table) {
                $this->assertNotSame([], Schema::columns($db, $table), $table);
                $this->assertContains($table, Backup::CORE_TABLES, $table);
                $this->assertNotContains('user_id', Schema::columns($db, $table), "$table: no personal data by user_id");
            }
            $now = '2026-11-05 12:00:00';
            $page = $db->insert('site_pages', ['created_by' => 1, 'created_at' => $now, 'updated_by' => 1, 'updated_at' => $now]);
            $other = $db->insert('site_pages', ['parent_id' => $page, 'created_by' => 1, 'created_at' => $now, 'updated_by' => 1, 'updated_at' => $now]);
            $de = $db->insert('site_page_translations', ['page_id' => $page, 'lang' => 'de', 'path' => 'ueber-uns', 'updated_at' => $now]);
            $db->insert('site_page_translations', ['page_id' => $page, 'lang' => 'en', 'path' => 'about-us', 'updated_at' => $now]);
            $db->insert('site_page_translations', ['page_id' => $other, 'lang' => 'en', 'path' => 'ueber-uns', 'updated_at' => $now]);
            $rev = $db->insert('site_page_revisions', ['translation_id' => $de, 'title' => 'Über uns', 'blocks' => '[]', 'created_by' => 1, 'created_at' => $now]);
            $db->execute('UPDATE {site_page_translations} SET draft_revision_id = ?, published_revision_id = ? WHERE id = ?', [$rev, $rev, $de]);
            $this->assertSame('', $db->scalar('SELECT hero_image FROM {site_page_revisions} WHERE id = ?', [$rev]));
            $this->assertSame(0, (int) $db->scalar('SELECT allow_person_indexing FROM {site_pages} WHERE id = ?', [$page]), 'noindex for people by default');

            foreach ([
                'same path twice in one language' => ['page_id' => $other, 'lang' => 'de', 'path' => 'ueber-uns'],
                'two translations of a page in one language' => ['page_id' => $page, 'lang' => 'de', 'path' => 'anders'],
            ] as $case => $row) {
                try {
                    $db->insert('site_page_translations', $row + ['updated_at' => $now]);
                    $this->fail($case);
                } catch (\PDOException) {
                    $this->addToAssertionCount(1);
                }
            }
        } finally {
            TestApp::dropTables($db);
        }
    }
}
