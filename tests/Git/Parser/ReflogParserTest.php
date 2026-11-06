<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Tests\Git\Parser;

use JulienBohy\GitProfilerBundle\Git\Parser\ReflogParser;
use PHPUnit\Framework\TestCase;

final class ReflogParserTest extends TestCase
{
    public function testCountsOneEntryPerLine(): void
    {
        $line = str_repeat('0', 40) . ' ' . str_repeat('1', 40) . " Ada <a@example.com> 1700000000 +0000\tWIP on main: abc init\n";

        self::assertSame(0, (new ReflogParser())->countEntries(''));
        self::assertSame(1, (new ReflogParser())->countEntries($line));
        self::assertSame(3, (new ReflogParser())->countEntries($line . $line . $line));
    }

    public function testIgnoresEmptyLines(): void
    {
        self::assertSame(2, (new ReflogParser())->countEntries("a\n\nb\n\n"));
        self::assertSame(2, (new ReflogParser())->countEntries("a\nb"));
        self::assertSame(0, (new ReflogParser())->countEntries("\n\n"));
    }
}
