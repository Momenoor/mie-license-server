<?php

namespace App\Services;

use App\Models\Release;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Records releases announced by the app's repository — pushed by its
 * GitHub Action, or pulled from GitHub on demand. A new release is always
 * created unpublished; publishing stays a deliberate step in the admin
 * panel. An existing one only gets its notes filled in, never its
 * published state changed.
 */
class ReleaseIntake
{
    /**
     * @return array{release: Release, created: bool}
     */
    public function record(string $version, ?string $notes, ?Carbon $releasedAt = null, ?string $product = null): array
    {
        $version = ltrim(trim($version), 'vV');

        if (preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
            throw new RuntimeException("Not a release version: {$version}");
        }

        $release = Release::query()->firstOrNew([
            'product' => $product ?? config('releases.product'),
            'version' => $version,
        ]);

        $created = ! $release->exists;

        if ($created) {
            $release->is_published = false;
            $release->released_at = $releasedAt ?? now();
        }

        // Keep notes someone wrote by hand in the admin panel.
        if (filled($notes) && blank($release->notes)) {
            $release->notes = trim($notes);
        }

        $release->save();

        return ['release' => $release, 'created' => $created];
    }

    /**
     * Pulls the repository's GitHub releases (with their notes), then any
     * vX.Y.Z tags that have no GitHub release (without notes).
     *
     * @return int how many releases were newly created
     */
    public function syncFromGitHub(): int
    {
        $created = 0;
        $seen = [];

        foreach ($this->github('releases') as $release) {
            if (($release['draft'] ?? false) || ! $this->isVersionTag($release['tag_name'] ?? '')) {
                continue;
            }

            $seen[$release['tag_name']] = true;
            $created += (int) $this->record(
                $release['tag_name'],
                $release['body'] ?? null,
                isset($release['published_at']) ? Carbon::parse($release['published_at']) : null,
            )['created'];
        }

        foreach ($this->github('tags') as $tag) {
            if (isset($seen[$tag['name'] ?? '']) || ! $this->isVersionTag($tag['name'] ?? '')) {
                continue;
            }

            $created += (int) $this->record($tag['name'], null)['created'];
        }

        return $created;
    }

    private function isVersionTag(string $tag): bool
    {
        return preg_match('/^v?\d+\.\d+\.\d+$/', $tag) === 1;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function github(string $endpoint): array
    {
        $response = Http::acceptJson()
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->when(config('releases.github_token'), fn ($http) => $http->withToken(config('releases.github_token')))
            ->timeout(15)
            ->get('https://api.github.com/repos/'.config('releases.github_repo')."/{$endpoint}", ['per_page' => 100]);

        if (! $response->successful()) {
            throw new RuntimeException("GitHub {$endpoint} request failed ({$response->status()}): ".$response->json('message', ''));
        }

        return $response->json();
    }
}
