<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Tests\Git\Parser;

use JulienBohy\GitProfilerBundle\Git\Parser\LogParser;
use PHPUnit\Framework\TestCase;

final class LogParserTest extends TestCase
{
    private const S = "\x1f";
    private const A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const C = 'cccccccccccccccccccccccccccccccccccccccc';
    private const DATE = '2026-11-05T10:00:00+01:00';

    public function testFormatsMatchTheParsedFieldOrder(): void
    {
        self::assertSame('%H' . self::S . '%an' . self::S . '%aI' . self::S . '%s', LogParser::UNPUSHED_FORMAT);
        self::assertSame('%H' . self::S . '%P' . self::S . '%an' . self::S . '%aI' . self::S . '%s', LogParser::GRAPH_FORMAT);
    }

    public function testUnpushedCommitsKeepASubjectContainingTheSeparator(): void
    {
        $output = implode(self::S, [self::A, 'Ada', self::DATE, 'fix' . self::S . 'it']) . "\n";

        $commits = (new LogParser())->unpushedCommits($output);

        self::assertCount(1, $commits);
        self::assertSame('aaaaaaa', $commits[0]->shortHash);
        self::assertSame('Ada', $commits[0]->author);
        self::assertSame('fix' . self::S . 'it', $commits[0]->subject);
        self::assertSame(self::DATE, $commits[0]->date->format(\DateTimeInterface::ATOM));
    }

    public function testUnpushedCommitsSkipAMalformedLine(): void
    {
        $output = implode(self::S, [self::A, 'Ada', 'not-a-date', 'broken']) . "\n"
            . implode(self::S, [self::B, 'Bob', self::DATE, 'ok']) . "\n";

        $commits = (new LogParser())->unpushedCommits($output);

        self::assertCount(1, $commits);
        self::assertSame('ok', $commits[0]->subject);
    }

    public function testCommitHashesAreTheFirstField(): void
    {
        $output = implode(self::S, [self::A, 'x', self::DATE, 's']) . "\n" . implode(self::S, [self::B, 'x', self::DATE, 's']) . "\n";

        self::assertSame([self::A => true, self::B => true], (new LogParser())->commitHashes($output));
        self::assertSame([], (new LogParser())->commitHashes(''));
    }

    public function testGraphCommitsWithFlags(): void
    {
        $output = implode("\n", [
            implode(self::S, [self::A, self::B . ' ' . self::C, 'Ada', self::DATE, 'merge']),
            implode(self::S, [self::B, self::C, 'Bob', self::DATE, 'upstream tip']),
            implode(self::S, [self::C, '', 'Cy', self::DATE, 'root']),
        ]) . "\n";

        $commits = (new LogParser())->graphCommits($output, self::A, 'bbbbbbb', [self::A => true], true);

        self::assertCount(3, $commits);
        self::assertSame([self::B, self::C], $commits[0]->parentHashes);
        self::assertTrue($commits[0]->isHead);
        self::assertFalse($commits[0]->isPushed);
        self::assertTrue($commits[1]->isUpstream);
        self::assertTrue($commits[1]->isPushed);
        self::assertSame([], $commits[2]->parentHashes);
        self::assertSame('ccccccc', $commits[2]->shortHash);
    }

    public function testShortHashesAreTheFirstSevenCharacters(): void
    {
        $hash = '0123456789abcdef0123456789abcdef01234567';

        $unpushed = (new LogParser())->unpushedCommits(implode(self::S, [$hash, 'Ada', self::DATE, 's']) . "\n");
        $graph = (new LogParser())->graphCommits(implode(self::S, [$hash, '', 'Ada', self::DATE, 's']) . "\n", $hash, null, [], false);

        self::assertSame('0123456', $unpushed[0]->shortHash);
        self::assertSame('0123456', $graph[0]->shortHash);
    }

    public function testBlankOutputGivesNoHashes(): void
    {
        self::assertSame([], (new LogParser())->commitHashes("  \n"));
    }

    public function testGraphCommitsWithoutUpstreamAreNeverPushedNorUpstream(): void
    {
        $output = implode(self::S, [self::A, '', 'Ada', self::DATE, 'only']) . "\n";

        $commits = (new LogParser())->graphCommits($output, self::A, null, [], false);

        self::assertFalse($commits[0]->isPushed);
        self::assertFalse($commits[0]->isUpstream);
    }

    public function testGraphCommitsKeepASubjectContainingTheSeparatorAndSkipMalformedLines(): void
    {
        $output = implode("\n", [
            implode(self::S, [self::A, '', 'Ada', 'not-a-date', 'broken']),
            implode(self::S, [self::B, '', 'Bob', self::DATE, 'a' . self::S . 'b']),
        ]) . "\n";

        $commits = (new LogParser())->graphCommits($output, self::B, null, [], false);

        self::assertCount(1, $commits);
        self::assertSame('a' . self::S . 'b', $commits[0]->subject);
    }
}
