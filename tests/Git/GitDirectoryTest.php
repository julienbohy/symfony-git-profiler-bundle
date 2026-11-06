<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Tests\Git;

use JulienBohy\GitProfilerBundle\Git\Command\GitCommandRunnerInterface;
use JulienBohy\GitProfilerBundle\Git\GitDirectory;
use JulienBohy\GitProfilerBundle\Git\StashCounter;
use JulienBohy\GitProfilerBundle\Git\TagPresence;
use JulienBohy\GitProfilerBundle\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

final class GitDirectoryTest extends TestCase
{
    use TemporaryDirectory;

    private string $root;

    protected function setUp(): void
    {
        $this->root = $this->createTemporaryDirectory('gpb_gd_');
        mkdir($this->root . '/.git/worktrees/wt', recursive: true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testTheCommonDirectoryIsTheGitDirectoryOutsideALinkedWorktree(): void
    {
        $directory = $this->directory($this->root . '/.git');

        self::assertSame($this->root . '/.git', $directory->path());
        self::assertSame($this->root . '/.git', $directory->commonPath());
    }

    public function testTheCommonDirectoryOfALinkedWorktreeIsRelativeToItsGitDirectory(): void
    {
        $worktreeGitDir = $this->root . '/.git/worktrees/wt';
        file_put_contents($worktreeGitDir . '/commondir', "../..\n");

        $directory = $this->directory($worktreeGitDir);

        self::assertSame($worktreeGitDir, $directory->path());
        self::assertSame($worktreeGitDir . '/../..', $directory->commonPath());
        self::assertSame(realpath($this->root . '/.git'), realpath($directory->commonPath()));
    }

    public function testTheCommonDirectoryMayBeAbsolute(): void
    {
        $worktreeGitDir = $this->root . '/.git/worktrees/wt';
        file_put_contents($worktreeGitDir . '/commondir', $this->root . '/.git');

        self::assertSame($this->root . '/.git', $this->directory($worktreeGitDir)->commonPath());
    }

    public function testAnEmptyCommondirFileMeansTheGitDirectoryItself(): void
    {
        $worktreeGitDir = $this->root . '/.git/worktrees/wt';
        file_put_contents($worktreeGitDir . '/commondir', "\n");

        self::assertSame($worktreeGitDir, $this->directory($worktreeGitDir)->commonPath());
    }

    public function testAMissingCommonDirectoryThrows(): void
    {
        $worktreeGitDir = $this->root . '/.git/worktrees/wt';
        file_put_contents($worktreeGitDir . '/commondir', '../missing');

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Common git directory');

        $this->directory($worktreeGitDir)->commonPath();
    }

    public function testNothingIsLookedUpTwice(): void
    {
        $runner = new class($this->root . '/.git') implements GitCommandRunnerInterface {
            public int $calls = 0;

            public function __construct(private readonly string $gitDir)
            {
            }

            public function run(array $args): string
            {
                ++$this->calls;

                return $this->gitDir . "\n";
            }
        };
        $directory = new GitDirectory($runner, $this->root);

        $directory->path();
        $directory->commonPath();
        $directory->commonPath();

        self::assertSame(1, $runner->calls);
    }

    public function testStashesAreCountedFromTheReflog(): void
    {
        $counter = new StashCounter($this->directory($this->root . '/.git'));
        self::assertSame(0, $counter->count());

        mkdir($this->root . '/.git/logs/refs', recursive: true);
        file_put_contents($this->root . '/.git/logs/refs/stash', "a\nb\n");
        self::assertSame(2, $counter->count());
    }

    public function testAStashReflogThatIsNotAFileCountsNothing(): void
    {
        mkdir($this->root . '/.git/logs/refs/stash', recursive: true);

        self::assertSame(0, (new StashCounter($this->directory($this->root . '/.git')))->count());
    }

    public function testStashesAreReadFromTheCommonDirectoryOfAWorktree(): void
    {
        $worktreeGitDir = $this->root . '/.git/worktrees/wt';
        file_put_contents($worktreeGitDir . '/commondir', '../..');
        mkdir($this->root . '/.git/logs/refs', recursive: true);
        file_put_contents($this->root . '/.git/logs/refs/stash', "a\nb\nc\n");

        self::assertSame(3, (new StashCounter($this->directory($worktreeGitDir)))->count());
    }

    public function testTagPresenceIsFailSafe(): void
    {
        // Unlocatable git directory: unknown, so git is left to decide.
        self::assertTrue((new TagPresence($this->directory($this->root . '/missing')))->mightHaveTags());
        // Located, nothing under refs/tags nor packed-refs: certain.
        self::assertFalse((new TagPresence($this->directory($this->root . '/.git')))->mightHaveTags());

        // Empty directories left by deleted tags do not count.
        mkdir($this->root . '/.git/refs/tags/v1/sub', recursive: true);
        self::assertFalse((new TagPresence($this->directory($this->root . '/.git')))->mightHaveTags());

        file_put_contents($this->root . '/.git/refs/tags/v1/sub/x', "sha\n");
        self::assertTrue((new TagPresence($this->directory($this->root . '/.git')))->mightHaveTags());
    }

    public function testTagPresenceReadsPackedRefsAndStopsAtTheFirstTag(): void
    {
        $sha = str_repeat('a', 40);
        file_put_contents($this->root . '/.git/packed-refs', "# pack-refs with: peeled\n" . $sha . " refs/heads/main\n");
        self::assertFalse((new TagPresence($this->directory($this->root . '/.git')))->mightHaveTags());

        file_put_contents($this->root . '/.git/packed-refs', "# pack-refs with: peeled\n" . $sha . " refs/heads/main\n" . $sha . " refs/tags/1.0\n^" . $sha . "\n");
        self::assertTrue((new TagPresence($this->directory($this->root . '/.git')))->mightHaveTags());
    }

    private function directory(string $gitDirectory): GitDirectory
    {
        return new GitDirectory(new class($gitDirectory) implements GitCommandRunnerInterface {
            public function __construct(private readonly string $gitDir)
            {
            }

            public function run(array $args): string
            {
                return $this->gitDir . "\n";
            }
        }, $this->root);
    }
}
