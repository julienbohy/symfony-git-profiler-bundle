<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Tests\Git\Parser;

use JulienBohy\GitProfilerBundle\Git\Parser\PackedRefsParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PackedRefsParserTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function lines(): iterable
    {
        $sha1 = str_repeat('a', 40);
        $sha256 = str_repeat('b', 64);

        yield 'tag' => [$sha1 . " refs/tags/0.4.0\n", true];
        yield 'nested tag' => [$sha1 . ' refs/tags/v1/rc.1', true];
        yield 'sha-256 tag' => [$sha256 . ' refs/tags/0.4.0', true];
        yield 'branch' => [$sha1 . " refs/heads/main\n", false];
        yield 'remote branch named tags' => [$sha1 . ' refs/remotes/origin/refs/tags/x', false];
        yield 'peeled line' => ['^' . $sha1, false];
        yield 'header' => ['# pack-refs with: peeled fully-peeled sorted ', false];
        yield 'tags prefix only' => [$sha1 . ' refs/tag/x', false];
        yield 'short hash' => [str_repeat('a', 39) . ' refs/tags/x', false];
        yield 'in-between hash length' => [str_repeat('a', 50) . ' refs/tags/x', false];
        yield 'too long hash' => [str_repeat('a', 65) . ' refs/tags/x', false];
        yield 'not hex' => [str_repeat('g', 40) . ' refs/tags/x', false];
        yield 'garbage before' => ['x' . $sha1 . ' refs/tags/x', false];
        yield 'two spaces' => [$sha1 . '  refs/tags/x', false];
        yield 'empty' => ['', false];
    }

    #[DataProvider('lines')]
    public function testRecognisesTagLines(string $line, bool $expected): void
    {
        self::assertSame($expected, (new PackedRefsParser())->isTagLine($line));
    }
}
