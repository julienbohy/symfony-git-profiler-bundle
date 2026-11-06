<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Tests\Templates;

use JulienBohy\GitProfilerBundle\DataCollector\GitDataCollector;
use JulienBohy\GitProfilerBundle\Exception\GitCommandFailedException;
use JulienBohy\GitProfilerBundle\Git\ChangedFile;
use JulienBohy\GitProfilerBundle\Git\Command\GitCommandRunnerInterface;
use JulienBohy\GitProfilerBundle\Git\Command\TraceableGitCommandRunner;
use JulienBohy\GitProfilerBundle\Git\ConflictKind;
use JulienBohy\GitProfilerBundle\Git\FileStage;
use JulienBohy\GitProfilerBundle\Git\FileStatus;
use JulienBohy\GitProfilerBundle\Git\GitInfo;
use JulienBohy\GitProfilerBundle\Git\GitRepositoryInterface;
use JulienBohy\GitProfilerBundle\Git\GraphCommit;
use JulienBohy\GitProfilerBundle\Git\Operation;
use JulienBohy\GitProfilerBundle\Git\OperationKind;
use JulienBohy\GitProfilerBundle\Git\Remote\GitProvider;
use JulienBohy\GitProfilerBundle\Git\Remote\RemoteRepository;
use JulienBohy\GitProfilerBundle\Git\Remote\RemoteUrlParser;
use JulienBohy\GitProfilerBundle\Git\UnpushedCommit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;

/**
 * Renders the collector template with the profiler layout stubbed out, so the
 * blocks are executed with real data without booting a kernel.
 */
final class GitTemplateRenderingTest extends TestCase
{
    public function testRendersTheCommitGraph(): void
    {
        $rootHash = str_repeat('c', 40);

        $info = new GitInfo(
            'main',
            'abc1234',
            false,
            hasUpstream: true,
            graphCommits: [
                new GraphCommit(str_repeat('a', 40), 'aaaaaaa', [$rootHash], 'local work', 'Alice', new \DateTimeImmutable('2026-08-14T10:00:00+00:00'), isHead: true),
                new GraphCommit(str_repeat('b', 40), 'bbbbbbb', [$rootHash], 'remote work', 'Bob', new \DateTimeImmutable('2026-08-14T09:00:00+00:00'), isUpstream: true, isPushed: true),
                new GraphCommit($rootHash, 'ccccccc', [], 'init', 'Alice', new \DateTimeImmutable('2026-08-14T08:00:00+00:00'), isPushed: true),
            ],
            upstreamRef: 'origin/main',
        );

        $html = $this->render($info);

        self::assertStringContainsString('Commit graph', $html);
        self::assertStringContainsString('local work', $html);
        // Dots are drawn for the three commits.
        self::assertSame(3, substr_count($html, '<circle'));
        // The diverged branch produces at least one curved segment — fill="none"
        // is unique to the bezier curves (the icon SVG also contains a <path>).
        self::assertStringContainsString('fill="none"', $html);
        // Refs are labelled.
        self::assertStringContainsString('HEAD → main', $html);
        self::assertStringContainsString('origin/main', $html);
    }

    public function testFillsAllDotsWhenThereIsNoUpstream(): void
    {
        // Without an upstream, "pushed" is meaningless: no dot may render hollow
        // (the hollow-dot legend is hidden in that state).
        $rootHash = str_repeat('c', 40);

        $html = $this->render(new GitInfo(
            'main',
            'abc1234',
            false,
            graphCommits: [
                new GraphCommit(str_repeat('a', 40), 'aaaaaaa', [$rootHash], 'local work', 'Alice', new \DateTimeImmutable('2026-08-14T10:00:00+00:00'), isHead: true),
                new GraphCommit($rootHash, 'ccccccc', [], 'init', 'Alice', new \DateTimeImmutable('2026-08-14T08:00:00+00:00')),
            ],
        ));

        self::assertStringContainsString('Commit graph', $html);
        self::assertStringNotContainsString('var(--base-0', $html);
        self::assertStringNotContainsString('Hollow dots', $html);
    }

