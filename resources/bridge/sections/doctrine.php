<?php

function symfonyLspBridgeDoctrineSection(SymfonyLspBridgeContext $context): ?array
{
    if (!interface_exists(Doctrine\Persistence\ManagerRegistry::class)) {
        return null;
    }
    $entities = [];
    $complete = false;
    try {
        $container = $context->kernel()->getContainer();
        if ($container->has('doctrine')) {
            $registry = $container->get('doctrine');
            if ($registry instanceof Doctrine\Persistence\ManagerRegistry) {
                foreach ($registry->getManagers() as $manager) {
                    foreach ($manager->getMetadataFactory()->getAllMetadata() as $metadata) {
                        $reflection = $metadata->getReflectionClass();
                        $fields = [];
                        foreach ($metadata->getFieldNames() as $name) {
                            $type = $metadata->getTypeOfField($name);
                            $fields[] = ['name' => $name, 'type' => is_string($type) ? $type : null, 'association' => false, 'targetEntity' => null];
                        }
                        foreach ($metadata->getAssociationNames() as $name) {
                            $fields[] = ['name' => $name, 'type' => null, 'association' => true, 'targetEntity' => $metadata->getAssociationTargetClass($name)];
                        }
                        $repositoryClass = $metadata instanceof Doctrine\ORM\Mapping\ClassMetadata ? $metadata->customRepositoryClassName : null;
                        $entities[] = [
                            'className' => $metadata->getName(),
                            'file' => $reflection->getFileName() ?: null,
                            'repositoryClass' => $repositoryClass,
                            'fields' => $fields,
                        ];
                    }
                }
                $complete = true;
            }
        }
    } catch (Throwable) {
        $context->addError('doctrine');
    }
    usort($entities, static fn (array $a, array $b): int => $a['className'] <=> $b['className']);

    return ['entities' => $entities, 'complete' => $complete];
}
