<?php

namespace Tests\Feature;

use App\Services\SelfUpdater;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/**
 * With no Composer on the server, the self-updater fetches composer.phar
 * itself — only from an installer matching getcomposer.org's signature.
 */
class SelfUpdateComposerTest extends TestCase
{
    /** Stands in for getcomposer.org's installer: writes the requested phar. */
    private const INSTALLER = <<<'PHP'
<?php
$options = getopt('', ['install-dir:', 'filename:', 'quiet']);
file_put_contents($options['install-dir'].'/'.$options['filename'], 'phar');
PHP;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        File::delete(storage_path('app/self-update/composer.phar'));
    }

    protected function tearDown(): void
    {
        File::delete(storage_path('app/self-update/composer.phar'));

        parent::tearDown();
    }

    public function test_it_installs_composer_from_a_verified_installer(): void
    {
        Http::fake([
            'composer.github.io/installer.sig' => Http::response(hash('sha384', self::INSTALLER)),
            'getcomposer.org/installer' => Http::response(self::INSTALLER),
        ]);

        $phar = $this->download();

        $this->assertFileExists($phar);
        $this->assertSame('phar', File::get(storage_path('app/self-update/composer.phar')));
        $this->assertFileDoesNotExist(storage_path('app/self-update/composer-setup.php'));
    }

    public function test_it_refuses_an_installer_whose_signature_does_not_match(): void
    {
        Http::fake([
            'composer.github.io/installer.sig' => Http::response(hash('sha384', 'something else')),
            'getcomposer.org/installer' => Http::response(self::INSTALLER),
        ]);

        try {
            $this->download();
            $this->fail('A tampered installer was run.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('signature did not match', $e->getMessage());
        }

        $this->assertFileDoesNotExist(storage_path('app/self-update/composer.phar'));
    }

    private function download(): string
    {
        return (new ReflectionMethod(SelfUpdater::class, 'downloadComposer'))->invoke(app(SelfUpdater::class));
    }
}
