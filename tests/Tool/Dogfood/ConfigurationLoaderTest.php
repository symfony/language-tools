<?php

namespace Symfony\Lsp\Tests\Tool\Dogfood;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Lsp\Tools\Dogfood\ConfigurationException;
use Symfony\Lsp\Tools\Dogfood\ConfigurationLoader;

final class ConfigurationLoaderTest extends TestCase
{
    private const REVISION = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = Path::join(sys_get_temp_dir(), 'symfony-lsp-dogfood-'.bin2hex(random_bytes(8)));
        (new Filesystem())->mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testLoadsMinimalConfigurationWithDefaults(): void
    {
        $this->write('kimai.json', [
            'version' => 1,
            'repository' => 'https://github.com/kimai/kimai.git',
            'revision' => self::REVISION,
            'setup' => 'composer',
            'ci' => true,
        ]);

        $configurations = (new ConfigurationLoader())->load([$this->directory], ['composer']);

        self::assertCount(1, $configurations);
        $configuration = $configurations[0];
        self::assertSame('kimai', $configuration->name);
        self::assertSame('https://github.com/kimai/kimai.git', $configuration->repository);
        self::assertSame(self::REVISION, $configuration->revision);
        self::assertNull($configuration->directory);
        self::assertSame('dev', $configuration->environment);
        self::assertSame('composer', $configuration->setup);
        self::assertTrue($configuration->ci);
        self::assertSame(120, $configuration->indexTimeout);
        self::assertSame(10, $configuration->requestTimeout);
        self::assertSame(['src', 'templates', 'config'], $configuration->probeRoots);
        self::assertSame(1, $configuration->probesPerCategory);
    }

    public function testLoadsFullConfiguration(): void
    {
        $this->write('twig.symfony.com.json', [
            'version' => 1,
            'repository' => 'git@github.com:symfonycorp/oss-websites',
            'revision' => self::REVISION,
            'directory' => 'twig.symfony.com',
            'environment' => 'test',
            'setup' => 'composer',
            'ci' => false,
            'indexTimeout' => 300,
            'requestTimeout' => 20,
            'probeRoots' => ['project-base/src', 'project-base/templates'],
            'probesPerCategory' => 3,
            'allowPlugins' => ['contao/manager-plugin'],
        ]);

        $configuration = (new ConfigurationLoader())->load([$this->directory], ['composer'])[0];

        self::assertSame('twig.symfony.com', $configuration->name);
        self::assertSame('twig.symfony.com', $configuration->directory);
        self::assertSame('test', $configuration->environment);
        self::assertFalse($configuration->ci);
        self::assertSame(300, $configuration->indexTimeout);
        self::assertSame(20, $configuration->requestTimeout);
        self::assertSame(['project-base/src', 'project-base/templates'], $configuration->probeRoots);
        self::assertSame(3, $configuration->probesPerCategory);
        self::assertSame(['contao/manager-plugin'], $configuration->allowPlugins);
    }

    public function testDiscoversAPinnedLockFileNextToTheConfiguration(): void
    {
        $this->write('kimai.json', $this->valid());
        file_put_contents(Path::join($this->directory, 'kimai.lock'), '{}');

        $configuration = (new ConfigurationLoader())->load([$this->directory], ['composer'])[0];

        self::assertSame(Path::join($this->directory, 'kimai.lock'), $configuration->lockFile);
    }

    public function testHasNoLockFileWithoutASibling(): void
    {
        $this->write('kimai.json', $this->valid());

        self::assertNull((new ConfigurationLoader())->load([$this->directory], ['composer'])[0]->lockFile);
    }

    public function testSortsProjectsByName(): void
    {
        $this->write('zebra.json', $this->valid());
        $this->write('alpha.json', $this->valid());

        $configurations = (new ConfigurationLoader())->load([$this->directory], ['composer']);

        self::assertSame(['alpha', 'zebra'], array_column($configurations, 'name'));
    }

    public function testRejectsDuplicateProjectsAcrossDirectories(): void
    {
        $other = Path::join($this->directory, 'other');
        (new Filesystem())->mkdir($other);
        $this->write('kimai.json', $this->valid());
        $this->write('other/kimai.json', $this->valid());

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Duplicate project "kimai"');

        (new ConfigurationLoader())->load([$this->directory, $other], ['composer']);
    }

    public function testRejectsMissingDirectory(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('does not exist');

        (new ConfigurationLoader())->load([Path::join($this->directory, 'missing')], ['composer']);
    }

