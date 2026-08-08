local installer = require('symfony_lsp.installer')
local version = require('symfony_lsp.version')

local M = {}

function M.check()
  vim.health.start('Symfony LSP')

  local neovim = vim.version()
  local neovim_version = string.format('%d.%d.%d', neovim.major, neovim.minor, neovim.patch)
  if vim.version.ge(neovim, { 0, 11, 3 }) then
    vim.health.ok('Neovim ' .. neovim_version)
  else
    vim.health.error('Neovim 0.11.3 or later is required')
  end

  local platform, platform_error = installer.platform()
  if platform then
    vim.health.ok('Supported platform: ' .. platform)
  else
    vim.health.error(platform_error)
  end

  if vim.fn.executable('curl') == 1 then
    vim.health.ok('curl is available')
  else
    vim.health.error('curl is required for automatic installation')
  end
  if vim.fn.executable('tar') == 1 then
    vim.health.ok('tar is available')
  else
    vim.health.error('tar is required for automatic installation')
  end
  local checksum_tool = platform and vim.startswith(platform, 'linux-') and 'sha256sum'
    or (platform and vim.startswith(platform, 'macos-') and 'shasum' or 'certutil')
  if vim.fn.executable(checksum_tool) == 1 then
    vim.health.ok(checksum_tool .. ' is available')
  else
    vim.health.error(checksum_tool .. ' is required for automatic installation')
  end

  local configured_command = require('symfony_lsp').command()
  local executable = configured_command and configured_command[1] or installer.executable(version)
  if executable and vim.fn.executable(executable) == 1 then
    vim.health.ok('Symfony LSP ' .. version .. ' is available at ' .. executable)
  elseif vim.fn.executable('symfony-lsp') == 1 then
    vim.health.ok('Symfony LSP is available on PATH')
  else
    vim.health.warn('Symfony LSP is not installed; run :SymfonyLspInstall')
  end

  local active = vim
    .iter(vim.lsp.get_clients())
    :filter(function(client)
      return client.name == 'symfony_lsp'
    end)
    :totable()
  if #active == 0 then
    vim.health.info('No Symfony LSP client is active')
  else
    for _, client in ipairs(active) do
      local server_version = client.server_info and client.server_info.version or 'unknown'
      vim.health.ok('Client ' .. client.id .. ' is active with server ' .. server_version)
    end
  end
end

return M
