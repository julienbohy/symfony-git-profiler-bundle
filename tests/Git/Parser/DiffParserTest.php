<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Tests\Git\Parser;

use JulienBohy\GitProfilerBundle\Git\ChangedFile;
use JulienBohy\GitProfilerBundle\Git\FileStage;
use JulienBohy\GitProfilerBundle\Git\FileStatus;
use JulienBohy\GitProfilerBundle\Git\Parser\DiffParser;
use PHPUnit\Framework\TestCase;

final class DiffParserTest extends TestCase
{
    private const RAW = ':100644 100644 1111111111111111111111111111111111111111 2222222222222222222222222222222222222222 ';

    public function testAddedModifiedAndDeletedFilesWithCounts(): void
    {
        $files = $this->parse([
            self::RAW . 'A', 'added.txt',
            self::RAW . 'M', 'modified.txt',
            self::RAW . 'D', 'deleted.txt',
            "3\t0\tadded.txt", "1\t1\tmodified.txt", "0\t2\tdeleted.txt",
        ], FileStage::Staged);

        self::assertEquals([
            new ChangedFile('added.txt', FileStatus::Added, FileStage::Staged, null, 3, 0),
            new ChangedFile('modified.txt', FileStatus::Modified, FileStage::Staged, null, 1, 1),
            new ChangedFile('deleted.txt', FileStatus::Deleted, FileStage::Staged, null, 0, 2),
        ], $files);
    }

    public function testRenameCarriesTheOldPathAndItsCounts(): void
    {
        $files = $this->parse([self::RAW . 'R087', 'old.txt', 'new.txt', "2\t1\t", 'old.txt', 'new.txt'], FileStage::Committed);

        self::assertEquals([new ChangedFile('new.txt', FileStatus::Renamed, FileStage::Committed, 'old.txt', 2, 1)], $files);
    }

    public function testBinaryFileHasZeroCounts(): void
    {
        $files = $this->parse([self::RAW . 'M', 'image.png', "-\t-\timage.png"], FileStage::Unstaged);

        self::assertEquals([new ChangedFile('image.png', FileStatus::Modified, FileStage::Unstaged, null, 0, 0)], $files);
    }

    public function testPathsWithSpacesNewlinesAndDigitsOnly(): void
    {
        $files = $this->parse([
            self::RAW . 'M', 'with space.txt',
            self::RAW . 'M', "line\nbreak.txt",
            self::RAW . 'M', '2024',
            "1\t0\twith space.txt", "0\t1\tline\nbreak.txt", "5\t5\t2024",
        ], FileStage::Unstaged);

        self::assertSame(['with space.txt', "line\nbreak.txt", '2024'], array_map(static fn (ChangedFile $f) => $f->path, $files));
        self::assertSame([1, 0, 5], array_map(static fn (ChangedFile $f) => $f->additions, $files));
    }

    public function testTypeChangeIsReportedAsModified(): void
    {
        $files = $this->parse([self::RAW . 'T', 'link'], FileStage::Staged);

        self::assertSame(FileStatus::Modified, $files[0]->status);
    }

    public function testEmptyOutputGivesNoFiles(): void
    {
        self::assertSame([], (new DiffParser())->parse('', FileStage::Staged));
    }

    /**
     * @param list<string> $fields
     *
     * @return list<ChangedFile>
     */
    private function parse(array $fields, FileStage $stage): array
    {
        return (new DiffParser())->parse(implode("\0", $fields) . "\0", $stage);
    }
}
