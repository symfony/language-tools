# Third-Party Notices

Symfony Language Tools distributions include third-party software under the
licenses identified below. The corresponding license texts are available under
`THIRD_PARTY_LICENSES/`.

## PHP Language Server

The language server includes the production Composer packages recorded in
`composer.lock`. Their license texts are stored by package name under
`THIRD_PARTY_LICENSES/php/`. These packages are licensed under the MIT License.
The generated Composer runtime is also licensed under the MIT License.

## Standalone PHP Runtime

The standalone executables combine the language server with a static PHP
runtime built from source with static-php-cli, including the Tree-sitter
parser extension.

| Component | License |
| --- | --- |
| PHP 8.4 series | PHP License 3.01 |
| phpmicro | Apache License 2.0 |
| GNU libiconv 1.19 | GNU Lesser General Public License 2.1 |
| zlib 1.3.2 | zlib License |

Corresponding source code is available from the
[PHP releases](https://www.php.net/releases/),
[phpmicro](https://github.com/static-php/phpmicro),
[GNU libiconv](https://www.gnu.org/software/libiconv/) and
[zlib](https://github.com/madler/zlib) projects.

## Tree-sitter Parser

The Tree-sitter parser ships compiled into the server binaries.

| Component | Revision | License |
| --- | --- | --- |
| tree-sitter | `64402de2857cc197ecc4ca3bc144ea91fda7e72e` | MIT License |
| tree-sitter-twig | `2208d2a3c3ee7ef378e97df2e51c18feb7ee9dfc` | MIT License |
| tree-sitter-yaml | `a1c4812a73ec5e089de8e441fdea3a921e8d5079` | MIT License |
| Unicode data used by tree-sitter | Unicode License V3 |

## VS Code Extension

The VS Code extension includes these production npm packages:

| Package | License |
| --- | --- |
| balanced-match | MIT License |
| brace-expansion | MIT License |
| minimatch | Blue Oak Model License 1.0.0 |
| semver | ISC License |
| vscode-jsonrpc | MIT License |
| vscode-languageclient | MIT License |
| vscode-languageserver-protocol | MIT License |
| vscode-languageserver-textdocument | MIT License |
| vscode-languageserver-types | MIT License |
