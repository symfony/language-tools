import * as fs from 'node:fs';
import * as path from 'node:path';
import { runTests } from '@vscode/test-electron';

async function main(): Promise<void> {
    const extensionDevelopmentPath = path.resolve(__dirname, '..', '..');
    const repositoryPath = path.resolve(extensionDevelopmentPath, '..', '..');
    const fixturePath = path.join(repositoryPath, 'tests', 'Fixtures', 'RuntimeApplication');
    const testDataPath = path.join(extensionDevelopmentPath, '.vscode-test');
    const profilePath = path.join(testDataPath, 'profile');
    const workspacePath = path.join(testDataPath, 'messenger.code-workspace');

    if (!fs.existsSync(path.join(fixturePath, 'vendor', 'autoload.php'))) {
        throw new Error('Install the RuntimeApplication fixture dependencies before running VS Code tests.');
    }

    await fs.promises.rm(profilePath, { force: true, recursive: true });
    await fs.promises.mkdir(testDataPath, { recursive: true });
    await fs.promises.writeFile(workspacePath, JSON.stringify({
        folders: [{ path: fixturePath }],
        settings: {
            'symfonyLsp.trustWorkspace': true,
            'symfonyLsp.phpCommand': ['php'],
            'symfonyLsp.environment': 'test',
            'symfonyLsp.debug': true,
            'php.suggest.basic': false,
        },
    }, null, 2));

    await runTests({
        extensionDevelopmentPath: [
            extensionDevelopmentPath,
            path.join(extensionDevelopmentPath, 'test', 'fixtures', 'twig-language'),
        ],
        extensionTestsPath: path.join(extensionDevelopmentPath, 'out', 'test', 'suite', 'index.js'),
        cachePath: testDataPath,
        version: process.env.VSCODE_TEST_VERSION ?? '1.131.0',
        launchArgs: [
            workspacePath,
            '--disable-extensions',
            '--disable-gpu',
            `--extensions-dir=${path.join(profilePath, 'extensions')}`,
            `--user-data-dir=${path.join(profilePath, 'user-data')}`,
        ],
    });
}

main().catch((error: unknown) => {
    console.error(error);
    process.exit(1);
});
