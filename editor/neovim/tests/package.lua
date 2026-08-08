if not vim.version.ge(vim.version(), { 0, 12, 0 }) then
  print('Neovim package test skipped')
  vim.cmd.qall({ bang = true })
  return
end

local source = assert(vim.env.SYMFONY_LSP_PLUGIN_SOURCE)
local revision = assert(vim.env.SYMFONY_LSP_PLUGIN_REVISION)
vim.pack.add({
  {
    src = source,
    name = 'symfony-lsp-package-test',
    version = revision,
  },
})

assert(require('symfony_lsp.version'))
assert(vim.lsp.config.symfony_lsp.cmd[1] == 'symfony-lsp')
assert(vim.iter(vim.fn.tagfiles()):any(function(path)
  return path:find('symfony%-lsp%-package%-test/doc/tags') ~= nil
end))
print('Neovim package test passed')
vim.cmd.qall({ bang = true })
