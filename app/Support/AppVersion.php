<?php

namespace App\Support;

/**
 * This license server's own version: the highest vX.Y.Z tag on the
 * checked-out commit, read straight from .git (no git process) — the same
 * scheme as the MIE app, where tagging is releasing.
 */
class AppVersion
{
    /**
     * The version, or null when the checkout isn't on a tagged commit.
     */
    public static function current(): ?string
    {
        return config('self_update.version_from_git', true)
            ? static::fromGit(base_path('.git'))
            : null;
    }

    /**
     * The checked-out commit, shortened — shown when there is no version.
     */
    public static function commit(): ?string
    {
        $head = static::resolveHead(base_path('.git'));

        return $head !== null ? substr($head, 0, 7) : null;
    }

    public static function fromGit(string $gitDir): ?string
    {
        $head = static::resolveHead($gitDir);

        if ($head === null) {
            return null;
        }

        $versions = [];

        foreach (static::tagRefs($gitDir) as $tag => $commit) {
            if ($commit === $head && preg_match('/^v(\d+\.\d+\.\d+)$/', $tag, $match) === 1) {
                $versions[] = $match[1];
            }
        }

        usort($versions, fn (string $a, string $b): int => version_compare($b, $a));

        return $versions[0] ?? null;
    }

    private static function resolveHead(string $gitDir): ?string
    {
        $head = trim((string) @file_get_contents($gitDir.'/HEAD'));

        if (! str_starts_with($head, 'ref: ')) {
            return preg_match('/^[0-9a-f]{40}$/', $head) === 1 ? $head : null;
        }

        $ref = substr($head, 5);
        $loose = trim((string) @file_get_contents($gitDir.'/'.$ref));

        if ($loose !== '') {
            return $loose;
        }

        return static::packedRefs($gitDir)[$ref] ?? null;
    }

    /**
     * @return array<string, string> tag name => commit
     */
    private static function tagRefs(string $gitDir): array
    {
        $tags = [];

        foreach (static::packedRefs($gitDir) as $name => $commit) {
            if (str_starts_with($name, 'refs/tags/')) {
                $tags[substr($name, 10)] = $commit;
            }
        }

        foreach (glob($gitDir.'/refs/tags/*') ?: [] as $file) {
            $tags[basename($file)] = trim((string) @file_get_contents($file));
        }

        return $tags;
    }

    /**
     * @return array<string, string> ref name => commit (peeled for annotated tags)
     */
    private static function packedRefs(string $gitDir): array
    {
        $refs = [];
        $last = null;

        foreach (@file($gitDir.'/packed-refs', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (str_starts_with($line, '^') && $last !== null) {
                $refs[$last] = substr($line, 1);
            } elseif (preg_match('/^([0-9a-f]{40}) (\S+)$/', $line, $match) === 1) {
                $refs[$match[2]] = $match[1];
                $last = $match[2];
            }
        }

        return $refs;
    }
}
