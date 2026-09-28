<?php

namespace App\Services;

use App\Models\Release;

/**
 * Keeps a product's published releases to the ones that matter: the newest
 * release is always published, and an older one stays published only while
 * some installation still runs it. Older releases nobody is on are
 * unpublished; older ones with installations are left as they are.
 *
 * Runs whenever the answer can change — a release is recorded, an
 * installation reports a different version — plus daily from the
 * scheduler for installations removed along with their license.
 */
class ReleasePublisher
{
    /**
     * @return array{published: list<string>, unpublished: list<string>}
     */
    public function sync(string $product): array
    {
        $changes = ['published' => [], 'unpublished' => []];

        $releases = Release::query()
            ->where('product', $product)
            ->get()
            ->sort(fn (Release $a, Release $b): int => version_compare($b->version, $a->version))
            ->values();

        foreach ($releases as $index => $release) {
            $shouldPublish = $index === 0 || ($release->is_published && $release->installationsCount() > 0);

            if ($release->is_published === $shouldPublish) {
                continue;
            }

            $release->update(['is_published' => $shouldPublish]);
            $changes[$shouldPublish ? 'published' : 'unpublished'][] = $release->version;
        }

        return $changes;
    }
}
