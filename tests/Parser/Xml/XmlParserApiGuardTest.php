<?php

namespace Symfony\Lsp\Tests\Parser\Xml;

use PHPUnit\Framework\TestCase;

final class XmlParserApiGuardTest extends TestCase
{
    public function testSourceDoesNotUseGeneralPurposeXmlParserApis(): void
    {
        $root = \dirname(__DIR__, 3).'/src';
        $forbidden = '/\\b(?:DOMDocument|DOMXPath|SimpleXMLElement|XMLReader|Dom\\\\XMLDocument)\\b|\\b(?:simplexml_load_(?:file|string)|libxml_[A-Za-z_]+|xml_parser_create(?:_ns)?|xml_parse(?:_into_struct)?)\\s*\\(/';
        $violations = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || 'php' !== $file->getExtension()) {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            if (false !== $source && preg_match($forbidden, $source)) {
                $violations[] = substr($file->getPathname(), \strlen($root) + 1);
            }
        }

        self::assertSame([], $violations);
    }
}
