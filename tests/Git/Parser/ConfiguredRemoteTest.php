<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Tests\Git\Parser;

use JulienBohy\GitProfilerBundle\Git\Parser\ConfiguredRemote;
use PHPUnit\Framework\TestCase;

final class ConfiguredRemoteTest extends TestCase
{
    public function testExtractsTheBranchOfATrackingRef(): void
    {
        $remote = new ConfiguredRemote('origin', 'x');

        self::assertSame('main', $remote->branchOf('origin/main'));
        self::assertSame('feature/x', $remote->branchOf('origin/feature/x'));
    }

    public function testRemoteNamesMayContainSlashes(): void
    {
        self::assertSame('main', (new ConfiguredRemote('my/fork', 'x'))->branchOf('my/fork/main'));
    }

    public function testRefsOfAnotherRemoteGiveNoBranch(): void
    {
        $remote = new ConfiguredRemote('origin', 'x');

        self::assertNull($remote->branchOf('upstream/main'));
        self::assertNull($remote->branchOf('origin2/main'));
        self::assertNull($remote->branchOf('xorigin/main'));
        self::assertNull($remote->branchOf('origin'));
        self::assertNull($remote->branchOf('origin/'));
        self::assertNull($remote->branchOf('main'));
    }
}
