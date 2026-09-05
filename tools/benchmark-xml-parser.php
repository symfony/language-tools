<?php

use Symfony\Lsp\Parser\Xml\TolerantXmlParser;

require dirname(__DIR__).'/vendor/autoload.php';

$fixtures = [
    'services' => <<<'XML'
        <?xml version="1.0"?>
        <container xmlns="http://symfony.com/schema/dic/services">
            <services>
                <service id="App\Handler" class="App\Handler">
                    <argument type="service" id="logger"/>
                    <tag name="messenger.message_handler"/>
                </service>
            </services>
        </container>
        XML,
    'xliff' => <<<'XML'
        <!DOCTYPE xliff [<!ENTITY inert "ignored">]>
        <xliff xmlns="urn:oasis:names:tc:xliff:document:2.0" version="2.0">
            <file id="messages">
                <unit id="title" name="page.title">
                    <segment><source>page.title</source><target>Page <ph id="name"/> title</target></segment>
                </unit>
            </file>
        </xliff>
        XML,
    'incomplete' => <<<'XML'
        <container><broken value="unfinished
        <service id="recovered"><argument type="service" id="logger"/></service>
        XML,
];
$parser = new TolerantXmlParser();
$iterations = 1_000;
printf("fixture,bytes,events,diagnostics,mean_ms,peak_bytes\n");
foreach ($fixtures as $name => $source) {
    gc_collect_cycles();
    memory_reset_peak_usage();
    $startedAt = hrtime(true);
    $document = $parser->parse($source);
    for ($iteration = 1; $iteration < $iterations; ++$iteration) {
        $document = $parser->parse($source);
    }
    $elapsed = hrtime(true) - $startedAt;
    printf(
        "%s,%d,%d,%d,%.4f,%d\n",
        $name,
        strlen($source),
        count($document->events),
        count($document->diagnostics),
        $elapsed / 1_000_000 / $iterations,
        memory_get_peak_usage(true),
    );
}
