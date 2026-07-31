<?php

namespace Symfony\Lsp\Tests\Feature\Security;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Feature\Security\ProjectSecuritySnapshotLoader;
use Symfony\Lsp\Feature\Security\SecurityIndexRegistry;
use Symfony\Lsp\Project\Project;

final class ProjectSecuritySnapshotLoaderTest extends TestCase
{
    public function testLoadsRuntimeMetadata(): void
    {
        $indexes = new SecurityIndexRegistry();
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        (new ProjectSecuritySnapshotLoader($indexes))->load($project, ['sections' => ['security' => [
            'complete' => true,
            'firewalls' => [['name' => 'main', 'provider' => 'users', 'enabled' => true, 'stateless' => false, 'lazy' => true, 'authenticators' => ['App\\Security\\Authenticator']]],
            'providers' => [['name' => 'users', 'type' => 'entity']],
            'roles' => [['name' => 'ROLE_ADMIN', 'inheritedRoles' => ['ROLE_USER']]],
            'voters' => [['class' => 'App\\Security\\PostVoter']],
        ]]]);

        $index = $indexes->forProject($project);
        self::assertTrue($index->isComplete());
        self::assertSame('users', $index->firewall('main')?->provider());
        self::assertSame(['App\\Security\\Authenticator'], $index->firewall('main')->authenticators());
        self::assertSame('entity', $index->provider('users')?->type());
        self::assertSame(['ROLE_USER'], $index->role('ROLE_ADMIN')?->inheritedRoles());
        self::assertSame('App\\Security\\PostVoter', $index->voters()[0]->className());
    }
}
