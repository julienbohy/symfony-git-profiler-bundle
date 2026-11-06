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

    protected function setUp(): void
    {
        $this->dir = $this->createTemporaryDirectory('gpb_runner_');
        $this->initGitRepository($this->dir);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->dir);
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
}
