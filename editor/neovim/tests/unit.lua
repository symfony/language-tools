local repo = vim.fn.getcwd()
vim.opt.runtimepath:prepend(repo)

local installer = require('symfony_lsp.installer')
local status = require('symfony_lsp.status')

local function assert_same(expected, actual, message)
  if not vim.deep_equal(expected, actual) then
    error(
      (message or 'values differ')
        .. '\nexpected: '
        .. vim.inspect(expected)
        .. '\nactual: '
        .. vim.inspect(actual)
    )
  end
end

local config = vim.lsp.config.symfony_lsp
assert_same({ 'symfony-lsp' }, config.cmd)
assert_same({ 'composer.json', '.git' }, config.root_markers)
assert(config.workspace_required)
assert(config.capabilities.workspace.didChangeWatchedFiles.dynamicRegistration)
assert(type(config.commands['editor.action.showReferences']) == 'function')

assert_same('linux-x64', installer.platform({ sysname = 'Linux', machine = 'x86_64' }))
assert_same('linux-arm64', installer.platform({ sysname = 'Linux', machine = 'aarch64' }))
assert_same('macos-arm64', installer.platform({ sysname = 'Darwin', machine = 'arm64' }))
assert_same('windows-x64', installer.platform({ sysname = 'Windows_NT', machine = 'AMD64' }))
assert_same('symfony-lsp-v1.2.3-linux-x64.tar.gz', installer.archive('1.2.3', 'linux-x64'))
assert_same('symfony-lsp-v1.2.3-windows-x64.zip', installer.archive('1.2.3', 'windows-x64'))

local root = repo .. '/var/neovim-tests/unit'
vim.fn.delete(root, 'rf')
vim.fn.mkdir(root, 'p')
vim.cmd.edit(vim.fn.fnameescape(root .. '/project/src/Controller.php'))
status.update(1, {
  {
    root = root .. '/project',
    environment = 'test',
    runtimeEnabled = true,
    trusted = true,
    source = { state = 'ready' },
    runtime = { state = 'ready' },
  },
})
assert_same('Symfony test ✓', status.statusline())
status.update(1, {
  {
    root = root .. '/project',
    environment = 'test',
    runtimeEnabled = true,
    trusted = false,
    source = { state = 'ready' },
    runtime = { state = 'idle' },
  },
})
assert_same('Symfony static', status.statusline())
status.update(1, {
  {
    root = root .. '/project',
    environment = 'test',
    runtimeEnabled = false,
    trusted = true,
    source = { state = 'ready' },
    runtime = { state = 'idle' },
  },
})
assert_same('Symfony static', status.statusline())
assert_same(
  root .. '/project: source ready, runtime disabled, environment test',
  status.describe(status.current())
)
status.update(1, {
  {
    root = root .. '/project',
    environment = 'test',
    runtimeEnabled = true,
    trusted = true,
    source = { state = 'ready' },
    runtime = { state = 'stale' },
  },
})
assert_same('Symfony test !', status.statusline())
status.remove(1)

local function write(path, contents)
  local file = assert(io.open(path, 'wb'))
  assert(file:write(contents))
  file:close()
end

local function create_release(version, valid_checksum)
  local platform = assert(installer.platform())
  local archive = installer.archive(version, platform)
  local release = root .. '/releases/v' .. version
  local package = root .. '/package/symfony-lsp-v' .. version .. '-' .. platform
  vim.fn.mkdir(release, 'p')
  vim.fn.mkdir(package, 'p')
  local suffix = platform == 'windows-x64' and '.exe' or ''
  write(package .. '/symfony-lsp' .. suffix, '#!/bin/sh\necho "Symfony LSP ' .. version .. '"\n')
  write(package .. '/symfony-lsp-tree-sitter' .. suffix, '#!/bin/sh\nexit 0\n')
  write(package .. '/LICENSE', 'fixture\n')
  vim.uv.fs_chmod(package .. '/symfony-lsp' .. suffix, 493)
  vim.uv.fs_chmod(package .. '/symfony-lsp-tree-sitter' .. suffix, 493)
  local result = vim
    .system({
      'tar',
      platform == 'windows-x64' and '-cf' or '-czf',
      release .. '/' .. archive,
      '-C',
      root .. '/package',
      vim.fs.basename(package),
    })
    :wait()
  assert_same(0, result.code, result.stderr)
  local checksum_command = vim.startswith(platform, 'linux-')
      and { 'sha256sum', release .. '/' .. archive }
    or { 'shasum', '-a', '256', release .. '/' .. archive }
  local checksum_result = vim.system(checksum_command, { text = true }):wait()
  assert_same(0, checksum_result.code, checksum_result.stderr)
  local checksum = valid_checksum and checksum_result.stdout:match('^[0-9a-fA-F]+')
    or string.rep('0', 64)
  write(release .. '/SHA256SUMS', checksum .. '  ' .. archive .. '\n')
end

local function install(version)
  local finished = false
  local installed_path
  local install_error
  installer.install(version, {
    base_url = 'file://' .. root .. '/releases',
    data_dir = root .. '/installed',
  }, function(path, error_message)
    installed_path = path
    install_error = error_message
    finished = true
  end)
  assert(
    vim.wait(30000, function()
      return finished
    end, 20),
    'installer timed out'
  )

  return installed_path, install_error
end

create_release('9.8.7-test', true)
local installed_path, install_error = install('9.8.7-test')
assert(not install_error, install_error)
assert(vim.uv.fs_stat(installed_path))
assert_same(installed_path, installer.executable('9.8.7-test', { data_dir = root .. '/installed' }))

create_release('9.8.8-test', false)
installed_path, install_error = install('9.8.8-test')
assert(not installed_path)
assert(install_error:find('checksum verification failed', 1, true), install_error)
assert(not installer.executable('9.8.8-test', { data_dir = root .. '/installed' }))

vim.fn.delete(root, 'rf')
print('Neovim unit tests passed')
vim.cmd.qall({ bang = true })
