<?php

namespace Tests\Feature;

use App\Services\SelfUpdater;
use App\Support\AppVersion;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The self-updater's git steps against a real, throwaway repository — a
 * bare "origin" with tags v1.0.0 and v1.1.0, and a "server" checkout at
 * v1.0.0 whose composer.lock was rewritten on the server. This
 * repository's own checkout is never touched: base_path() points at the
 * throwaway one for the duration of each test.
 */
class SelfUpdateGitTest extends TestCase
{
    private string $root;

    private string $server;

    private string $originalBasePath;

    protected function setUp(): void
    {
        parent::setUp();

        if ((new ExecutableFinder)->find('git') === null) {
            $this->markTestSkipped('git is not installed.');
        }

        $this->root = str_replace('\\', '/', sys_get_temp_dir()).'/self-update-test-'.uniqid();
        $this->server = $this->root.'/server';
        $work = $this->root.'/work';

        File::ensureDirectoryExists($this->root);
        $this->git($this->root, ['init', '--bare', '-b', 'master', 'origin.git']);
        $this->git($this->root, ['clone', 'origin.git', 'work']);

        File::put($work.'/app.txt', "v1\n");
        File::put($work.'/composer.lock', "lock v1\n");
        $this->commitAndTag($work, 'v1.0.0');

        File::put($work.'/app.txt', "v2\n");
        File::put($work.'/composer.lock', "lock v2\n");
        $this->commitAndTag($work, 'v1.1.0');

        $this->git($work, ['push', 'origin', 'master', '--tags']);

        $this->git($this->root, ['clone', 'origin.git', 'server']);
        $this->git($this->server, ['-c', 'advice.detachedHead=false', 'checkout', 'v1.0.0']);
        File::put($this->server.'/composer.lock', "lock rewritten on the server\n");

        $this->originalBasePath = $this->app->basePath();
        $this->app->setBasePath($this->server);
    }

    protected function tearDown(): void
    {
        if (isset($this->originalBasePath)) {
            $this->app->setBasePath($this->originalBasePath);
        }

        if (isset($this->root)) {
            // Git marks pack files read-only, which File::deleteDirectory
            // can't remove on Windows.
            foreach (File::allFiles($this->root, true) as $file) {
                @chmod($file->getPathname(), 0666);
            }
            File::deleteDirectory($this->root);
        }

        parent::tearDown();
    }

    public function test_it_checks_out_the_tag_and_then_reports_its_version(): void
    {
        config(['self_update.version_from_git' => true]);
        $this->assertSame('1.0.0', AppVersion::current());

        $updater = app(SelfUpdater::class);
        $updater->start('1.1.0');

        $this->assertTrue($updater->runNextStep(), (string) $updater->state()['log']); // preflight

        // git's output reached the live log while preflight ran.
        $this->assertMatchesRegularExpression('/\b[0-9a-f]{40}\b/', $updater->liveOutput());

        $this->assertTrue($updater->runNextStep(), (string) $updater->state()['log']); // code

        $this->assertSame("v2\n", $this->read('app.txt'));
        $this->assertSame("lock v2\n", $this->read('composer.lock'), 'The server-rewritten lock file is replaced by the release\'s.');
        $this->assertSame('1.1.0', AppVersion::current());
    }

    public function test_preflight_refuses_to_overwrite_other_local_changes(): void
    {
        File::put($this->server.'/app.txt', "edited on the server\n");

        $updater = app(SelfUpdater::class);
        $updater->start('1.1.0');

        $this->assertFalse($updater->runNextStep());
        $this->assertStringContainsString('app.txt', $updater->state()['log']);
        $this->assertSame("edited on the server\n", $this->read('app.txt'));
    }

    public function test_preflight_fails_for_a_version_that_was_never_tagged(): void
    {
        $updater = app(SelfUpdater::class);
        $updater->start('9.9.9');

        $this->assertFalse($updater->runNextStep());
        $this->assertStringContainsString('v9.9.9', $updater->state()['log']);
    }

    private function read(string $file): string
    {
        return str_replace("\r\n", "\n", File::get($this->server.'/'.$file));
    }

    private function commitAndTag(string $dir, string $tag): void
    {
        $this->git($dir, ['add', '-A']);
        $this->git($dir, ['-c', 'user.name=Test', '-c', 'user.email=test@example.com', 'commit', '-m', $tag]);
        $this->git($dir, ['tag', $tag]);
    }

    /**
     * @param  list<string>  $arguments
     */
    private function git(string $cwd, array $arguments): void
    {
        (new Process(['git', '-c', 'core.autocrlf=false', ...$arguments], $cwd))->mustRun();
    }
}
