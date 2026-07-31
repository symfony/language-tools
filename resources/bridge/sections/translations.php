<?php

function bridgeTranslationsSection(SymfonyLspBridgeContext $context): ?array
{
    $items = [];
    if (interface_exists(Symfony\Component\Translation\TranslatorBagInterface::class)) {
        try {
            $kernel = $context->kernel();
            $container = $kernel->getContainer();
            if ($container->has('translator')) {
                $translator = $container->get('translator');
                if ($translator instanceof Symfony\Component\Translation\TranslatorBagInterface) {
                    $locales = method_exists($translator, 'getLocale') ? [$translator->getLocale()] : [];
                    if (method_exists($translator, 'getFallbackLocales')) {
                        array_push($locales, ...$translator->getFallbackLocales());
                    }
                    if ($container->hasParameter('kernel.enabled_locales')) {
                        $enabledLocales = $container->getParameter('kernel.enabled_locales');
                        if (is_array($enabledLocales)) {
                            array_push($locales, ...array_filter($enabledLocales, 'is_string'));
                        }
                    }
                    foreach (array_values(array_unique(array_filter($locales, 'is_string'))) as $locale) {
                        foreach ($translator->getCatalogue($locale)->all() as $domain => $messages) {
                            foreach (is_array($messages) ? $messages : [] as $key => $message) {
                                if (is_string($domain) && is_string($key) && is_string($message)) {
                                    $items[] = ['key' => $key, 'domain' => $domain, 'locale' => $locale, 'message' => $message];
                                }
                            }
                        }
                    }
                }
            }
        } catch (Throwable $error) {
            $context->addError('translations', $error->getMessage());
        }
    }
    usort($items, static fn (array $a, array $b): int => [$a['domain'], $a['key'], $a['locale']] <=> [$b['domain'], $b['key'], $b['locale']]);
    $section = [
        'complete' => true,
        'generation' => hash('sha256', json_encode($items, JSON_THROW_ON_ERROR)),
        'items' => $items,
        'resources' => [],
        'warnings' => [],
    ];

    return $section;
}