    public function testDetachedHeadShowsAPlainHeadBadge(): void
    {
        $html = $this->render(new GitInfo(
            'HEAD',
            'abc1234',
            false,
            graphCommits: [
                new GraphCommit(str_repeat('a', 40), 'aaaaaaa', [], 'detached work', 'Alice', new \DateTimeImmutable('2026-08-14T10:00:00+00:00'), isHead: true),
            ],
        ));

        self::assertStringNotContainsString('HEAD → HEAD', $html);
        self::assertStringContainsString('git-badge-head">HEAD<', $html);
    }

    public function testDetachedHeadIsLabelledAsDetached(): void
    {
        $html = $this->render(new GitInfo('HEAD', 'abc1234', false));

        self::assertStringContainsString('<span class="label">Detached HEAD</span>', $html);
        self::assertStringContainsString('<span class="value">detached</span>', $html);
        self::assertStringContainsString('<b>Detached HEAD</b>', $html);
        self::assertStringNotContainsString('<span class="value">HEAD</span>', $html);
        self::assertStringNotContainsString('<span class="label">Branch</span>', $html);
        self::assertStringContainsString('Detached HEAD — no branch, so no upstream to compare with.', $html);
        self::assertStringNotContainsString('for this branch', $html);
    }

    public function testRealBranchWithoutUpstreamKeepsTheNoUpstreamMessage(): void
    {
        $html = $this->render(new GitInfo('feature/x', 'abc1234', false));

        self::assertStringContainsString('<span class="label">Branch</span>', $html);
        self::assertStringContainsString('<span class="value">feature/x</span>', $html);
        self::assertStringContainsString('No upstream branch configured for this branch.', $html);
        self::assertStringNotContainsString('Detached HEAD', $html);
    }

    public function testBehindCardShowsADashWithoutUpstream(): void
    {
        $html = $this->render(new GitInfo('main', 'abc1234', false));

        self::assertMatchesRegularExpression('#<span class="value">–</span>\s*<span class="label">Behind upstream</span>#', $html);
    }

