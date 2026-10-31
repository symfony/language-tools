Vim
===

Vim runs Symfony Language Tools through the `vim-lsp`_ plugin. Install the
standalone server and put ``symfony-lsp`` on ``PATH``; see
`installing the server`_. Linux and Apple Silicon macOS are supported.
Windows isn't: vim-lsp speaks to language servers over standard input and
output, which the Windows server can't do.

Enabling It
-----------

Install vim-lsp, then register the server in your ``vimrc``:

.. code-block:: vim

    augroup symfony_lsp
        autocmd!
        autocmd User lsp_setup call lsp#register_server({
            \ 'name': 'symfony-lsp',
            \ 'cmd': {server_info -> ['symfony-lsp']},
            \ 'root_uri': {server_info -> lsp#utils#path_to_uri(
            \     lsp#utils#find_nearest_parent_file_directory(
            \         lsp#utils#get_buffer_path(), 'composer.json'))},
            \ 'allowlist': ['php', 'twig', 'yaml', 'xml', 'json',
            \     'javascript', 'typescript'],
            \ 'initialization_options': {'workspaceTrust': v:true},
            \ 'workspace_config': {'symfonyLsp': {}},
            \ })
    augroup END

The server starts for those file types in the directory containing the
nearest ``composer.json``. Keep a general PHP language server registered
alongside it.

Configuration
-------------

Shared settings belong in ``.symfony-lsp.json``; see
`project configuration`_. Override them for Vim only in ``workspace_config``,
for example ``{'symfonyLsp': {'environment': 'test'}}``.

``workspaceTrust`` answers the trust question up front: ``v:true`` runs your
application, ``v:false`` never runs it. Without it, the server asks before
running your application. Set it to ``v:true`` only if you trust every
project you open in Vim.

``projectRoots`` and ``trace`` belong to ``initialization_options``, not
``workspace_config``. Restart Vim after changing them.

Set the server's memory limit through Vim's environment:

.. code-block:: vim

    let $SYMFONY_LSP_MEMORY_LIMIT = '4G'

Code Lenses
-----------

``:LspCodeLens`` lists the Symfony lenses of the current file. To open the
locations of the lens you pick in the quickfix list, register the command it
runs in the same ``augroup``:

.. code-block:: vim

    function! s:show_references(context) abort
        let l:items = []
        for l:location in a:context['command']['arguments'][2]
            call add(l:items, {
                \ 'filename': lsp#utils#uri_to_path(l:location['uri']),
                \ 'lnum': l:location['range']['start']['line'] + 1,
                \ 'col': l:location['range']['start']['character'] + 1,
                \ })
        endfor
        call setqflist(l:items)
        copen
    endfunction

    autocmd User lsp_setup call lsp#register_command(
        \ 'editor.action.showReferences', function('s:show_references'))

Troubleshooting
---------------

Run ``:LspStatus`` and confirm that ``symfony-lsp`` is running. Vim shows a
message when the application fails to boot; ``symfony-lsp check --verbose``
shows its cause. Set ``g:lsp_log_file`` to a path and ``trace`` to
``messages`` temporarily to log redacted protocol traffic.

.. _`vim-lsp`: https://github.com/prabirshrestha/vim-lsp
.. _`installing the server`: ../index.rst
.. _`project configuration`: ../configuration.rst
