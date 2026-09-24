<?php

namespace App\Services;

use App\Support\AppVersion;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Updates this license server to one of its own repository's vX.Y.Z tags:
 * checks the tag out, installs its Composer dependencies, migrates and
 * clears caches — one step per call, so the System Update page runs each
 * as its own request and shows progress (a single request would outlive
 * typical shared-hosting timeouts). Ported from the MIE app's updater.
 *
 * No maintenance mode: the only public surface is the license API, and an
 * installation that misses a check-in during the update just keeps running
 * on its grace period.
 *
 * State lives in a JSON file under storage/app/self-update, so a reload
 * resumes where the update stopped.
 */
class SelfUpdater
{
    /**
     * @return array<string, string> step key => label, in run order
     */
    public function steps(): array
    {
        return [
            'preflight' => 'Check the server can update',
            'code' => 'Download the new version',
            'dependencies' => 'Install PHP dependencies',
            'database' => 'Update the database',
            'cleanup' => 'Clear caches',
        ];
    }

    /**
     * The newest vX.Y.Z tag on GitHub, with its GitHub Release notes if it
     * has one. Cached for 10 minutes (GitHub allows 60 anonymous requests
     * an hour); $fresh skips the cache.
     *
     * @return array{version: string, notes: string|null, url: string|null}|null
     */
    public function latestRelease(bool $fresh = false): ?array
    {
        if ($fresh) {
            Cache::forget('self-update:latest');
        }

        return Cache::remember('self-update:latest', 600, function (): ?array {
            $versions = collect($this->github('tags'))
                ->pluck('name')
                ->filter(fn ($name): bool => is_string($name) && preg_match('/^v\d+\.\d+\.\d+$/', $name) === 1)
                ->map(fn (string $name): string => substr($name, 1))
                ->sort(fn (string $a, string $b): int => version_compare($b, $a))
                ->values();

            if ($versions->isEmpty()) {
                return null;
            }

            $version = $versions->first();
            $release = $this->request()->get($this->repoUrl("releases/tags/v{$version}"));

            return [
                'version' => $version,
                'notes' => $release->successful() ? $release->json('body') : null,
                'url' => $release->successful() ? $release->json('html_url') : null,
            ];
        });
    }

    /**
     * @return array{version: string, completed: list<string>, failed: bool, log: string}|null
     */
    public function state(): ?array
    {
        $state = json_decode((string) @file_get_contents($this->statePath()), true);

        return is_array($state) ? $state : null;
    }

    public function start(string $version): void
    {
        $this->saveState(['version' => $version, 'completed' => [], 'failed' => false, 'log' => '']);
    }

    /**
     * runNextStep(), unless a step is already running — the page calls
     * again after a request the web server timed out while PHP carried on,
     * and must never start the same step twice.
     *
     * @return 'ran'|'stopped'|'busy'
     */
    public function runNextStepIfIdle(): string
    {
        try {
            $lock = Cache::lock('self-update:step', 1800);

            if (! $lock->get()) {
                return 'busy';
            }
        } catch (Throwable) {
            $lock = null; // Cache store without locks.
        }

        try {
            return $this->runNextStep() ? 'ran' : 'stopped';
        } finally {
            $lock?->release();
        }
    }

    /**
     * Runs the next pending step. Returns false once there is nothing left
     * or a step failed.
     */
    public function runNextStep(): bool
    {
        $state = $this->state();

        if ($state === null || $state['failed']) {
            return false;
        }

        $pending = array_values(array_diff(array_keys($this->steps()), $state['completed']));

        if ($pending === []) {
            return false;
        }

        $step = $pending[0];

        @set_time_limit(0);
        @ignore_user_abort(true);

        $log = '== '.$this->steps()[$step]." ==\n";
        @file_put_contents($this->liveLogPath(), $log);

        try {
            $log .= $this->runStep($step, $state['version']);
            $state['completed'][] = $step;
        } catch (Throwable $e) {
            $log .= $e->getMessage()."\n";
            $state['failed'] = true;
        }

        $state['log'] = mb_substr($state['log'].$this->stripColours($log)."\n", -20000);
        $this->saveState($state);

        // Once finished, nothing is left to resume.
        if (in_array('cleanup', $state['completed'], true)) {
            @unlink($this->statePath());
            Cache::forget('self-update:latest');
        }

        return ! $state['failed'];
    }