    public function testRejectsInvalidJson(): void
    {
        file_put_contents(Path::join($this->directory, 'kimai.json'), '{');

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Invalid JSON');

        (new ConfigurationLoader())->load([$this->directory], ['composer']);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('invalidConfigurationProvider')]
    public function testRejectsInvalidConfiguration(array $overrides, string $message, string $file = 'kimai.json'): void
    {
        $this->write($file, array_merge($this->valid(), $overrides));

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage($message);

        (new ConfigurationLoader())->load([$this->directory], ['composer']);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string, 2?: string}>
     */
    public static function invalidConfigurationProvider(): iterable
    {
        yield 'invalid name' => [[], 'Invalid project name', 'Kimai!.json'];
        yield 'unknown key' => [['command' => 'rm -rf /'], 'Unknown key "command"'];
        yield 'missing version' => [['version' => null], '"version": 1'];
        yield 'unsupported version' => [['version' => 2], '"version": 1'];
        yield 'missing repository' => [['repository' => null], 'non-empty "repository"'];
        yield 'absolute repository' => [['repository' => '/srv/app'], 'must be a remote Git URL'];
        yield 'home repository' => [['repository' => '~/app'], 'must be a remote Git URL'];
        yield 'windows repository' => [['repository' => 'C:/app'], 'must be a remote Git URL'];
        yield 'file repository' => [['repository' => 'file:///srv/app'], 'must be a remote Git URL'];
        yield 'relative repository' => [['repository' => 'tui.symfony.com'], 'must be a remote Git URL'];
        yield 'credential repository' => [['repository' => 'https://user:secret@github.com/a/b.git'], 'must not embed credentials'];
        yield 'short revision' => [['revision' => 'abc123'], 'full lowercase commit hash'];
        yield 'branch revision' => [['revision' => 'main'], 'full lowercase commit hash'];
        yield 'absolute directory' => [['directory' => '/srv'], 'relative path inside the repository'];
        yield 'parent directory' => [['directory' => '../other'], 'relative path inside the repository'];
        yield 'invalid environment' => [['environment' => 'dev; rm'], 'simple environment name'];
        yield 'unknown setup' => [['setup' => 'shell'], 'The "setup" in'];
        yield 'missing ci' => [['ci' => null], 'boolean "ci"'];
        yield 'string ci' => [['ci' => 'yes'], 'boolean "ci"'];
        yield 'zero index timeout' => [['indexTimeout' => 0], 'between 1 and 900'];
        yield 'huge index timeout' => [['indexTimeout' => 1000], 'between 1 and 900'];
        yield 'zero request timeout' => [['requestTimeout' => 0], 'between 1 and 120'];
        yield 'huge request timeout' => [['requestTimeout' => 600], 'between 1 and 120'];
        yield 'empty probe roots' => [['probeRoots' => []], 'non-empty list of relative paths'];
        yield 'absolute probe root' => [['probeRoots' => ['/srv']], 'non-empty list of relative paths'];
        yield 'parent probe root' => [['probeRoots' => ['../other']], 'non-empty list of relative paths'];
        yield 'comma probe root' => [['probeRoots' => ['src,templates']], 'non-empty list of relative paths'];
        yield 'zero probes per category' => [['probesPerCategory' => 0], 'between 1 and 10'];
        yield 'huge probes per category' => [['probesPerCategory' => 100], 'between 1 and 10'];
        yield 'plugin map' => [['allowPlugins' => ['contao/manager-plugin' => true]], 'list of Composer plugin names'];
        yield 'invalid plugin name' => [['allowPlugins' => ['not a plugin']], 'list of Composer plugin names'];
        yield 'invalid platform requirement' => [['ignorePlatformRequirements' => ['symfony/imap']], 'platform package names'];
        yield 'absolute setup change' => [['setupChanges' => ['/etc/passwd']], 'list of relative paths'];
        yield 'parent setup change' => [['setupChanges' => ['../outside']], 'list of relative paths'];
    }

    /**
     * @return array<string, mixed>
     */
    private function valid(): array
    {
        return [
            'version' => 1,
            'repository' => 'https://github.com/kimai/kimai.git',
            'revision' => self::REVISION,
            'setup' => 'composer',
            'ci' => true,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function write(string $name, array $data): void
    {
        file_put_contents(Path::join($this->directory, $name), json_encode(array_filter($data, static fn ($value): bool => null !== $value), \JSON_THROW_ON_ERROR));
    }
}
