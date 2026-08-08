local references = require('symfony_lsp.references')

---@type vim.lsp.Config
return {
  cmd = { 'symfony-lsp' },
  filetypes = {
    'php',
    'twig',
    'yaml',
    'json',
    'xml',
    'javascript',
    'typescript',
    'env',
  },
  root_markers = { 'composer.json', '.git' },
  workspace_required = true,
  capabilities = {
    workspace = {
      didChangeWatchedFiles = {
        dynamicRegistration = true,
      },
    },
  },
  init_options = {
    phpCommand = { 'php' },
    consolePath = 'bin/console',
    environment = 'dev',
    debug = true,
    runtimeIndexing = true,
    projectRoots = {},
    trace = 'off',
  },
  settings = {
    symfonyLsp = {
      phpCommand = { 'php' },
      consolePath = 'bin/console',
      environment = 'dev',
      debug = true,
      runtimeIndexing = true,
      projectRoots = {},
      translationDiagnostics = false,
    },
  },
  commands = {
    ['editor.action.showReferences'] = references.show,
  },
}