    public function retry(): void
    {
        $state = $this->state();

        if ($state !== null) {
            $state['failed'] = false;
            $this->saveState($state);
        }
    }

    /**
     * Give up on a failed update. Updating again stays possible.
     */
    public function abandon(): void
    {
        @unlink($this->statePath());
    }

    /**
     * The running step's output so far (its tail) — read by the System
     * Update page while a step runs.
     */
    public function liveOutput(int $maxLength = 8000): string
    {
        return mb_substr($this->stripColours((string) @file_get_contents($this->liveLogPath())), -$maxLength);
    }

    private function runStep(string $step, string $version): string
    {
        return match ($step) {
            'preflight' => $this->preflight($version),
            'code' => $this->checkout($version),
            'dependencies' => $this->composerInstall(),
            'database' => $this->artisan('migrate', ['--force' => true]),
            'cleanup' => $this->artisan('optimize:clear')."Updated to version {$version}.\n",
        };
    }

    private function preflight(string $version): string
    {
        if (! function_exists('proc_open')) {
            throw new RuntimeException('The proc_open function is disabled on this server, so it cannot run git or Composer.');
        }

        if (! is_dir(base_path('.git'))) {
            throw new RuntimeException('This server is not a git checkout, so it cannot be updated in place.');
        }

        $output = 'PHP: '.implode(' ', $this->php())."\n";
        $output .= 'Composer: '.implode(' ', $this->composer())."\n";
        $output .= $this->git(['fetch', '--tags', '--force', 'origin']);

        if (! $this->process([...$this->gitCommand(), 'rev-parse', '-q', '--verify', "refs/tags/v{$version}"])['ok']) {
            throw new RuntimeException("Release tag v{$version} was not found on the repository.");
        }

        $output .= "Found tag v{$version}.\n";

        // Local edits would be overwritten (or block the checkout). Only two
        // kinds are expected, and checkout() handles both: files Composer/npm
        // regenerate on the server, and the PHP-version handler hosts add to
        // .htaccess files (e.g. cPanel's MultiPHP Manager).
        $unexpected = array_filter(
            $this->changedFiles(),
            fn (string $file): bool => ! $this->isGenerated($file) && ! $this->isHtaccess($file),
        );

        if ($unexpected !== []) {
            throw new RuntimeException('These files were changed on the server and would be overwritten: '.implode(', ', $unexpected));
        }

        return $output;
    }

    private function checkout(string $version): string
    {
        $changed = $this->changedFiles();
        $generated = array_values(array_filter($changed, $this->isGenerated(...)));

        if ($generated !== []) {
            $this->git(['checkout', '--', ...$generated]);
        }

        if (is_dir(base_path('public/build'))) {
            $this->git(['clean', '-f', '-q', '--', 'public/build']);
        }

        // Each server-edited .htaccess: remember its PHP handler, back the
        // file up, and let the release's version replace it — without the
        // handler the host could fall back to an older PHP.
        $handlers = [];

        foreach (array_filter($changed, $this->isHtaccess(...)) as $file) {
            $local = (string) file_get_contents(base_path($file));
            $handlers[$file] = static::phpHandlerBlocks($local);

            file_put_contents($this->directory().'/'.str_replace('/', '_', $file).'-'.now()->format('Ymd-His'), $local);
            $this->git(['checkout', '--', $file]);
        }

        $output = $this->git(['-c', 'advice.detachedHead=false', 'checkout', "v{$version}"]);

        foreach ($handlers as $file => ['top' => $top, 'bottom' => $bottom]) {
            $release = str_replace("\r\n", "\n", (string) @file_get_contents(base_path($file)));
            file_put_contents(base_path($file), $top.$release.($bottom !== '' ? "\n".$bottom : ''));
            $output .= "Replaced {$file} with this release's, keeping its PHP handler (the previous file is saved in storage/app/self-update/).\n";
        }

        return $output;
    }

