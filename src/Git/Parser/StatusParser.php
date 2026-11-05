<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Git\Parser;

/**
 * Parses `git status --porcelain=v2 --branch -z --untracked-files=all`.
 *
 * @see https://git-scm.com/docs/git-status#_porcelain_format_version_2
 *
 * @internal
 */
final class StatusParser
{
    public function parse(string $output): StatusSnapshot
    {
        $oid = null;
        $branch = '';
        $upstream = null;
        $ahead = 0;
        $behind = 0;
        $hasTracking = false;
        $hasStaged = false;
        $hasUnstaged = false;
        $untracked = [];

        $fields = explode("\0", $output);
        $count = \count($fields);

        for ($i = 0; $i < $count; ++$i) {
            $record = $fields[$i];
            if ($record === '') {
                continue;
            }

            if (str_starts_with($record, '# ')) {
                [$key, $value] = explode(' ', substr($record, 2), 2) + [1 => ''];

                switch ($key) {
                    case 'branch.oid':
                        $oid = $value === '(initial)' ? null : $value;
                        break;
                    case 'branch.head':
                        $branch = $value === '(detached)' ? 'HEAD' : $value;
                        break;
                    case 'branch.upstream':
                        $upstream = $value;
                        break;
                    case 'branch.ab':
                        if (preg_match('/^\+(\d+) -(\d+)$/', $value, $matches) === 1) {
                            $hasTracking = true;
                            $ahead = (int) $matches[1];
                            $behind = (int) $matches[2];
                        }
                        break;
                }

                continue;
            }

            switch ($record[0]) {
                case '1':
                case '2':
                    $hasStaged = $hasStaged || ($record[2] ?? '.') !== '.';
                    $hasUnstaged = $hasUnstaged || ($record[3] ?? '.') !== '.';
                    if ($record[0] === '2') {
                        ++$i; // the next NUL-separated field is the original path
                    }
                    break;
                case 'u':
                    $hasStaged = true;
                    $hasUnstaged = true;
                    break;
                case '?':
                    $untracked[] = substr($record, 2);
                    break;
                default:
                    // '!' (ignored) and unknown record types are skipped.
                    break;
            }
        }

        return new StatusSnapshot($oid, $branch, $upstream, $ahead, $behind, $hasTracking, $hasStaged, $hasUnstaged, $untracked);
    }
}
