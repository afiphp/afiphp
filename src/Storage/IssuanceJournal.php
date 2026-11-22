<?php

declare(strict_types=1);

namespace Afiphp\Storage;

interface IssuanceJournal
{
    /** @return array<string, array> */
    public function all(): array;

    /** Must commit durably BEFORE returning, even if the surrounding callback later fails. */
    public function save(string $key, array $record): void;
}