    /**
     * The PHP-version handler blocks in an .htaccess, split by where they
     * go back: cPanel's own marked block ("# php -- BEGIN cPanel-generated
     * handler" … "END") at the bottom, where MultiPHP Manager keeps it;
     * any other `<IfModule mime_module>` block with an AddHandler (and the
     * comment line above it) at the top.
     *
     * @return array{top: string, bottom: string}
     */
    public static function phpHandlerBlocks(string $htaccess): array
    {
        $htaccess = str_replace("\r\n", "\n", $htaccess);

        preg_match_all('/^# php -- BEGIN cPanel-generated handler.*?^# php -- END cPanel-generated handler[^\n]*\n?/ms', $htaccess, $cpanel);
        $rest = str_replace($cpanel[0], '', $htaccess);

        preg_match_all(
            '/(?:^#[^\n]*\n)?<IfModule mime_module>(?:(?!<\/IfModule>).)*?AddHandler(?:(?!<\/IfModule>).)*<\/IfModule>\s*/ms',
            $rest,
            $other,
        );

        $top = implode('', $other[0]);
        $bottom = implode('', $cpanel[0]);

        return [
            'top' => $top === '' ? '' : rtrim($top)."\n\n",
            'bottom' => $bottom === '' ? '' : rtrim($bottom)."\n",
        ];
    }

    private function isHtaccess(string $file): bool
    {
        return $file === '.htaccess' || str_ends_with($file, '/.htaccess');
    }

    private function composerInstall(): string
    {
        $result = $this->process(
            [...$this->composer(), 'install', '--no-dev', '--no-interaction', '--optimize-autoloader', '--no-ansi'],
            timeout: 1800,
        );

        if (! $result['ok'] || ! file_exists(base_path('vendor/autoload.php'))) {
            throw new RuntimeException($result['output']."\nComposer install failed.");
        }

        return $result['output'];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function artisan(string $command, array $arguments = []): string
    {
        $exitCode = Artisan::call($command, $arguments);
        $output = Artisan::output();

        if ($exitCode !== 0) {
            throw new RuntimeException($output."\n\"{$command}\" failed.");
        }

        return $output;
    }

    /**
     * Files the server's own Composer/npm runs rewrite, never edited by
     * hand — the release ships its own copy of each.
     */
    private function isGenerated(string $file): bool
    {
        return in_array($file, ['composer.lock', 'package-lock.json'], true)
            || preg_match('#^public/(js|css|fonts|vendor|build)/#', $file) === 1;
    }

    /**
     * @return list<string>
     */
    private function changedFiles(): array
    {
        // Porcelain lines are "XY path" — never trimmed before cutting.
        return array_values(array_filter(array_map(
            fn (string $line): string => trim(substr(rtrim($line, "\r"), 3)),
            explode("\n", $this->git(['status', '--porcelain', '--untracked-files=no'])),
        )));
    }

    /**
     * @param  list<string>  $arguments
     */
    private function git(array $arguments): string
    {
        $result = $this->process([...$this->gitCommand(), ...$arguments]);

        if (! $result['ok']) {
            throw new RuntimeException($result['output']."\ngit {$arguments[0]} failed.");
        }

        return $result['output'];
    }

    /**
     * @return list<string>
     */
    private function gitCommand(): array
    {
        $git = (new ExecutableFinder)->find('git');

        if ($git === null) {
            throw new RuntimeException('git was not found on this server.');
        }

        // Trust this one repository only, if the web user doesn't own it.
        return [$git, '-c', 'safe.directory='.str_replace('\\', '/', base_path())];
    }

    /**
     * The PHP command-line binary — under a web server PHP_BINARY is the
     * FastCGI/LiteSpeed handler, which can't run Composer.
     *
     * @return list<string>
     */
    private function php(): array
    {
        if (PHP_SAPI === 'cli' && PHP_BINARY !== '') {
            return [PHP_BINARY];
        }

        $version = PHP_MAJOR_VERSION.PHP_MINOR_VERSION;

        foreach ([
            "/opt/cpanel/ea-php{$version}/root/usr/bin/php",
            "/opt/alt/php{$version}/usr/bin/php",
            "/usr/local/bin/php{$version}",
            "/usr/bin/php{$version}",
        ] as $path) {
            if (is_executable($path)) {
                return [$path];
            }
        }

        $php = (new ExecutableFinder)->find('php');

        if ($php === null) {
            throw new RuntimeException('The PHP command-line binary was not found on this server.');
        }

        return [$php];
    }

    /**
     * @return list<string>
     */
    private function composer(): array
    {
        foreach ([base_path('composer.phar'), $this->downloadedComposerPath()] as $phar) {
            if (is_file($phar)) {
                return [...$this->php(), $phar];
            }
        }

        // The web server's PATH is often shorter than a login shell's, so
        // also look where cPanel and common setups install Composer.
        $home = getenv('HOME') ?: (function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['dir'] ?? '') : '');
        $composer = (new ExecutableFinder)->find('composer', null, array_filter([
            '/opt/cpanel/composer/bin',
            '/usr/local/bin',
            '/usr/bin',
            $home !== '' ? $home.'/bin' : null,
            $home !== '' ? $home.'/.composer/vendor/bin' : null,
        ]));

        if ($composer === null) {
            return [...$this->php(), $this->downloadComposer()];
        }

        return preg_match('/\.(bat|cmd|exe)$/i', $composer) === 1
            ? [$composer]
            : [...$this->php(), $composer];
    }

