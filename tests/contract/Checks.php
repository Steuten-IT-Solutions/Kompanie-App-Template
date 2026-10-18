<?php

declare(strict_types=1);

namespace KompanieApp\Contract;

/** Sammelt Prüfergebnisse; gibt sie wahlweise sofort aus. */
final class Checks
{
    public int $passed = 0;
    /** @var string[] */
    public array $failed = [];

    public function __construct(private readonly bool $echo = false)
    {
    }

    public function section(string $title): void
    {
        if ($this->echo) {
            echo $title, "\n";
        }
    }

    public function ok(bool $condition, string $label): void
    {
        if ($condition) {
            $this->passed++;
        } else {
            $this->failed[] = $label;
        }
        if ($this->echo) {
            echo $condition ? '  ok   ' : '  FAIL ', $label, "\n";
        }
    }
}