    #[DataProvider('behindCounts')]
    public function testBehindCard(int $behind): void
    {
        $html = $this->render(new GitInfo('main', 'abc1234', false, hasUpstream: true, upstreamRef: 'origin/main', behindCount: $behind));

        self::assertMatchesRegularExpression('#<span class="value">' . $behind . '</span>\s*<span class="label">Behind upstream</span>#', $html);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function behindCounts(): iterable
    {
        yield 'up to date' => [0];
        yield 'behind' => [3];
    }

    public function testNothingToPushButBehindRendersOneCoherentMessage(): void
    {
        $html = $this->render(new GitInfo('main', 'abc1234', false, hasUpstream: true, upstreamRef: 'origin/main', behindCount: 3));

        self::assertStringContainsString("Nothing to push. <code>origin/main</code> has 3 commits you don't have yet (as of the last fetch).", $html);
        self::assertStringNotContainsString('Up to date with upstream', $html);
        self::assertStringNotContainsString('Also 3 commits behind', $html);
    }

    public function testSingularBehindMessage(): void
    {
        $html = $this->render(new GitInfo('main', 'abc1234', false, hasUpstream: true, upstreamRef: 'origin/main', behindCount: 1));

        self::assertStringContainsString("has 1 commit you don't have yet", $html);
    }

    public function testUpToDateMessageWhenNeitherAheadNorBehind(): void
    {
        $html = $this->render(new GitInfo('main', 'abc1234', false, hasUpstream: true, upstreamRef: 'origin/main'));

        self::assertStringContainsString('Up to date with upstream — nothing to push.', $html);
        self::assertStringNotContainsString('Nothing to push.', $html);
    }

    public function testAheadAndBehindShowsTheBehindLineAboveTheTable(): void
    {
        $html = $this->render(new GitInfo(
            'main',
            'abc1234',
            false,
            hasUpstream: true,
            unpushedCommits: [new UnpushedCommit('aaaaaaa', 'local work', 'Alice', new \DateTimeImmutable('2026-08-14T10:00:00+00:00'))],
            upstreamRef: 'origin/main',
            behindCount: 2,
        ));

        $behindLine = strpos($html, 'Also 2 commits behind <code>origin/main</code> (as of the last fetch).');
        self::assertNotFalse($behindLine);
        self::assertLessThan(strpos($html, 'Files touched by these commits') ?: \PHP_INT_MAX, $behindLine);
        self::assertStringContainsString('local work', $html);
        self::assertStringNotContainsString('Nothing to push', $html);
        self::assertStringNotContainsString('Up to date with upstream', $html);
    }

    public function testDatesAreEmittedAsTimeElementsConvertedToTheUserTimezone(): void
    {
        $html = $this->render(new GitInfo(
            'main',
            'abc1234',
            false,
            hasUpstream: true,
            unpushedCommits: [new UnpushedCommit('aaaaaaa', 'local work', 'Alice', new \DateTimeImmutable('2026-08-14T10:00:00+02:00'))],
            graphCommits: [
                new GraphCommit(str_repeat('a', 40), 'aaaaaaa', [], 'local work', 'Alice', new \DateTimeImmutable('2026-08-14T10:00:00+02:00'), isHead: true),
            ],
            upstreamRef: 'origin/main',
        ));

        // Once in the commit graph, once in the unpushed commits table.
        self::assertSame(2, substr_count($html, '<time class="git-local-time" datetime="2026-08-14T10:00:00+02:00">'));
        self::assertMatchesRegularExpression('#<time class="git-local-time" [^>]*>2026-08-14 \d{2}:\d{2}</time>#', $html);
        // The script that rewrites them in the viewer's timezone ships with the panel.
        self::assertStringContainsString("querySelectorAll('time.git-local-time')", $html);
    }

    public function testOmitsTheCommitGraphWhenThereAreNoGraphCommits(): void
    {
        $html = $this->render(new GitInfo('main', 'abc1234', false));

        self::assertStringNotContainsString('Commit graph', $html);
    }

    public function testShowsTheBehindCountWhenTheUpstreamIsAhead(): void
    {
        $html = $this->render(new GitInfo('main', 'abc1234', false, hasUpstream: true, upstreamRef: 'origin/main', behindCount: 2));

        self::assertStringContainsString('&#8595; 2', $html);
        self::assertStringContainsString('has 2 commits you don\'t have yet (as of the last fetch).', $html);
    }

    public function testHidesTheBehindCountWhenNothingIsBehind(): void
    {
        $html = $this->render(new GitInfo('main', 'abc1234', false, hasUpstream: true, upstreamRef: 'origin/main'));

        self::assertStringNotContainsString('&#8595;', $html);
        self::assertStringNotContainsString('behind <code>', $html);
    }

    public function testListsTheGitCommandsWithFailures(): void
    {
        $inner = new class implements GitCommandRunnerInterface {
            public function run(array $args): string
            {
                if ($args[0] === 'log') {
                    throw GitCommandFailedException::fromArguments($args, 'simulated failure');
                }

                return '';
            }
        };
        $tracer = new TraceableGitCommandRunner($inner);
        $readGit = static function () use ($tracer): void {
            $tracer->run(['status', '-z']);
            try {
                $tracer->run(['log']);
            } catch (GitCommandFailedException) {
            }
        };

        $html = $this->render(new GitInfo('main', 'abc1234', false), $tracer, $readGit);

        self::assertStringContainsString('<h3>Git commands</h3>', $html);
        self::assertStringContainsString('<code>git status -z</code>', $html);
        self::assertStringContainsString('2 commands in', $html);
        self::assertStringContainsString('simulated failure', $html);
    }

    public function testNoBannerAndANormalToolbarWithoutOperation(): void
    {
        $html = $this->render(new GitInfo('main', 'abc1234', false));

        self::assertStringNotContainsString('git-operation', $html);
        self::assertStringNotContainsString('Conflicts', $html);
        self::assertStringContainsString('sf-toolbar-status-normal', $html);
    }

    #[DataProvider('banners')]
    public function testOperationBanner(Operation $operation, string $banner, string $hints): void
    {
        $html = $this->render(new GitInfo('main', 'abc1234', false, operation: $operation));

        self::assertStringContainsString('class="git-operation status-warning"', $html);
        self::assertMatchesRegularExpression('#<b>' . preg_quote($banner, '#') . '</b>#', $html);
        self::assertStringContainsString($hints, $html);
        self::assertStringNotContainsString('conflict', $html);
        self::assertStringContainsString('sf-toolbar-status-yellow', $html);
    }

    /**
     * @return iterable<string, array{Operation, string, string}>
     */
    public static function banners(): iterable
    {
        yield 'rebase' => [
            new Operation(OperationKind::Rebase, 3, 7, 'feature/x', '1a2b3c4'),
            'Rebasing <code>feature/x</code> onto <code>1a2b3c4</code> — step 3/7',
            '<code>git rebase --continue</code> · <code>git rebase --abort</code>',
        ];
        yield 'rebase without details' => [new Operation(OperationKind::Rebase), 'Rebasing', '<code>git rebase --abort</code>'];
        yield 'merge' => [
            new Operation(OperationKind::Merge, target: "Merge branch 'other'"),
            'Merging <code>Merge branch &#039;other&#039;</code>',
            '<code>git commit</code> · <code>git merge --abort</code>',
        ];
        yield 'merge without message' => [new Operation(OperationKind::Merge), 'Merge in progress', '<code>git merge --abort</code>'];
        yield 'am' => [
            new Operation(OperationKind::ApplyMailbox, 2, 5),
            'Applying patches (am) — 2/5',
            '<code>git am --continue</code> · <code>git am --skip</code> · <code>git am --abort</code>',
        ];
        yield 'cherry-pick' => [
            new Operation(OperationKind::CherryPick, target: 'abc1234'),
            'Cherry-picking <code>abc1234</code>',
            '<code>git cherry-pick --continue</code> · <code>git cherry-pick --abort</code>',
        ];
        yield 'revert' => [
            new Operation(OperationKind::Revert, target: 'abc1234'),
            'Reverting <code>abc1234</code>',
            '<code>git revert --continue</code> · <code>git revert --abort</code>',
        ];
        yield 'bisect' => [new Operation(OperationKind::Bisect), 'Bisect in progress', '<code>git bisect reset</code>'];
    }

    public function testConflictsAreMentionedInTheBannerShownAsACardAndTurnTheToolbarRed(): void
    {
        $html = $this->render(new GitInfo(
            'HEAD',
            'abc1234',
            true,
            workingFiles: [
                new ChangedFile('src/A.php', FileStatus::Conflicted, FileStage::Unmerged, conflict: ConflictKind::BothModified),
                new ChangedFile('src/B.php', FileStatus::Conflicted, FileStage::Unmerged, conflict: ConflictKind::DeletedByUs),
                new ChangedFile('src/C.php', FileStatus::Modified, FileStage::Unstaged, additions: 2, deletions: 1),
            ],
            operation: new Operation(OperationKind::Merge, target: 'feature'),
        ));

        self::assertStringContainsString('class="git-operation status-error"', $html);
        self::assertMatchesRegularExpression('#<b>Merging <code>feature</code></b>\s*— 2 conflicts#', $html);
        self::assertMatchesRegularExpression('#<span class="value">2</span>\s*<span class="label">Conflicts</span>#', $html);
        self::assertStringContainsString('sf-toolbar-status-red', $html);
        self::assertStringNotContainsString('sf-toolbar-status-yellow', $html);
        // Toolbar: label of the operation, conflict counter and the matching tooltip rows.
        self::assertStringContainsString('title="Operation in progress">merging</span>', $html);
        self::assertStringContainsString('title="Files with conflicts">&#9888; 2</span>', $html);
        self::assertMatchesRegularExpression('#<b>Operation</b>\s*<span>merging</span>#', $html);
        self::assertMatchesRegularExpression('#<b>Conflicts</b>\s*<span>2</span>#', $html);
    }

    public function testASingleConflictIsNotPluralised(): void
    {
        $html = $this->render(new GitInfo(
            'main',
            'abc1234',
            true,
            workingFiles: [new ChangedFile('a.txt', FileStatus::Conflicted, FileStage::Unmerged, conflict: ConflictKind::BothAdded)],
            operation: new Operation(OperationKind::CherryPick, target: 'abc1234'),
        ));

        self::assertMatchesRegularExpression('#— 1 conflict(?!s)#', $html);
    }

    public function testConflictedFilesAreListedWithTheirKindAndWithoutLineCounts(): void
    {
        $html = $this->render(new GitInfo(
            'main',
            'abc1234',
            true,
            workingFiles: [
                new ChangedFile('a.txt', FileStatus::Conflicted, FileStage::Unmerged, conflict: ConflictKind::AddedByThem),
                new ChangedFile('b.txt', FileStatus::Modified, FileStage::Unstaged, additions: 4, deletions: 2),
            ],
        ));

        // A conflict without an operation (e.g. `git stash pop`) still turns the toolbar red.
        self::assertStringNotContainsString('git-operation', $html);
        self::assertStringContainsString('sf-toolbar-status-red', $html);
        self::assertMatchesRegularExpression('#<code>a\.txt</code>.*?<td>Conflict — added by them</td>\s*<td>Unmerged</td>\s*<td></td>#s', $html);
        self::assertMatchesRegularExpression('#<td>Modified</td>\s*<td>Unstaged</td>\s*<td><span class="text-muted">\+4 −2</span></td>#', $html);
        self::assertLessThan(strpos($html, 'b.txt') ?: \PHP_INT_MAX, strpos($html, 'a.txt'));
    }

    public function testRebasingShowsTheBranchBeingRebasedInsteadOfDetached(): void
    {
        $html = $this->render(new GitInfo('HEAD', 'abc1234', false, operation: new Operation(OperationKind::Rebase, 3, 7, 'feature/x', '1a2b3c4')));

        self::assertStringContainsString('<span class="label">Rebasing</span>', $html);
        self::assertStringContainsString('<span class="value">feature/x</span>', $html);
        self::assertStringContainsString('<b>Rebasing</b>', $html);
        self::assertStringContainsString('title="Rebasing feature/x">feature/x</span>', $html);
        self::assertStringContainsString('title="Operation in progress">rebasing 3/7</span>', $html);
        self::assertStringNotContainsString('Detached HEAD at', $html);
        self::assertStringNotContainsString('<span class="value">detached</span>', $html);
        self::assertStringNotContainsString('<span class="label">Detached HEAD</span>', $html);
        // The branch has an upstream: say it comes back after the rebase, not that there is none.
        self::assertStringContainsString('is compared with its upstream again once the rebase is finished', $html);
        self::assertStringContainsString('<span>after the rebase</span>', $html);
        self::assertStringNotContainsString('no branch, so no upstream', $html);
    }

    public function testDetachedRebaseWithoutBranchNameStaysDetached(): void
    {
        $html = $this->render(new GitInfo('HEAD', 'abc1234', false, operation: new Operation(OperationKind::Rebase, 1, 2)));

        self::assertStringContainsString('<span class="label">Detached HEAD</span>', $html);
        self::assertStringContainsString('<span class="value">detached</span>', $html);
    }

    public function testNotRebasingKeepsTheBranchCardDuringAMerge(): void
    {
        $html = $this->render(new GitInfo('main', 'abc1234', false, operation: new Operation(OperationKind::Merge, target: 'other')));

        self::assertStringContainsString('<span class="label">Branch</span>', $html);
        self::assertStringContainsString('<span class="value">main</span>', $html);
    }

    public function testOnlyPushedCommitsAreLinked(): void
    {
        $html = $this->render($this->linkedInfo());

        $url = 'https://github.com/o/r/commit/' . str_repeat('b', 40);
        self::assertStringContainsString('<a href="' . $url . '" target="_blank" rel="noopener noreferrer"><code>bbbbbbb</code></a>', $html);
        // The unpushed HEAD commit would be a 404: plain text.
        self::assertStringContainsString('<span class="git-graph-hash"><code>aaaaaaa</code></span>', $html);
        self::assertSame(1, substr_count($html, '/commit/'));
    }

    public function testTheUpstreamBadgeLinksToTheRemoteBranch(): void
    {
        $html = $this->render($this->linkedInfo());

        self::assertStringContainsString('<span class="git-badge git-badge-upstream"><a href="https://github.com/o/r/tree/feature/x" target="_blank" rel="noopener noreferrer">origin/feature/x</a></span>', $html);
        self::assertStringContainsString('Pushed commits link to <a href="https://github.com/o/r" target="_blank" rel="noopener noreferrer">GitHub</a>.', $html);
    }

    public function testEveryExternalLinkOpensInANewTabWithoutOpenerAccess(): void
    {
        $html = $this->render($this->linkedInfo(behind: 2));

        $links = substr_count($html, '<a href="https://');
        self::assertGreaterThanOrEqual(4, $links);
        self::assertSame($links, substr_count($html, ' target="_blank" rel="noopener noreferrer">'));
        self::assertSame(0, preg_match('#<a href="(?!https://)#', $html));
    }

    public function testNoLinkWithoutARemote(): void
    {
        $html = $this->render($this->linkedInfo(remote: false, behind: 2));

        self::assertStringNotContainsString('<a href', $html);
        self::assertStringNotContainsString('_blank', $html);
        self::assertStringContainsString('<span class="git-badge git-badge-upstream">origin/feature/x</span>', $html);
        self::assertStringContainsString('<span class="git-graph-hash"><code>bbbbbbb</code></span>', $html);
        self::assertStringNotContainsString('Pushed commits link to', $html);
        self::assertStringNotContainsString('Compare on', $html);
    }

    public function testCompareLinkWhenBehindAndNotAhead(): void
    {
        $html = $this->render($this->linkedInfo(behind: 2));

        self::assertStringContainsString('<a href="https://github.com/o/r/compare/' . str_repeat('a', 40) . '...feature/x" target="_blank" rel="noopener noreferrer">Compare on GitHub</a>', $html);
        self::assertStringContainsString('Nothing to push.', $html);
    }

    public function testNoCompareLinkWhenInSyncOrAhead(): void
    {
        self::assertStringNotContainsString('Compare on', $this->render($this->linkedInfo()));

        $ahead = [new UnpushedCommit('aaaaaaa', 'local', 'Ada', new \DateTimeImmutable('2026-08-14T10:00:00+00:00'))];
        self::assertStringNotContainsString('Compare on', $this->render($this->linkedInfo(behind: 2, unpushed: $ahead)));
    }

    public function testCompareLinkNamesTheProvider(): void
    {
        self::assertStringContainsString('Compare on GitLab</a>', $this->render($this->linkedInfo(behind: 1, provider: GitProvider::GitLab)));
        self::assertStringNotContainsString('Compare on', $this->render($this->linkedInfo(behind: 1, provider: GitProvider::Bitbucket)));
    }

    public function testCredentialsOfTheRemoteUrlNeverReachTheOutput(): void
    {
        $remote = (new RemoteUrlParser())->parse('https://octocat:s3cr3t-token@github.com/o/r.git');
        self::assertNotNull($remote);

        $html = $this->render($this->linkedInfo(behind: 2, repository: $remote));

        self::assertStringContainsString('https://github.com/o/r/commit/', $html);
        self::assertStringNotContainsString('s3cr3t', $html);
        self::assertStringNotContainsString('octocat', $html);
        self::assertStringNotContainsString('@github.com', $html);
    }

    public function testVersionAndStashesAreShownAsCardsAndInTheToolbarTooltip(): void
    {
        $html = $this->render(new GitInfo('main', 'abc1234', false, describe: '0.4.0-3-g2897d71', stashCount: 2));

        self::assertStringContainsString('<span class="value"><code>0.4.0-3-g2897d71</code></span>', $html);
        self::assertStringContainsString('<span class="label">Version</span>', $html);
        self::assertStringContainsString('<span class="value">2</span>' . "\n" . '                    <span class="label">Stashes</span>', $html);
        self::assertStringContainsString('<b>Version</b>' . "\n" . '                    <span>0.4.0-3-g2897d71</span>', $html);
        self::assertStringContainsString('<b>Stashes</b>' . "\n" . '                    <span>2</span>', $html);
    }

    public function testVersionAndStashesAreHiddenWhenThereIsNothingToShow(): void
    {
        $html = $this->render(new GitInfo('main', 'abc1234', false));

        self::assertStringNotContainsString('Version', $html);
        self::assertStringNotContainsString('Stashes', $html);
    }

    public function testAProfileCollectedBeforeTheLinksExistedStillRenders(): void
    {
        $repository = $this->createStub(GitRepositoryInterface::class);
        $collector = new GitDataCollector($repository);
        (new \ReflectionProperty(GitDataCollector::class, 'data'))->setValue($collector, [
            'available' => true,
            'branch' => 'main',
            'shortCommit' => 'abc1234',
            'hasUpstream' => true,
            'upstreamRef' => 'origin/main',
            'graphLaneCount' => 1,
            'graphRows' => [['shortHash' => 'abc1234', 'subject' => 'init', 'author' => 'Ada', 'date' => '2026-08-14T10:00:00+00:00', 'lane' => 0, 'incomingLanes' => [], 'segments' => [], 'isHead' => true, 'isUpstream' => true, 'isPushed' => true]],
        ]);

        $html = $this->renderCollector($collector);

        self::assertStringContainsString('<code>abc1234</code>', $html);
        self::assertStringNotContainsString('<a href', $html);
    }

    /**
     * @param list<UnpushedCommit> $unpushed
     */
    private function linkedInfo(bool $remote = true, int $behind = 0, array $unpushed = [], GitProvider $provider = GitProvider::GitHub, ?RemoteRepository $repository = null): GitInfo
    {
        $head = str_repeat('a', 40);
        $pushed = str_repeat('b', 40);

        return new GitInfo(
            'feature/x',
            'aaaaaaa',
            false,
            hasUpstream: true,
            unpushedCommits: $unpushed,
            graphCommits: [
                new GraphCommit($head, 'aaaaaaa', [$pushed], 'local work', 'Ada', new \DateTimeImmutable('2026-08-14T10:00:00+00:00'), isHead: true),
                new GraphCommit($pushed, 'bbbbbbb', [], 'init', 'Ada', new \DateTimeImmutable('2026-08-14T09:00:00+00:00'), isUpstream: true, isPushed: true),
            ],
            upstreamRef: 'origin/feature/x',
            behindCount: $behind,
            remote: $remote ? ($repository ?? new RemoteRepository($provider, 'https://github.com/o/r')) : null,
            upstreamBranch: 'feature/x',
            headHash: $head,
        );
    }

    private function render(GitInfo $info, ?TraceableGitCommandRunner $tracer = null, ?\Closure $readGit = null): string
    {
        $repository = $this->createStub(GitRepositoryInterface::class);
        $repository->method('read')->willReturnCallback(static function () use ($info, $readGit): GitInfo {
            $readGit?->__invoke();

            return $info;
        });

        $collector = new GitDataCollector($repository, tracer: $tracer);
        $collector->collect(new Request(), new Response());
        $collector->lateCollect();

        return $this->renderCollector($collector);
    }

    private function renderCollector(GitDataCollector $collector): string
    {
        $bundleLoader = new FilesystemLoader();
        $bundleLoader->addPath(\dirname(__DIR__, 2) . '/templates', 'GitProfiler');

        $layoutStub = new ArrayLoader([
            '@WebProfiler/Profiler/layout.html.twig' => '{% block toolbar %}{% endblock %}{% block menu %}{% endblock %}{% block panel %}{% endblock %}',
            '@WebProfiler/Profiler/toolbar_item.html.twig' => '<div class="sf-toolbar-block sf-toolbar-status-{{ status|default(\'normal\') }}">{{ icon }}{{ text }}</div>',
        ]);

        $twig = new Environment(new ChainLoader([$layoutStub, $bundleLoader]));

        return $twig->render('@GitProfiler/Collector/git.html.twig', ['collector' => $collector]);
    }
}
