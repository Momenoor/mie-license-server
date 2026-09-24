<?php

namespace App\Services;

use App\Models\Release;
use Illuminate\Http\Client\PendingRequest;
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
        } elseif ($releasedAt !== null) {
            // GitHub's date for the release is authoritative — this also
            // corrects releases recorded earlier with a fallback date.
            $release->released_at = $releasedAt;
        }

        // Keep notes someone wrote by hand in the admin panel.
        if (filled($notes) && blank($release->notes)) {
            $release->notes = trim($notes);
        }

        $release->save();

        return ['release' => $release, 'created' => $created];
    }

    /**
     * Pulls the repository's GitHub releases (with their notes and GitHub's
     * publish date), then any vX.Y.Z tags that have no GitHub release
     * (without notes, dated by the commit the tag points at).
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

            // One extra request per tag for its commit date — only for tags
            // not recorded yet, to stay inside GitHub's anonymous limit of
            // 60 requests an hour.
            if ($this->isRecorded($tag['name'])) {
                continue;
            }

            $created += (int) $this->record($tag['name'], null, $this->commitDate($tag))['created'];
        }

        return $created;
    }

    private function isRecorded(string $tag): bool
    {
        return Release::query()
            ->where('product', config('releases.product'))
            ->where('version', ltrim($tag, 'vV'))
            ->exists();
    }

    /**
     * When the tagged commit was made, from the commit URL GitHub's tag
     * listing includes. Null if GitHub doesn't answer.
     *
     * @param  array<string, mixed>  $tag
     */
    private function commitDate(array $tag): ?Carbon
    {
        $url = $tag['commit']['url'] ?? null;

        if (! is_string($url) || ! str_starts_with($url, 'https://api.github.com/')) {
            return null;
        }

        $response = $this->request()->get($url);
        $date = $response->successful()
            ? ($response->json('commit.committer.date') ?? $response->json('commit.author.date'))
            : null;

        return is_string($date) ? Carbon::parse($date) : null;
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
        $response = $this->request()
            ->get('https://api.github.com/repos/'.config('releases.github_repo')."/{$endpoint}", ['per_page' => 100]);

        if (! $response->successful()) {
            throw new RuntimeException("GitHub {$endpoint} request failed ({$response->status()}): ".$response->json('message', ''));
        }

        return $response->json();
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->when(config('releases.github_token'), fn (PendingRequest $http) => $http->withToken(config('releases.github_token')))
            ->timeout(15);
    }
}
