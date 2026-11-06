<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Tests\Git;

use JulienBohy\GitProfilerBundle\Exception\GitCommandFailedException;
use JulienBohy\GitProfilerBundle\Git\Command\GitCommandRunnerInterface;
use JulienBohy\GitProfilerBundle\Git\OperationDetector;
use JulienBohy\GitProfilerBundle\Git\OperationKind;
use JulienBohy\GitProfilerBundle\Tests\Support\RecordingLogger;
use JulienBohy\GitProfilerBundle\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

/**
 * Detection against hand-made git directories, so every on-disk layout is covered whatever the installed git.
 */
final class OperationDetectorTest extends TestCase
{
    use TemporaryDirectory;

    private const OID = '0123456789abcdef0123456789abcdef01234567';
    private const OTHER_OID = 'fedcba9876543210fedcba9876543210fedcba98';

    private string $root;
    private string $gitDir;

    protected function setUp(): void
    {
        $this->root = $this->createTemporaryDirectory('gpb_op_');
        $this->gitDir = $this->root . '/.git';
        mkdir($this->gitDir);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testNoOperationOnAnIdleRepository(): void
    {
        self::assertNull($this->detector()->detect());
    }

    public function testRebaseMergeBackend(): void
    {
        $this->write('rebase-merge/msgnum', "3\n");
        $this->write('rebase-merge/end', "7\n");
        $this->write('rebase-merge/head-name', "refs/heads/feature/x\n");
        $this->write('rebase-merge/onto', self::OID . "\n");

        $operation = $this->detector()->detect();

        self::assertNotNull($operation);
        self::assertSame(OperationKind::Rebase, $operation->kind);
        self::assertSame(3, $operation->step);
        self::assertSame(7, $operation->total);
        self::assertSame('feature/x', $operation->headName);
        self::assertSame('0123456', $operation->onto);
        self::assertNull($operation->target);
    }

    public function testRebaseApplyBackend(): void
    {
        $this->write('rebase-apply/next', "2\n");
        $this->write('rebase-apply/last', "5\n");
        $this->write('rebase-apply/rebasing', '');
        $this->write('rebase-apply/head-name', "refs/heads/feature\n");
        $this->write('rebase-apply/onto', self::OID . "\n");

        $operation = $this->detector()->detect();

        self::assertNotNull($operation);
        self::assertSame(OperationKind::Rebase, $operation->kind);
        self::assertSame(2, $operation->step);
        self::assertSame(5, $operation->total);
        self::assertSame('feature', $operation->headName);
        self::assertSame('0123456', $operation->onto);
    }

    public function testRebaseOfADetachedHeadHasNoBranchName(): void
    {
        $this->write('rebase-merge/msgnum', '1');
        $this->write('rebase-merge/end', '2');
        $this->write('rebase-merge/head-name', 'detached HEAD');

        $operation = $this->detector()->detect();

        self::assertNotNull($operation);
        self::assertSame(OperationKind::Rebase, $operation->kind);
        self::assertNull($operation->headName);
        self::assertNull($operation->onto);
    }

    public function testRebaseWithUnreadableCountersKeepsTheKind(): void
    {
        $this->write('rebase-merge/msgnum', 'not a number');

        $operation = $this->detector()->detect();

        self::assertNotNull($operation);
        self::assertSame(OperationKind::Rebase, $operation->kind);
        self::assertNull($operation->step);
        self::assertNull($operation->total);
    }

    public function testApplyMailbox(): void
    {
        $this->write('rebase-apply/applying', '');
        $this->write('rebase-apply/next', '2');
        $this->write('rebase-apply/last', '5');

        $operation = $this->detector()->detect();

        self::assertNotNull($operation);
        self::assertSame(OperationKind::ApplyMailbox, $operation->kind);
        self::assertSame(2, $operation->step);
        self::assertSame(5, $operation->total);
        self::assertNull($operation->headName);
    }

    public function testMergeUsesTheFirstLineOfTheMergeMessage(): void
    {
        $this->write('MERGE_HEAD', self::OID . "\n");
        $this->write('MERGE_MSG', "Merge branch 'other'\n\n# Conflicts:\n#\tfile.txt\n");

        $operation = $this->detector()->detect();

        self::assertNotNull($operation);
        self::assertSame(OperationKind::Merge, $operation->kind);
        self::assertSame("Merge branch 'other'", $operation->target);
    }

    public function testMergeFallsBackToTheShortHashWithoutMessage(): void
    {
        $this->write('MERGE_HEAD', self::OID . "\n");

        $operation = $this->detector()->detect();

        self::assertNotNull($operation);
        self::assertSame(OperationKind::Merge, $operation->kind);
        self::assertSame('0123456', $operation->target);
    }

    public function testCherryPick(): void
    {
        $this->write('CHERRY_PICK_HEAD', self::OID . "\n");

        $operation = $this->detector()->detect();

        self::assertNotNull($operation);
        self::assertSame(OperationKind::CherryPick, $operation->kind);
        self::assertSame('0123456', $operation->target);
    }

    public function testRevert(): void
    {
        $this->write('REVERT_HEAD', self::OID . "\n");

        $operation = $this->detector()->detect();

        self::assertNotNull($operation);
        self::assertSame(OperationKind::Revert, $operation->kind);
        self::assertSame('0123456', $operation->target);
    }

    public function testBisect(): void
    {
        $this->write('BISECT_LOG', "git bisect start\n");

        $operation = $this->detector()->detect();

        self::assertNotNull($operation);
        self::assertSame(OperationKind::Bisect, $operation->kind);
        self::assertNull($operation->target);
    }

    public function testShortHashesAreOnlyShortenedWhenTheyAreFullObjectIds(): void
    {
        $this->write('CHERRY_PICK_HEAD', 'abc1234');
        self::assertSame('abc1234', $this->detector()->detect()?->target);

        // 39 and 41 characters are not object ids.
        $this->write('CHERRY_PICK_HEAD', substr(self::OID, 1));
        self::assertSame(substr(self::OID, 1), $this->detector()->detect()?->target);
        $this->write('CHERRY_PICK_HEAD', self::OID . '0');
        self::assertSame(self::OID . '0', $this->detector()->detect()?->target);

        // SHA-256 object ids are 64 characters long.
        $this->write('CHERRY_PICK_HEAD', self::OID . substr(self::OTHER_OID, 0, 24));
        self::assertSame('0123456', $this->detector()->detect()?->target);
    }

    public function testPrecedenceWhenSeveralOperationsCoexist(): void
    {
        $this->write('BISECT_LOG', 'git bisect start');
        self::assertSame(OperationKind::Bisect, $this->detector()->detect()?->kind);

        $this->write('REVERT_HEAD', self::OID);
        self::assertSame(OperationKind::Revert, $this->detector()->detect()?->kind);

        $this->write('CHERRY_PICK_HEAD', self::OID);
        self::assertSame(OperationKind::CherryPick, $this->detector()->detect()?->kind);

        $this->write('MERGE_HEAD', self::OID);
        self::assertSame(OperationKind::Merge, $this->detector()->detect()?->kind);

        $this->write('rebase-apply/applying', '');
        self::assertSame(OperationKind::ApplyMailbox, $this->detector()->detect()?->kind);

        $this->write('rebase-apply/rebasing', '');
        unlink($this->gitDir . '/rebase-apply/applying');
        self::assertSame(OperationKind::Rebase, $this->detector()->detect()?->kind);

        $this->write('rebase-merge/msgnum', '1');
        self::assertSame(OperationKind::Rebase, $this->detector()->detect()?->kind);
    }

    public function testRelativeGitDirectoryIsResolvedAgainstTheWorkingDirectory(): void
    {
        $this->write('MERGE_HEAD', self::OID);

        $detector = new OperationDetector($this->runnerReturning(".git\n"), $this->root . '/');

        self::assertSame(OperationKind::Merge, $detector->detect()?->kind);
    }

    public function testRelativeGitDirectoryOutsideTheWorkingDirectory(): void
    {
        mkdir($this->root . '/sub');
        $this->write('MERGE_HEAD', self::OID);

        $detector = new OperationDetector($this->runnerReturning("../.git\n"), $this->root . '/sub');

        self::assertSame(OperationKind::Merge, $detector->detect()?->kind);
    }

    public function testAbsoluteGitDirectoryOfALinkedWorktree(): void
    {
        $worktreeGitDir = $this->gitDir . '/worktrees/wt';
        mkdir($worktreeGitDir, recursive: true);
        file_put_contents($worktreeGitDir . '/CHERRY_PICK_HEAD', self::OID);

        $detector = new OperationDetector($this->runnerReturning($worktreeGitDir . "\n"), '/somewhere/else');

        self::assertSame(OperationKind::CherryPick, $detector->detect()?->kind);
    }

    public function testWindowsAbsolutePathsAreNotPrefixedWithTheWorkingDirectory(): void
    {
        $logger = new RecordingLogger();
        $detector = new OperationDetector($this->runnerReturning('C:\\repo\\.git'), $this->root, $logger);

        self::assertNull($detector->detect());
        $message = $logger->records[0]['context']['message'] ?? null;
        self::assertIsString($message);
        self::assertStringNotContainsString($this->root, $message);
        self::assertStringContainsString('C:\\repo\\.git', $message);
    }

    public function testGitDirectoryIsLocatedOnceAndMemoized(): void
    {
        $runner = new class($this->gitDir) implements GitCommandRunnerInterface {
            /** @var list<list<string>> */
            public array $calls = [];

            public function __construct(private readonly string $gitDir)
            {
            }

            public function run(array $args): string
            {
                $this->calls[] = $args;

                return $this->gitDir . "\n";
            }
        };
        $detector = new OperationDetector($runner, $this->root);

        $detector->detect();
        $this->write('BISECT_LOG', 'git bisect start');

        // Files are read again on every call, the directory is not looked up again.
        self::assertSame(OperationKind::Bisect, $detector->detect()?->kind);
        self::assertSame([['rev-parse', '--git-dir']], $runner->calls);
    }

    public function testAFailedLookupIsNotMemoized(): void
    {
        $runner = new class($this->gitDir) implements GitCommandRunnerInterface {
            public int $calls = 0;

            public function __construct(private readonly string $gitDir)
            {
            }

            public function run(array $args): string
            {
                if (++$this->calls === 1) {
                    throw GitCommandFailedException::fromArguments($args, 'boom');
                }

                return $this->gitDir;
            }
        };
        $this->write('BISECT_LOG', 'git bisect start');
        $detector = new OperationDetector($runner, $this->root);

        $first = $detector->detect();
        $second = $detector->detect();

        self::assertNull($first);
        self::assertSame(OperationKind::Bisect, $second?->kind);
    }

    public function testMissingGitDirectoryMeansNoOperation(): void
    {
        $logger = new RecordingLogger();
        $detector = new OperationDetector($this->runnerReturning($this->root . '/missing'), $this->root, $logger);

        self::assertNull($detector->detect());
        self::assertCount(1, $logger->records);
        self::assertSame('debug', $logger->records[0]['level']);
    }

    public function testEmptyOutputMeansNoOperation(): void
    {
        $logger = new RecordingLogger();

        self::assertNull((new OperationDetector($this->runnerReturning("\n"), $this->root, $logger))->detect());
        self::assertCount(1, $logger->records);
    }

    public function testAFailingGitCommandMeansNoOperation(): void
    {
        $runner = new class implements GitCommandRunnerInterface {
            public function run(array $args): string
            {
                throw GitCommandFailedException::fromArguments($args, 'not a git repository');
            }
        };
        $logger = new RecordingLogger();

        self::assertNull((new OperationDetector($runner, $this->root, $logger))->detect());
        self::assertSame('debug', $logger->records[0]['level']);
    }

    public function testUnreadableFilesNeverThrow(): void
    {
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('File permissions are not enforced for root.');
        }

        $this->write('MERGE_HEAD', self::OID);
        $this->write('MERGE_MSG', "Merge branch 'x'");
        chmod($this->gitDir . '/MERGE_MSG', 0o000);

        $operation = $this->detector()->detect();

        self::assertNotNull($operation);
        self::assertSame(OperationKind::Merge, $operation->kind);
        self::assertSame('0123456', $operation->target);
    }

    private function detector(): OperationDetector
    {
        return new OperationDetector($this->runnerReturning($this->gitDir . "\n"), $this->root);
    }

    private function runnerReturning(string $output): GitCommandRunnerInterface
    {
        return new class($output) implements GitCommandRunnerInterface {
            public function __construct(private readonly string $output)
            {
            }

            public function run(array $args): string
            {
                return $this->output;
            }
        };
    }

    private function write(string $file, string $content): void
    {
        $path = $this->gitDir . '/' . $file;
        if (!is_dir(\dirname($path))) {
            mkdir(\dirname($path), recursive: true);
        }
        file_put_contents($path, $content);
    }
}
