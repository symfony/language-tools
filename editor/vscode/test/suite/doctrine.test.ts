import * as assert from 'node:assert/strict';
import * as vscode from 'vscode';
import {
    completions,
    hoverText,
    labels,
    locationPaths,
    open,
    positionAfter,
    positionInside,
    TestCase,
    waitFor,
    withTemporaryDocument,
} from './support';

export const doctrineTests: TestCase[] = [
    ['Doctrine entity fields and repositories complete and navigate mappings', testDoctrineLanguageFeatures],
];

async function testDoctrineLanguageFeatures(): Promise<void> {
    const contents = `<?php

use App\\Entity\\Product;
use App\\Repository\\ProductRepository;
use Symfony\\Bridge\\Doctrine\\Form\\Type\\EntityType;

function configure($builder, ProductRepository $products): void
{
    $builder->add('product', EntityType::class, [
        'class' => Product::class,
        'choice_label' => 'name',
    ]);
    $products->findBy(['name' => 'Symfony']);
}
`;
    await withTemporaryDocument('DoctrineConsumer.php', contents, async (document) => {
        const formCompletionPosition = positionAfter(document, "'choice_label' => 'na");
        const formItems = await waitFor(
            () => completions(document.uri, formCompletionPosition),
            (result) => labels(result).includes('name'),
            'Doctrine EntityType field completion',
        );
        assert.ok(labels(formItems).includes('name'));

        const repositoryCompletionPosition = positionAfter(document, "findBy(['na");
        const repositoryItems = await waitFor(
            () => completions(document.uri, repositoryCompletionPosition),
            (result) => labels(result).includes('name'),
            'Doctrine repository field completion',
        );
        assert.ok(labels(repositoryItems).includes('name'));

        const fieldPosition = positionInside(document, "findBy(['name'", 'name');
        const hovers = await vscode.commands.executeCommand<vscode.Hover[]>('vscode.executeHoverProvider', document.uri, fieldPosition);
        assert.match(hoverText(hovers), /Doctrine field: `App\\Entity\\Product::\$name`/);
        const definitions = await vscode.commands.executeCommand<Array<vscode.Location | vscode.LocationLink>>('vscode.executeDefinitionProvider', document.uri, fieldPosition);
        assert.ok(locationPaths(definitions).some((item) => item.endsWith('/src/Entity/Product.php')));
        const references = await vscode.commands.executeCommand<Array<vscode.Location | vscode.LocationLink>>('vscode.executeReferenceProvider', document.uri, fieldPosition);
        assert.ok(locationPaths(references).includes(document.uri.path));
    });

    const entity = await open('src/Entity/Product.php');
    const repositoryPosition = positionInside(entity, 'ProductRepository::class', 'ProductRepository');
    const repositoryDefinitions = await vscode.commands.executeCommand<Array<vscode.Location | vscode.LocationLink>>('vscode.executeDefinitionProvider', entity.uri, repositoryPosition);
    assert.ok(locationPaths(repositoryDefinitions).some((item) => item.endsWith('/src/Repository/ProductRepository.php')));
    const entityLenses = await waitFor(
        () => vscode.commands.executeCommand<vscode.CodeLens[]>('vscode.executeCodeLensProvider', entity.uri),
        (result) => (result ?? []).some((item) => item.command?.title.includes('Repository:')),
        'Doctrine entity repository code lens',
    );
    assert.ok(entityLenses.some((item) => item.command?.title.includes('ProductRepository')));

    const repository = await open('src/Repository/ProductRepository.php');
    const entityPosition = positionInside(repository, 'Product::class', 'Product');
    const entityDefinitions = await vscode.commands.executeCommand<Array<vscode.Location | vscode.LocationLink>>('vscode.executeDefinitionProvider', repository.uri, entityPosition);
    assert.ok(locationPaths(entityDefinitions).some((item) => item.endsWith('/src/Entity/Product.php')));
}
