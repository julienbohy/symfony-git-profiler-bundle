<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Tests\Git\Parser;

use JulienBohy\GitProfilerBundle\Git\Parser\StatusParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StatusParserTest extends TestCase
{
    private const OID = '0123456789abcdef0123456789abcdef01234567';

    public function testCleanBranchWithoutUpstream(): void
    {
        $status = $this->parse(['# branch.oid ' . self::OID, '# branch.head main']);

        self::assertSame(self::OID, $status->oid);
        self::assertSame('main', $status->branch);
        self::assertNull($status->upstream);
        self::assertFalse($status->hasUpstreamTracking);
        self::assertSame(0, $status->ahead);
        self::assertSame(0, $status->behind);
        self::assertFalse($status->hasStaged);
        self::assertFalse($status->hasUnstaged);
        self::assertSame([], $status->untracked);
    }

    public function testRepositoryWithoutCommitHasNoOid(): void
    {
        self::assertNull($this->parse(['# branch.oid (initial)', '# branch.head main'])->oid);
    }

    public function testDetachedHeadIsReportedAsHead(): void
    {
        self::assertSame('HEAD', $this->parse(['# branch.oid ' . self::OID, '# branch.head (detached)'])->branch);
    }

    public function testUpstreamWithAheadAndBehind(): void
    {
        $status = $this->parse([
            '# branch.oid ' . self::OID, '# branch.head main', '# branch.upstream origin/main', '# branch.ab +2 -3',
        ]);

        self::assertSame('origin/main', $status->upstream);
        self::assertTrue($status->hasUpstreamTracking);
        self::assertSame(2, $status->ahead);
        self::assertSame(3, $status->behind);
    }

    public function testAnEmptyRecordDoesNotStopTheParsing(): void
    {
        $status = $this->parse(['# branch.oid ' . self::OID, '', '? untracked.txt']);

        self::assertSame(['untracked.txt'], $status->untracked);
    }

    public function testAHeaderWithoutValueHasAnEmptyValue(): void
    {
        $status = $this->parse(['# branch.oid ' . self::OID, '# branch.upstream', '# unknown']);

        self::assertSame('', $status->upstream);
        self::assertSame(self::OID, $status->oid);
    }

    #[DataProvider('malformedAheadBehind')]
    public function testAMalformedAheadBehindIsIgnored(string $value): void
    {
        $status = $this->parse(['# branch.oid ' . self::OID, '# branch.ab ' . $value]);

        self::assertFalse($status->hasUpstreamTracking);
        self::assertSame(0, $status->ahead);
        self::assertSame(0, $status->behind);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedAheadBehind(): iterable
    {
        yield 'leading garbage' => ['x+4 -5'];
        yield 'trailing garbage' => ['+4 -5 x'];
    }

    public function testOnlyStagedEntriesAreNotUnstaged(): void
    {
        $status = $this->parse(['# branch.oid ' . self::OID, '1 M. N... 100644 100644 100644 aaa bbb a.txt']);

        self::assertTrue($status->hasStaged);
        self::assertFalse($status->hasUnstaged);
    }

    public function testOnlyUnstagedEntriesAreNotStaged(): void
    {
        $status = $this->parse(['# branch.oid ' . self::OID, '1 .M N... 100644 100644 100644 aaa aaa a.txt']);

        self::assertFalse($status->hasStaged);
        self::assertTrue($status->hasUnstaged);
    }

    public function testUpstreamWhoseRefIsGoneHasNoTracking(): void
    {
        $status = $this->parse(['# branch.oid ' . self::OID, '# branch.head main', '# branch.upstream origin/gone']);

        self::assertSame('origin/gone', $status->upstream);
        self::assertFalse($status->hasUpstreamTracking);
    }

    public function testStagedAndUnstagedEntries(): void
    {
        $status = $this->parse([
            '# branch.oid ' . self::OID, '# branch.head main',
            '1 M. N... 100644 100644 100644 aaa bbb staged.txt',
            '1 .M N... 100644 100644 100644 aaa aaa unstaged with space.txt',
        ]);

        self::assertTrue($status->hasStaged);
        self::assertTrue($status->hasUnstaged);
    }

    public function testRenameConsumesTheOriginalPathField(): void
    {
        $status = $this->parse([
            '# branch.oid ' . self::OID, '# branch.head main',
            '2 R. N... 100644 100644 100644 aaa aaa R100 new.txt', '? decoy.txt',
            '? untracked.txt',
        ]);

        self::assertTrue($status->hasStaged);
        self::assertFalse($status->hasUnstaged);
        self::assertSame(['untracked.txt'], $status->untracked);
    }

    public function testUnmergedEntryCountsAsStagedAndUnstaged(): void
    {
        $status = $this->parse([
            '# branch.oid ' . self::OID, '# branch.head main',
            'u UU N... 100644 100644 100644 100644 aaa bbb ccc conflict.txt',
        ]);

        self::assertTrue($status->hasStaged);
        self::assertTrue($status->hasUnstaged);
    }

    public function testUntrackedPathsKeepSpacesAndNewlinesAndIgnoredEntriesAreSkipped(): void
    {
        $status = $this->parse([
            '# branch.oid ' . self::OID, '# branch.head main',
            '? dir/with space.txt', "? line\nbreak.txt", '! ignored.log',
        ]);

        self::assertSame(['dir/with space.txt', "line\nbreak.txt"], $status->untracked);
        self::assertFalse($status->hasStaged);
        self::assertFalse($status->hasUnstaged);
    }

    /**
     * @param list<string> $records
     */
    private function parse(array $records): \JulienBohy\GitProfilerBundle\Git\Parser\StatusSnapshot
    {
        return (new StatusParser())->parse(implode("\0", $records) . "\0");
    }
}