    /**
     * No Composer anywhere: fetch composer.phar the official way — the
     * installer, checked against the signature getcomposer.org publishes
     * — into storage (kept across updates, never committed).
     */
    private function downloadComposer(): string
    {
        $target = $this->downloadedComposerPath();
        $installer = $this->directory().'/composer-setup.php';

        $signature = trim((string) $this->request()->get('https://composer.github.io/installer.sig')->body());
        $script = $this->request()->get('https://getcomposer.org/installer')->body();

        if ($signature === '' || $script === '' || ! hash_equals($signature, hash('sha384', $script))) {
            throw new RuntimeException('Composer was not found on this server, and downloading it failed (installer missing or its signature did not match). Upload composer.phar to the license server\'s folder, next to artisan, and retry.');
        }

        file_put_contents($installer, $script);

        try {
            $result = $this->process([...$this->php(), $installer, '--quiet', '--install-dir='.dirname($target), '--filename='.basename($target)]);
        } finally {
            @unlink($installer);
        }

        if (! $result['ok'] || ! is_file($target)) {
            throw new RuntimeException($result['output']."\nComposer was not found on this server, and installing it failed.");
        }

        return $target;
    }

    private function downloadedComposerPath(): string
    {
        return $this->directory().'/composer.phar';
    }

    /**
     * @param  list<string>  $command
     * @return array{ok: bool, output: string}
     */
    private function process(array $command, int $timeout = 300): array
    {
        $env = [];

        // Web requests often run without HOME, which git and Composer need.
        if (getenv('HOME') === false || getenv('HOME') === '') {
            $home = storage_path('app/self-update/home');
            @mkdir($home, 0755, true);
            $env = ['HOME' => $home, 'COMPOSER_HOME' => $home.'/.composer'];
        }

        $process = new Process($command, base_path(), $env, null, $timeout);

        // Streamed to the live log as it arrives (see liveOutput()).
        $process->run(fn (string $type, string $buffer) => @file_put_contents($this->liveLogPath(), $buffer, FILE_APPEND));

        return [
            'ok' => $process->isSuccessful(),
            'output' => $process->getOutput().$process->getErrorOutput(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function github(string $endpoint): array
    {
        $response = $this->request()->get($this->repoUrl($endpoint), ['per_page' => 100]);

        if (! $response->successful()) {
            throw new RuntimeException("GitHub {$endpoint} request failed ({$response->status()}): ".$response->json('message', ''));
        }

        return $response->json();
    }

    private function repoUrl(string $endpoint): string
    {
        return 'https://api.github.com/repos/'.config('self_update.github_repo').'/'.$endpoint;
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->when(config('self_update.github_token'), fn (PendingRequest $http) => $http->withToken(config('self_update.github_token')))
            ->timeout(15);
    }

    private function stripColours(string $text): string
    {
        return (string) preg_replace('/\e\[[0-9;]*[A-Za-z]/', '', $text);
    }

    private function statePath(): string
    {
        return $this->directory().'/state.json';
    }

    private function liveLogPath(): string
    {
        return $this->directory().'/live.log';
    }

    private function directory(): string
    {
        $directory = storage_path('app/self-update');
        @mkdir($directory, 0755, true);

        return $directory;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function saveState(array $state): void
    {
        file_put_contents($this->statePath(), json_encode($state));
    }
}
