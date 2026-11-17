<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Tests\Git\Command;

use JulienBohy\GitProfilerBundle\Exception\ExceptionInterface;
use JulienBohy\GitProfilerBundle\Exception\GitCommandFailedException;
use JulienBohy\GitProfilerBundle\Git\Command\ProcessGitCommandRunner;
use JulienBohy\GitProfilerBundle\Tests\Support\GitRepositoryInitializer;
use JulienBohy\GitProfilerBundle\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProcessGitCommandRunnerTest extends TestCase
{
    use GitRepositoryInitializer;
    use TemporaryDirectory;

    private string $dir;

    /**
     * @var list<string>
     */
    private array $fakeBinaryDirectories = [];

    protected function setUp(): void
    {
        $this->dir = $this->createTemporaryDirectory('gpb_runner_');
        $this->initGitRepository($this->dir);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->dir);
        array_map($this->removeDirectory(...), $this->fakeBinaryDirectories);
    }

    public function testReturnsTheRawStandardOutput(): void
    {
        $output = (new ProcessGitCommandRunner($this->dir))->run(['rev-parse', '--is-inside-work-tree']);

        self::assertSame("true\n", $output);
    }

    public function testThrowsWithTheArgumentsAndStderrWhenGitFails(): void
    {
        try {
            (new ProcessGitCommandRunner($this->dir))->run(['no-such-subcommand']);
            self::fail('An exception was expected.');
        } catch (GitCommandFailedException $e) {
            self::assertInstanceOf(ExceptionInterface::class, $e);
            self::assertStringContainsString('git no-such-subcommand', $e->getMessage());
            self::assertStringContainsString('is not a git command', $e->getMessage());
        }
    }

    public function testThrowsWhenTheWorkingDirectoryDoesNotExist(): void
    {
        $this->expectException(GitCommandFailedException::class);

        (new ProcessGitCommandRunner($this->dir . '/missing'))->run(['status']);
    }

    public function testThrowsWhenTheBinaryDoesNotExist(): void
    {
        $this->expectException(GitCommandFailedException::class);

        (new ProcessGitCommandRunner($this->dir, $this->dir . '/no-git-here'))->run(['status']);
    }

    public function testBuildsTheProcessWithASafeEnvironmentAndTheTimeout(): void
    {
        $process = (new ProcessGitCommandRunner($this->dir, '/usr/bin/git', 2.5))->createProcess(['status']);

        $env = $process->getEnv();
        self::assertSame('C', $env['LC_ALL'] ?? null);
        self::assertSame('0', $env['GIT_OPTIONAL_LOCKS'] ?? null);
        self::assertSame('0', $env['GIT_TERMINAL_PROMPT'] ?? null);
        self::assertSame(2.5, $process->getTimeout());
        self::assertSame($this->dir, $process->getWorkingDirectory());
        self::assertStringContainsString('/usr/bin/git', $process->getCommandLine());
    }

    #[DataProvider('unusableTimeouts')]
    public function testNeverRunsWithoutATimeout(float|int|string $configured): void
    {
        $timeout = (new ProcessGitCommandRunner($this->dir, 'git', $configured))->createProcess(['status'])->getTimeout();

        self::assertSame(5.0, $timeout);
    }

    /**
     * @return iterable<string, array{float|int|string}>
     */
    public static function unusableTimeouts(): iterable
    {
        yield 'zero' => [0];
        yield 'zero float' => [0.0];
        yield 'negative' => [-1];
        yield 'non numeric string' => ['%env(float:MISSING)%'];
        yield 'empty string' => [''];
    }

    public function testCastsAnEnvResolvedStringTimeout(): void
    {
        $process = (new ProcessGitCommandRunner($this->dir, 'git', '2.5'))->createProcess(['status']);

        self::assertSame(2.5, $process->getTimeout());
    }

    public function testIgnoresAnInheritedGitDir(): void
    {
        $other = $this->createTemporaryDirectory('gpb_other_');
        $this->initGitRepository($other);

        $previous = getenv('GIT_DIR');
        putenv('GIT_DIR=' . $other . '/.git');
        try {
            $toplevel = (new ProcessGitCommandRunner($this->dir))->run(['rev-parse', '--show-toplevel']);
        } finally {
            false === $previous ? putenv('GIT_DIR') : putenv('GIT_DIR=' . $previous);
            $this->removeDirectory($other);
        }

        self::assertSame(realpath($this->dir), trim($toplevel));
    }

    public function testNeutralisesUserConfigThatAltersTheOutputFormat(): void
    {
        $commandLine = (new ProcessGitCommandRunner($this->dir, '/usr/bin/git'))->createProcess(['show', '-s'])->getCommandLine();

        $expected = ["'/usr/bin/git'", "'-c'", "'log.showSignature=false'", "'-c'", "'diff.relative=false'", "'show'", "'-s'"];
        self::assertSame(implode(' ', $expected), $commandLine);
    }

    public function testRunsAllTheCommandsOfABatchAtTheSameTime(): void
    {
        // Each fake "git" announces itself, then waits for its two siblings to announce themselves: it only
        // succeeds when the three processes were started before any of them was waited for.
        $barrier = $this->createTemporaryDirectory('gpb_barrier_');
        $binary = $this->fakeGit(<<<SH
            touch "$barrier/\$\$"
            for i in \$(seq 1 500); do
                [ "\$(ls "$barrier" | wc -l)" -ge 3 ] && echo "together \$1" && exit 0
                sleep 0.01
            done
            echo "alone" >&2
            exit 3
            SH);

        $results = (new ProcessGitCommandRunner($this->dir, $binary))->runParallel([['status'], ['log'], ['diff']]);

        self::assertCount(3, $results);
        foreach ($results as $result) {
            self::assertTrue($result->isSuccessful(), (string) $result->failure?->getMessage());
            self::assertStringStartsWith('together', $result->output());
        }
        $this->removeDirectory($barrier);
    }

    public function testResultsFollowTheOrderOfTheCommandsWhateverTheOrderOfCompletion(): void
    {
        // The first command is the slowest one.
        $binary = $this->fakeGit(<<<'SH'
            case "$*" in *slow*) sleep 0.3 ;; esac
            echo "$@"
            SH);

        $results = (new ProcessGitCommandRunner($this->dir, $binary))->runParallel([['slow'], ['fast'], ['quick']]);

        self::assertCount(3, $results);
        self::assertStringEndsWith("slow\n", $results[0]->output());
        self::assertStringEndsWith("fast\n", $results[1]->output());
        self::assertStringEndsWith("quick\n", $results[2]->output());
        // Each result has its own duration, not the wall time of the batch.
        self::assertGreaterThan($results[1]->durationMs, $results[0]->durationMs);
        self::assertGreaterThanOrEqual(250.0, $results[0]->durationMs);
    }

    public function testAFailingCommandOfABatchDoesNotAffectTheOthers(): void
    {
        $results = (new ProcessGitCommandRunner($this->dir))->runParallel([
            ['rev-parse', '--is-inside-work-tree'],
            ['no-such-subcommand'],
            ['rev-parse', '--git-dir'],
        ]);

        self::assertCount(3, $results);
        self::assertSame("true\n", $results[0]->output());
        self::assertFalse($results[1]->isSuccessful());
        self::assertInstanceOf(GitCommandFailedException::class, $results[1]->failure);
        self::assertStringContainsString('is not a git command', $results[1]->failure->getMessage());
        self::assertSame(".git\n", $results[2]->output());
        self::assertFalse($results[0]->cached);
    }

    public function testEachCommandOfABatchHasItsOwnTimeout(): void
    {
        $binary = $this->fakeGit(<<<'SH'
            case "$*" in *hang*) sleep 10 ;; esac
            echo done
            SH);

        $results = (new ProcessGitCommandRunner($this->dir, $binary, 0.5))->runParallel([['ok'], ['hang'], ['ok']]);

        self::assertCount(3, $results);
        self::assertSame("done\n", $results[0]->output());
        self::assertFalse($results[1]->isSuccessful());
        self::assertStringContainsString('exceeded the timeout of 0.5 seconds', (string) $results[1]->failure?->getMessage());
        self::assertSame("done\n", $results[2]->output());
    }

    public function testABatchReadsLargeOutputsWithoutStalling(): void
    {
        // Larger than a pipe buffer: the output of a command is drained while the others are still running.
        $binary = $this->fakeGit(<<<'SH'
            head -c 400000 /dev/zero | tr '\0' 'x'
            SH);

        $results = (new ProcessGitCommandRunner($this->dir, $binary))->runParallel([['a'], ['b']]);

        self::assertSame(400000, \strlen($results[0]->output()));
        self::assertSame(400000, \strlen($results[1]->output()));
    }

    public function testAnEmptyBatchRunsNothing(): void
    {
        self::assertSame([], (new ProcessGitCommandRunner($this->dir))->runParallel([]));
    }

    public function testACommandThatCannotStartIsAFailureOfItsOwn(): void
    {
        $results = (new ProcessGitCommandRunner($this->dir . '/missing'))->runParallel([['status'], ['log']]);

        self::assertCount(2, $results);
        self::assertFalse($results[0]->isSuccessful());
        self::assertFalse($results[1]->isSuccessful());
    }

    public function testAFailedResultThrowsItsFailureWhenReadAndASuccessfulOneNeverDoes(): void
    {
        $results = (new ProcessGitCommandRunner($this->dir))->runParallel([['no-such-subcommand'], ['rev-parse', '--git-dir']]);

        try {
            $results[0]->output();
            self::fail('An exception was expected.');
        } catch (GitCommandFailedException $e) {
            self::assertSame($results[0]->failure, $e);
        }
        self::assertSame(".git\n", $results[1]->output());
    }

    /**
     * An executable stand-in for the git binary: it is given "-c <setting> -c <setting>" first, then the arguments.
     */
    private function fakeGit(string $body): string
    {
        $directory = $this->createTemporaryDirectory('gpb_fakegit_');
        $this->fakeBinaryDirectories[] = $directory;
        $file = $directory . '/git';
        // Drops the four "-c" arguments added by the runner, so $@ holds the git arguments only.
        file_put_contents($file, "#!/bin/sh\nshift 4\n" . $body . "\n");
        chmod($file, 0o755);

        return $file;
    }
}
