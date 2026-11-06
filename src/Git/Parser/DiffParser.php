<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Git\Parser;

use JulienBohy\GitProfilerBundle\Git\ChangedFile;
use JulienBohy\GitProfilerBundle\Git\FileStage;
use JulienBohy\GitProfilerBundle\Git\FileStatus;

/**
 * Parses `git diff --raw --numstat -z -M`: raw records give the status (and the
 * old path of renames), numstat records give the added/deleted line counts.
 *
 * @internal
 */
final class DiffParser
{
    /**
     * @return list<ChangedFile>
     */
    public function parse(string $output, FileStage $stage): array
    {
        $fields = explode("\0", $output);
        $count = \count($fields);

        /** @var array<string, array{FileStatus, ?string}> $entries path => [status, old path] */
        $entries = [];
        /** @var array<string, array{int, int}> $counts path => [additions, deletions] */
        $counts = [];

        for ($i = 0; $i < $count; ++$i) {
            $field = $fields[$i];
            if ($field === '') {
                continue;
            }

            if ($field[0] === ':') {
                // ":<mode> <mode> <sha> <sha> <status>" — the status is the last token (e.g. "M", "R087").
                $letter = substr((string) strrchr($field, ' '), 1, 1);
                $oldPath = null;
                if ($letter === 'R' || $letter === 'C') {
                    $oldPath = $fields[++$i] ?? '';
                }
                $path = $fields[++$i] ?? '';

                $entries[$path] = [$this->status($letter), $letter === 'R' ? $oldPath : null];

                continue;
            }

            if (preg_match('/^(?<added>\d+|-)\t(?<deleted>\d+|-)\t(?<path>.*)/s', $field, $matches) === 1) {
                $path = $matches['path'];
                if ($path === '') {
                    // Rename: "<add>\t<del>\t" followed by the old and the new path fields.
                    ++$i;
                    $path = $fields[++$i] ?? '';
                }

                $counts[$path] = [(int) $matches['added'], (int) $matches['deleted']]; // "-" (binary) casts to 0
            }
        }

        $files = [];
        foreach ($entries as $path => [$status, $oldPath]) {
            $path = (string) $path; // numeric paths become integer array keys
            [$additions, $deletions] = $counts[$path] ?? [0, 0];
            $files[] = new ChangedFile($path, $status, $stage, $oldPath, $additions, $deletions);
        }

        return $files;
    }

    private function status(string $letter): FileStatus
    {
        return match ($letter) {
            'A' => FileStatus::Added,
            'D' => FileStatus::Deleted,
            'R' => FileStatus::Renamed,
            default => FileStatus::Modified,
        };
    }
}
