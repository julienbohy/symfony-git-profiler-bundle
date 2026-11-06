<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Tests\Git;

use JulienBohy\GitProfilerBundle\Exception\GitCommandFailedException;
use JulienBohy\GitProfilerBundle\Git\ChangedFile;
use JulienBohy\GitProfilerBundle\Git\Command\GitCommandRunnerInterface;
use JulienBohy\GitProfilerBundle\Git\Command\ProcessGitCommandRunner;
use JulienBohy\GitProfilerBundle\Git\Command\TraceableGitCommandRunner;
use JulienBohy\GitProfilerBundle\Git\FileStage;
use JulienBohy\GitProfilerBundle\Git\FileStatus;
use JulienBohy\GitProfilerBundle\Git\GitDirectory;
use JulienBohy\GitProfilerBundle\Git\GitRepository;
use JulienBohy\GitProfilerBundle\Git\OperationDetector;
use JulienBohy\GitProfilerBundle\Git\StashCounter;
use JulienBohy\GitProfilerBundle\Git\TagPresence;
use JulienBohy\GitProfilerBundle\Tests\Support\GitRepositoryInitializer;
use JulienBohy\GitProfilerBundle\Tests\Support\RecordingLogger;
use JulienBohy\GitProfilerBundle\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class GitRepositoryTest extends TestCase
{
    use GitRepositoryInitializer;
    use TemporaryDirectory;

    private string $dir;
    private string $remoteDir;
    private TraceableGitCommandRunner $tracer;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/gpb_' . bin2hex(random_bytes(6));
        $this->remoteDir = sys_get_temp_dir() . '/gpb_remote_' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        mkdir($this->remoteDir);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->dir);
        $this->removeDirectory($this->remoteDir);
    }

    public function testReturnsNullWhenNotAGitRepository(): void
    {
        self::assertNull($this->repository($this->dir)->read());
    }

    public function testReturnsNullOnRepositoryWithoutCommits(): void
    {
        $this->initGitRepository($this->dir);

        self::assertNull($this->repository($this->dir)->read());
    }

    public function testReadsBranchAndShortCommitOnCleanRepository(): void
    {
        $this->initRepositoryWithCommit();

        $info = $this->repository($this->dir)->read();

        self::assertNotNull($info);
        self::assertSame('main', $info->branch);
        self::assertMatchesRegularExpression('/^[0-9a-f]{7,}$/', $info->shortCommit);
        self::assertFalse($info->isDirty);
        self::assertSame([], $info->workingFiles);
    }

    public function testReadsALinkedWorktree(): void
    {
        // In a linked worktree, `.git` is a file (`gitdir: …`) and not a directory.
        $this->initRepositoryWithCommit();
        $worktree = $this->remoteDir . '/worktree';
        $this->git('worktree', 'add', '-b', 'feature', $worktree);
        file_put_contents($worktree . '/untracked.txt', "loose\n");

        $info = $this->repository($worktree)->read();

        self::assertNotNull($info);
        self::assertSame('feature', $info->branch);
        self::assertMatchesRegularExpression('/^[0-9a-f]{7,}$/', $info->shortCommit);
        self::assertTrue($info->isDirty);
        self::assertSame(FileStatus::Untracked, $this->indexByStagePath($info->workingFiles)['untracked']['untracked.txt'] ?? null);
    }

    public function testReadsASubmodule(): void
    {
        // A submodule's `.git` is a file whose `gitdir:` path is relative.
        $this->initGitRepository($this->remoteDir);
        foreach ([['config', 'user.email', 'test@example.com'], ['config', 'user.name', 'Test'], ['commit', '--allow-empty', '-m', 'sub']] as $args) {
            (new Process(['git', ...$args], $this->remoteDir))->mustRun();
        }
        $this->initRepositoryWithCommit();
        $this->git('-c', 'protocol.file.allow=always', 'submodule', 'add', $this->remoteDir, 'sub');

        $info = $this->repository($this->dir . '/sub')->read();

        self::assertNotNull($info);
        self::assertSame('main', $info->branch);
        self::assertMatchesRegularExpression('/^[0-9a-f]{7,}$/', $info->shortCommit);
    }

    public function testReturnsNullWhenTheGitFilePointsNowhere(): void
    {
        file_put_contents($this->dir . '/.git', "gitdir: /does/not/exist\n");

        self::assertNull($this->repository($this->dir)->read());
    }

    public function testDetectsDirtyWorkingTree(): void
    {
        $this->initRepositoryWithCommit();
        file_put_contents($this->dir . '/file.txt', "changed\n");

        $info = $this->repository($this->dir)->read();

        self::assertNotNull($info);
        self::assertTrue($info->isDirty);
    }

    public function testListsWorkingTreeFilesWithStatus(): void
    {
        $this->initRepositoryWithCommit();
        // Modified but not staged.
        file_put_contents($this->dir . '/file.txt', "changed\n");
        // New staged file.
        file_put_contents($this->dir . '/added.txt', "new\n");
        $this->git('add', 'added.txt');
        // Untracked file.
        file_put_contents($this->dir . '/untracked.txt', "loose\n");

        $info = $this->repository($this->dir)->read();

        self::assertNotNull($info);
        self::assertTrue($info->isDirty);

        $byStage = $this->indexByStagePath($info->workingFiles);

        self::assertSame(FileStatus::Added, $byStage['staged']['added.txt'] ?? null);
        self::assertSame(FileStatus::Modified, $byStage['unstaged']['file.txt'] ?? null);
        self::assertSame(FileStatus::Untracked, $byStage['untracked']['untracked.txt'] ?? null);
    }

    public function testKeepsPartiallyStagedFileInBothStages(): void
    {
        $this->initRepositoryWithCommit();
        file_put_contents($this->dir . '/file.txt', "v2\n");
        $this->git('add', 'file.txt');
        file_put_contents($this->dir . '/file.txt', "v3\n");

        $info = $this->repository($this->dir)->read();

        self::assertNotNull($info);
        $byStage = $this->indexByStagePath($info->workingFiles);

        self::assertSame(FileStatus::Modified, $byStage['staged']['file.txt'] ?? null);
        self::assertSame(FileStatus::Modified, $byStage['unstaged']['file.txt'] ?? null);
    }

    public function testNoUpstreamConfigured(): void
    {
        $this->initRepositoryWithCommit();

        $info = $this->repository($this->dir)->read();

        self::assertNotNull($info);
        self::assertFalse($info->hasUpstream);
        self::assertSame([], $info->unpushedCommits);
        self::assertSame([], $info->unpushedFiles);
    }

    public function testUpstreamWithAheadCommits(): void
    {
        $this->initRepositoryWithCommit();
        $this->configureUpstream();

        // Two local commits ahead of the upstream.
        file_put_contents($this->dir . '/feature.txt', "feature\n");
        $this->git('add', 'feature.txt');
        $this->git('commit', '-m', 'feature A');
        file_put_contents($this->dir . '/file.txt', "changed\n");
        $this->git('commit', '-am', 'feature B');

        $info = $this->repository($this->dir)->read();

        self::assertNotNull($info);
        self::assertTrue($info->hasUpstream);
        self::assertCount(2, $info->unpushedCommits);

        $subjects = array_map(static fn ($c) => $c->subject, $info->unpushedCommits);
        self::assertContains('feature A', $subjects);
        self::assertContains('feature B', $subjects);

        foreach ($info->unpushedCommits as $commit) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{7}$/', $commit->shortHash);
            self::assertInstanceOf(\DateTimeImmutable::class, $commit->date);
            self::assertNotSame('', $commit->author);
        }

        $paths = array_map(static fn (ChangedFile $f) => $f->path, $info->unpushedFiles);
        self::assertContains('feature.txt', $paths);
        self::assertContains('file.txt', $paths);
        foreach ($info->unpushedFiles as $file) {
            self::assertSame(FileStage::Committed, $file->stage);
        }
    }

    public function testUpstreamWithoutAheadCommits(): void
    {
        $this->initRepositoryWithCommit();
        $this->configureUpstream();

        $info = $this->repository($this->dir)->read();

        self::assertNotNull($info);
        self::assertTrue($info->hasUpstream);
        self::assertSame([], $info->unpushedCommits);
        self::assertSame([], $info->unpushedFiles);
    }

    public function testGraphCommitsOnLinearRepositoryWithoutUpstream(): void
    {
        $this->initRepositoryWithCommit();
        file_put_contents($this->dir . '/second.txt', "second\n");
        $this->git('add', 'second.txt');
        $this->git('commit', '-m', 'second');

        $info = $this->repository($this->dir)->read();

        self::assertNotNull($info);
        self::assertNull($info->upstreamRef);
        self::assertCount(2, $info->graphCommits);

        [$head, $root] = $info->graphCommits;

        self::assertSame('second', $head->subject);
        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $head->hash);
        self::assertMatchesRegularExpression('/^[0-9a-f]{7}$/', $head->shortHash);
        self::assertSame([$root->hash], $head->parentHashes);
        self::assertTrue($head->isHead);
        self::assertFalse($head->isUpstream);
        self::assertFalse($head->isPushed);
        self::assertNotSame('', $head->author);
        self::assertInstanceOf(\DateTimeImmutable::class, $head->date);

        self::assertSame('init', $root->subject);
        self::assertSame([], $root->parentHashes);
        self::assertFalse($root->isHead);
        self::assertFalse($root->isPushed);
    }

    public function testGraphCommitsWithDivergedUpstream(): void
    {
        $this->initRepositoryWithCommit();
        $this->configureUpstream();

        // One commit pushed to the upstream…
        file_put_contents($this->dir . '/remote.txt', "remote\n");
        $this->git('add', 'remote.txt');
        $this->git('commit', '-m', 'remote work');
        $this->git('push', 'origin', 'main');

        // …then the local branch is rewound and diverges with its own commit.
        $this->git('reset', '--hard', 'HEAD~1');
        file_put_contents($this->dir . '/local.txt', "local\n");
        $this->git('add', 'local.txt');
        $this->git('commit', '-m', 'local work');

        $info = $this->repository($this->dir)->read();

        self::assertNotNull($info);
        self::assertSame('origin/main', $info->upstreamRef);
        self::assertCount(3, $info->graphCommits);

        $bySubject = [];
        foreach ($info->graphCommits as $commit) {
            $bySubject[$commit->subject] = $commit;
        }

        $local = $bySubject['local work'] ?? null;
        $remote = $bySubject['remote work'] ?? null;
        $root = $bySubject['init'] ?? null;

        self::assertNotNull($local);
        self::assertTrue($local->isHead);
        self::assertFalse($local->isUpstream);
        self::assertFalse($local->isPushed);

        self::assertNotNull($remote);
        self::assertFalse($remote->isHead);
        self::assertTrue($remote->isUpstream);
        self::assertTrue($remote->isPushed);

        self::assertNotNull($root);
        self::assertFalse($root->isHead);
        self::assertFalse($root->isUpstream);
        self::assertTrue($root->isPushed);

        // Both diverged commits fork from the root.
        self::assertSame([$root->hash], $local->parentHashes);
        self::assertSame([$root->hash], $remote->parentHashes);
    }

    public function testUnpushedFilesIgnoreUpstreamOnlyChangesOfADivergedBranch(): void
    {
        $this->initRepositoryWithCommit();
        $this->configureUpstream();

        // The upstream gets a commit that adds a file and changes another…
        file_put_contents($this->dir . '/onlyup.txt', "up\n");
        file_put_contents($this->dir . '/file.txt', "changed upstream\n");
        $this->git('add', '.');
        $this->git('commit', '-m', 'upstream work');
        $this->git('push', 'origin', 'main');

        // …while the local branch, rewound, has its own commit on another file.
        $this->git('reset', '--hard', 'HEAD~1');
        file_put_contents($this->dir . '/local.txt', "local\n");
        $this->git('add', 'local.txt');
        $this->git('commit', '-m', 'local work');

        $info = $this->repository($this->dir)->read();

        self::assertNotNull($info);
        self::assertSame(1, $info->behindCount);
        self::assertCount(1, $info->unpushedCommits);
        self::assertSame(['local.txt'], array_map(static fn (ChangedFile $f) => $f->path, $info->unpushedFiles));
        self::assertSame(FileStatus::Added, $info->unpushedFiles[0]->status);
    }

    public function testGraphSurvivesAFileNamedHead(): void
    {
        $this->initRepositoryWithCommit();
        file_put_contents($this->dir . '/HEAD', "decoy\n");

        // The ambiguity between the HEAD revision and the HEAD file only arises
        // when git runs from inside the working tree — the common case when the
        // profiled application is started from the project root.
        $cwd = getcwd();
        chdir($this->dir);

        try {
            $info = $this->repository($this->dir)->read();
        } finally {
            chdir((string) $cwd);
        }

        self::assertNotNull($info);
        self::assertCount(1, $info->graphCommits);
    }

    public function testUnpushedCommitsSurviveASubjectContainingTheFieldSeparator(): void
    {
        $this->initRepositoryWithCommit();
        $this->configureUpstream();

        file_put_contents($this->dir . '/weird.txt', "weird\n");
        $this->git('add', 'weird.txt');
        $this->git('commit', '-m', "weird\x1fsubject");

        $info = $this->repository($this->dir)->read();

        self::assertNotNull($info);
        self::assertTrue($info->hasUpstream);
        self::assertCount(1, $info->unpushedCommits);
        self::assertSame("weird\x1fsubject", $info->unpushedCommits[0]->subject);
    }

    public function testGraphSurvivesASubjectContainingTheFieldSeparator(): void
    {
        $this->initRepositoryWithCommit();
        file_put_contents($this->dir . '/weird.txt', "weird\n");
        $this->git('add', 'weird.txt');
        $this->git('commit', '-m', "weird\x1fsubject");

        $info = $this->repository($this->dir)->read();

        self::assertNotNull($info);
        self::assertCount(2, $info->graphCommits);
        self::assertSame("weird\x1fsubject", $info->graphCommits[0]->subject);
        self::assertSame('init', $info->graphCommits[1]->subject);
    }

    public function testGraphCommitsAreLimited(): void
    {
        $this->initRepositoryWithCommit();
        file_put_contents($this->dir . '/file.txt', "v2\n");
        $this->git('commit', '-am', 'v2');
        file_put_contents($this->dir . '/file.txt', "v3\n");
        $this->git('commit', '-am', 'v3');

        $info = $this->repository($this->dir, graphCommitLimit: 2)->read();

        self::assertNotNull($info);
        self::assertCount(2, $info->graphCommits);
        self::assertSame('v3', $info->graphCommits[0]->subject);
        self::assertSame('v2', $info->graphCommits[1]->subject);
    }

    public function testCountsCommitsBehindTheUpstream(): void
    {
        $this->initRepositoryWithCommit();
        file_put_contents($this->dir . '/second.txt', "second\n");
        $this->git('add', 'second.txt');
        $this->git('commit', '-m', 'second');
        $this->configureUpstream();
        $this->git('reset', '--hard', 'HEAD~1');

        $info = $this->repository($this->dir)->read();

        self::assertNotNull($info);
        self::assertTrue($info->hasUpstream);
        self::assertSame(1, $info->behindCount);
        self::assertSame([], $info->unpushedCommits);
    }

    public function testNoBehindCountWithoutUpstream(): void
    {
        $this->initRepositoryWithCommit();

        self::assertSame(0, $this->repository($this->dir)->read()?->behindCount);
    }

    public function testDetachedHeadIsReportedAsHeadWithoutUpstream(): void
    {
        $this->initRepositoryWithCommit();
        $this->configureUpstream();
        $this->git('checkout', '--quiet', '--detach');

        $info = $this->repository($this->dir)->read();

        self::assertNotNull($info);
        self::assertSame('HEAD', $info->branch);
        self::assertFalse($info->hasUpstream);
    }

    public function testACleanRepositoryRunsFiveGitCommands(): void
    {
        $this->initRepositoryWithCommit();
        $this->configureUpstream();

        $this->repository($this->dir)->read();

        self::assertCount(5, $this->tracer->getTraces());
    }

    public function testADirtyRepositoryAheadOfItsUpstreamRunsNineGitCommands(): void
    {
        $this->initRepositoryWithCommit();
        $this->configureUpstream();
        file_put_contents($this->dir . '/ahead.txt', "ahead\n");
        $this->git('add', 'ahead.txt');
        $this->git('commit', '-m', 'ahead');
        file_put_contents($this->dir . '/staged.txt', "staged\n");
        $this->git('add', 'staged.txt');
        file_put_contents($this->dir . '/file.txt', "unstaged\n");

        $this->repository($this->dir)->read();

        self::assertCount(9, $this->tracer->getTraces());
    }

    public function testAFailingSectionIsLoggedAndOnlyThatSectionIsEmpty(): void
    {
        $this->initRepositoryWithCommit();

        $real = new ProcessGitCommandRunner($this->dir);
        $failingLog = new class($real) implements GitCommandRunnerInterface {
            public function __construct(private readonly GitCommandRunnerInterface $inner)
            {
            }

            public function run(array $args): string
            {
                if ($args[0] === 'log') {
                    throw GitCommandFailedException::fromArguments($args, 'simulated');
                }

                return $this->inner->run($args);
            }
        };
        $logger = new RecordingLogger();

        $info = (new GitRepository($failingLog, logger: $logger))->read();

        self::assertNotNull($info);
        self::assertSame('main', $info->branch);
        self::assertSame([], $info->graphCommits);
        self::assertNotEmpty($logger->records);
        self::assertSame('debug', $logger->records[0]['level']);
        self::assertInstanceOf(GitCommandFailedException::class, $logger->records[0]['context']['exception'] ?? null);
    }

    public function testDetectsAStagedRenameWithItsOldPathAndCounts(): void
    {
        $this->initRepositoryWithCommit();
        file_put_contents($this->dir . '/old.txt', "a\nb\nc\nd\n");
        $this->git('add', 'old.txt');
        $this->git('commit', '-m', 'add old');
        $this->git('mv', 'old.txt', 'new.txt');
        file_put_contents($this->dir . '/new.txt', "a\nb\nc\nd\ne\n");
        $this->git('add', 'new.txt');

        $info = $this->repository($this->dir)->read();

        self::assertNotNull($info);
        $staged = array_values(array_filter($info->workingFiles, static fn (ChangedFile $f): bool => $f->stage === FileStage::Staged));
        self::assertCount(1, $staged);
        self::assertSame('new.txt', $staged[0]->path);
        self::assertSame(FileStatus::Renamed, $staged[0]->status);
        self::assertSame('old.txt', $staged[0]->oldPath);
        self::assertSame(1, $staged[0]->additions);
        self::assertSame(0, $staged[0]->deletions);
        self::assertArrayNotHasKey('old.txt', $this->indexByStagePath($info->workingFiles)['staged'] ?? []);
    }

    public function testDetectsARenameInUnpushedCommits(): void
    {
        $this->initRepositoryWithCommit();
        file_put_contents($this->dir . '/old.txt', "a\nb\nc\nd\n");
        $this->git('add', 'old.txt');
        $this->git('commit', '-m', 'add old');
        $this->configureUpstream();
        $this->git('mv', 'old.txt', 'new.txt');
        file_put_contents($this->dir . '/new.txt', "a\nb\nc\nd\ne\n");
        $this->git('add', 'new.txt');
        $this->git('commit', '-m', 'rename');

        $info = $this->repository($this->dir)->read();

        self::assertNotNull($info);
        self::assertCount(1, $info->unpushedFiles);
        $file = $info->unpushedFiles[0];
        self::assertSame('new.txt', $file->path);
        self::assertSame(FileStatus::Renamed, $file->status);
        self::assertSame('old.txt', $file->oldPath);
        self::assertSame(1, $file->additions);
        self::assertSame(0, $file->deletions);
        self::assertSame(FileStage::Committed, $file->stage);
    }

    private function repository(string $directory, int $graphCommitLimit = GitRepository::DEFAULT_GRAPH_COMMIT_LIMIT): GitRepository
    {
        $this->tracer = new TraceableGitCommandRunner(new ProcessGitCommandRunner($directory));
        $this->logger = new RecordingLogger();

        // The production wiring: status, show, rev-parse, graph and config always run (stashes and tag presence read files; describe needs a tag).
        $gitDirectory = new GitDirectory($this->tracer, $directory);

        return new GitRepository(
            $this->tracer,
            graphCommitLimit: $graphCommitLimit,
            logger: $this->logger,
            operationDetector: new OperationDetector($this->tracer, $directory, $this->logger, $gitDirectory),
            stashCounter: new StashCounter($gitDirectory),
            tagPresence: new TagPresence($gitDirectory),
        );
    }

    private function initRepositoryWithCommit(): void
    {
        $this->initGitRepository($this->dir);
        $this->git('config', 'user.email', 'test@example.com');
        $this->git('config', 'user.name', 'Test');
        file_put_contents($this->dir . '/file.txt', "hello\n");
        $this->git('add', '.');
        $this->git('commit', '-m', 'init');
    }

    private function configureUpstream(): void
    {
        $this->initGitRepository($this->remoteDir, bare: true);
        $this->git('remote', 'add', 'origin', $this->remoteDir);
        $this->git('push', '-u', 'origin', 'main');
    }

    /**
     * @param list<ChangedFile> $files
     *
     * @return array<string, array<string, FileStatus>> [stage => [path => status]]
     */
    private function indexByStagePath(array $files): array
    {
        $index = [];
        foreach ($files as $file) {
            $index[$file->stage->value][$file->path] = $file->status;
        }

        return $index;
    }

    private function git(string ...$args): void
    {
        (new Process(['git', ...$args], $this->dir))->mustRun();
    }
}
