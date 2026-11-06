<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Tests\Git;

use JulienBohy\GitProfilerBundle\Git\ChangedFile;
use JulienBohy\GitProfilerBundle\Git\Command\ProcessGitCommandRunner;
use JulienBohy\GitProfilerBundle\Git\Command\TraceableGitCommandRunner;
use JulienBohy\GitProfilerBundle\Git\ConflictKind;
use JulienBohy\GitProfilerBundle\Git\FileStage;
use JulienBohy\GitProfilerBundle\Git\FileStatus;
use JulienBohy\GitProfilerBundle\Git\GitRepository;
use JulienBohy\GitProfilerBundle\Git\OperationDetector;
use JulienBohy\GitProfilerBundle\Git\OperationKind;
use JulienBohy\GitProfilerBundle\Tests\Support\GitRepositoryInitializer;
use JulienBohy\GitProfilerBundle\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Operations in progress and conflicts, on real repositories. Only commands available since git 2.11.
 */
final class GitRepositoryOperationTest extends TestCase
{
    use GitRepositoryInitializer;
    use TemporaryDirectory;

    private string $dir;
    private string $otherDir;
    private TraceableGitCommandRunner $tracer;

    protected function setUp(): void
    {
        $this->dir = $this->createTemporaryDirectory('gpb_op_');
        $this->otherDir = $this->createTemporaryDirectory('gpb_op_wt_');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->dir);
        $this->removeDirectory($this->otherDir);
    }

    public function testNoOperationOnACleanRepository(): void
    {
        $this->initRepository();

        $info = $this->read($this->dir);

        self::assertNull($info->operation);
        self::assertSame([], $info->workingFiles);
    }

    public function testMergeWithAConflict(): void
    {
        $this->initRepository();
        $this->divergeOnFile('other');

        $this->git(['merge', 'other'], allowFailure: true);
        $info = $this->read($this->dir);

        self::assertNotNull($info->operation);
        self::assertSame(OperationKind::Merge, $info->operation->kind);
        // Older git appends " into main" to the merge message when the branch is not "master".
        self::assertStringStartsWith("Merge branch 'other'", (string) $info->operation->target);
        self::assertSame('main', $info->branch);

        // The conflicted file is listed once, as a conflict, and not again as modified by the diffs.
        self::assertCount(1, $info->workingFiles);
        $file = $info->workingFiles[0];
        self::assertSame('file.txt', $file->path);
        self::assertSame(FileStatus::Conflicted, $file->status);
        self::assertSame(FileStage::Unmerged, $file->stage);
        self::assertSame(ConflictKind::BothModified, $file->conflict);
        self::assertTrue($info->isDirty);
    }

    public function testConflictsComeFirstAndAreNotDuplicatedByTheDiffs(): void
    {
        $this->initRepository();
        $this->commitFile('zzz.txt', "zzz\n", 'zzz');
        $this->divergeOnFile('other');

        $this->git(['merge', 'other'], allowFailure: true);
        // Other local changes next to the conflict: a staged one, an unstaged one and an untracked one.
        file_put_contents($this->dir . '/new.txt', "new\n");
        $this->git(['add', 'new.txt']);
        file_put_contents($this->dir . '/zzz.txt', "changed\n");
        file_put_contents($this->dir . '/loose.txt', "loose\n");

        $info = $this->read($this->dir);

        $paths = array_map(static fn (ChangedFile $file): string => $file->stage->value . ':' . $file->path, $info->workingFiles);
        self::assertSame(['unmerged:file.txt', 'staged:new.txt', 'unstaged:zzz.txt', 'untracked:loose.txt'], $paths);
    }

    public function testConflictsAloneDoNotRunTheDiffs(): void
    {
        $this->initRepository();
        $this->divergeOnFile('other');
        $this->git(['merge', 'other'], allowFailure: true);

        $this->read($this->dir);

        $commands = array_map(static fn ($trace): string => $trace->commandLine(), $this->tracer->getTraces());
        self::assertSame([], array_filter($commands, static fn (string $command): bool => str_contains($command, ' diff ')));
    }

    public function testDeletedByUsConflict(): void
    {
        $this->initRepository();
        $this->git(['checkout', '-b', 'other']);
        file_put_contents($this->dir . '/file.txt', "other\n");
        $this->git(['commit', '-am', 'other']);
        $this->git(['checkout', 'main']);
        $this->git(['rm', 'file.txt']);
        $this->git(['commit', '-m', 'delete']);

        $this->git(['merge', 'other'], allowFailure: true);
        $info = $this->read($this->dir);

        self::assertCount(1, $info->workingFiles);
        self::assertSame(ConflictKind::DeletedByUs, $info->workingFiles[0]->conflict);
    }

    public function testRebaseStoppedOnAConflict(): void
    {
        $this->initRepository();
        $this->git(['checkout', '-b', 'feature']);
        $this->commitFile('a.txt', "a\n", 'first');
        $this->commitFile('file.txt', "feature\n", 'second');
        $this->commitFile('b.txt', "b\n", 'third');
        $this->git(['checkout', 'main']);
        $this->commitFile('file.txt', "main\n", 'main change');
        $this->git(['checkout', 'feature']);

        $this->git(['rebase', 'main'], allowFailure: true);
        $info = $this->read($this->dir);

        // git < 2.26 replays through "rebase-apply" (am), newer versions through "rebase-merge": both are detected.
        self::assertNotNull($info->operation);
        self::assertSame(OperationKind::Rebase, $info->operation->kind);
        self::assertSame(2, $info->operation->step);
        self::assertSame(3, $info->operation->total);
        self::assertSame('feature', $info->operation->headName);
        self::assertSame(trim($this->gitOutput(['rev-parse', '--short=7', 'main'])), $info->operation->onto);
        // HEAD is detached while the rebase is stopped.
        self::assertSame('HEAD', $info->branch);
        self::assertCount(1, array_filter($info->workingFiles, static fn (ChangedFile $file): bool => $file->status === FileStatus::Conflicted));
        self::assertSame('file.txt', $info->workingFiles[0]->path);
    }

    public function testApplyMailboxInProgress(): void
    {
        $this->initRepository();
        $this->git(['checkout', '-b', 'patch']);
        $this->commitFile('file.txt', "patched\n", 'patched');
        $patch = $this->dir . '/../' . basename($this->dir) . '.mbox';
        file_put_contents($patch, $this->gitOutput(['format-patch', '--stdout', 'main..patch']));
        $this->git(['checkout', 'main']);
        $this->commitFile('file.txt', "diverged\n", 'diverged');

        try {
            $this->git(['am', '--3way', $patch], allowFailure: true);
            $info = $this->read($this->dir);
        } finally {
            unlink($patch);
        }

        self::assertNotNull($info->operation);
        self::assertSame(OperationKind::ApplyMailbox, $info->operation->kind);
        self::assertSame(1, $info->operation->step);
        self::assertSame(1, $info->operation->total);
    }

    public function testCherryPickWithAConflict(): void
    {
        $this->initRepository();
        $this->divergeOnFile('other');

        $this->git(['cherry-pick', 'other'], allowFailure: true);
        $info = $this->read($this->dir);

        self::assertNotNull($info->operation);
        self::assertSame(OperationKind::CherryPick, $info->operation->kind);
        self::assertSame(trim($this->gitOutput(['rev-parse', '--short=7', 'other'])), $info->operation->target);
        self::assertSame(FileStatus::Conflicted, $info->workingFiles[0]->status);
    }

    public function testRevertWithAConflict(): void
    {
        $this->initRepository();
        $this->commitFile('file.txt', "second\n", 'second');
        $this->commitFile('file.txt', "third\n", 'third');

        $this->git(['revert', '--no-edit', 'HEAD~1'], allowFailure: true);
        $info = $this->read($this->dir);

        self::assertNotNull($info->operation);
        self::assertSame(OperationKind::Revert, $info->operation->kind);
        self::assertSame(trim($this->gitOutput(['rev-parse', '--short=7', 'HEAD~1'])), $info->operation->target);
        self::assertSame(FileStatus::Conflicted, $info->workingFiles[0]->status);
    }

    public function testBisectStarted(): void
    {
        $this->initRepository();
        $this->commitFile('file.txt', "second\n", 'second');
        $this->git(['bisect', 'start']);

        $info = $this->read($this->dir);

        self::assertNotNull($info->operation);
        self::assertSame(OperationKind::Bisect, $info->operation->kind);
        self::assertSame([], $info->workingFiles);
    }

    public function testMergeInALinkedWorktree(): void
    {
        $this->initRepository();
        $this->divergeOnFile('other');
        $worktree = $this->otherDir . '/worktree';
        $this->git(['worktree', 'add', '-b', 'feature', $worktree]);
        $this->commitFileIn($worktree, 'file.txt', "feature\n", 'feature change');
        $this->git(['merge', 'other'], allowFailure: true, directory: $worktree);

        $info = $this->read($worktree);

        // The git directory of a linked worktree lives in the main repository.
        self::assertStringContainsString('/worktrees/', trim($this->gitOutput(['rev-parse', '--git-dir'], $worktree)));
        self::assertNotNull($info->operation);
        self::assertSame(OperationKind::Merge, $info->operation->kind);
        self::assertSame('feature', $info->branch);
        self::assertSame(FileStatus::Conflicted, $info->workingFiles[0]->status);
        // The main working tree is not affected.
        self::assertNull($this->read($this->dir)->operation);
    }

    public function testReadingTheGitDirectoryCostsOneExtraCommandOnceAndIsTraced(): void
    {
        $this->initRepository();
        $repository = $this->repository($this->dir);

        $repository->read();
        $repository->read();

        $commands = array_map(static fn ($trace): string => $trace->commandLine(), $this->tracer->getTraces());
        self::assertCount(1, array_filter($commands, static fn (string $command): bool => str_contains($command, 'rev-parse --git-dir')));
    }

    public function testWithoutDetectorThereIsNoOperation(): void
    {
        $this->initRepository();
        $this->divergeOnFile('other');
        $this->git(['merge', 'other'], allowFailure: true);

        $info = (new GitRepository(new ProcessGitCommandRunner($this->dir)))->read();

        self::assertNotNull($info);
        self::assertNull($info->operation);
        self::assertSame(FileStatus::Conflicted, $info->workingFiles[0]->status);
    }

    private function read(string $directory): \JulienBohy\GitProfilerBundle\Git\GitInfo
    {
        $info = $this->repository($directory)->read();
        self::assertNotNull($info);

        return $info;
    }

    private function repository(string $directory): GitRepository
    {
        $this->tracer = new TraceableGitCommandRunner(new ProcessGitCommandRunner($directory));

        return new GitRepository($this->tracer, operationDetector: new OperationDetector($this->tracer, $directory));
    }

    private function initRepository(): void
    {
        $this->initGitRepository($this->dir);
        $this->git(['config', 'user.email', 'test@example.com']);
        $this->git(['config', 'user.name', 'Test']);
        $this->commitFile('file.txt', "hello\n", 'init');
    }

    /**
     * Creates the branch $branch with a change of file.txt, then makes main change the same line differently.
     */
    private function divergeOnFile(string $branch): void
    {
        $this->git(['checkout', '-b', $branch]);
        $this->commitFile('file.txt', "{$branch}\n", $branch);
        $this->git(['checkout', 'main']);
        $this->commitFile('file.txt', "main\n", 'main change');
    }

    private function commitFile(string $file, string $content, string $message): void
    {
        $this->commitFileIn($this->dir, $file, $content, $message);
    }

    private function commitFileIn(string $directory, string $file, string $content, string $message): void
    {
        file_put_contents($directory . '/' . $file, $content);
        $this->git(['add', $file], directory: $directory);
        $this->git(['commit', '-m', $message], directory: $directory);
    }

    /**
     * @param list<string> $args
     */
    private function git(array $args, bool $allowFailure = false, ?string $directory = null): void
    {
        $process = new Process(['git', ...$args], $directory ?? $this->dir, ['GIT_EDITOR' => 'true', 'GIT_TERMINAL_PROMPT' => '0']);
        $allowFailure ? $process->run() : $process->mustRun();
    }

    /**
     * @param list<string> $args
     */
    private function gitOutput(array $args, ?string $directory = null): string
    {
        return (new Process(['git', ...$args], $directory ?? $this->dir))->mustRun()->getOutput();
    }
}
