local commands = require('symfony_lsp.commands')
local installer = require('symfony_lsp.installer')
local status = require('symfony_lsp.status')
local version = require('symfony_lsp.version')

local M = {}

local options = {}
local command
local configured = false

local function notify(message, level)
  vim.notify(message, level, { title = 'Symfony LSP' })
end

local function normalize_command(value)
  if type(value) == 'string' and value ~= '' then
    return { value }
  end
  if type(value) == 'table' and #value > 0 then
    return value
  end
end

local function path_server_matches()
  if vim.fn.executable('symfony-lsp') ~= 1 then
    return false
  end
  local result = vim.system({ 'symfony-lsp', '--version' }, { text = true }):wait()

  return result.code == 0 and vim.trim(result.stdout or '') == 'Symfony LSP ' .. version
end

local function configure(cmd)
  command = cmd
  local config = { cmd = cmd }
  if options.cmd_env then
    config.cmd_env = options.cmd_env
  end
  if options.settings then
    config.settings = { symfonyLsp = options.settings }
    config.init_options = vim.deepcopy(options.settings)
  end
  config.init_options = config.init_options or {}
  if options.workspace_trust ~= nil then
    config.init_options.workspaceTrust = options.workspace_trust
  end
  if options.project_roots then
    config.init_options.projectRoots = options.project_roots
  end
  if options.trace then
    config.init_options.trace = options.trace
  end
  if options.capabilities then
    config.capabilities = options.capabilities
  end
  if options.on_attach then
    config.on_attach = options.on_attach
  end

  vim.lsp.config('symfony_lsp', config)
  vim.lsp.enable('symfony_lsp')
end

local function install(force, callback)
  status.set_installing(true)
  notify('Installing Symfony LSP ' .. version .. '…')
  installer.install(version, {
    base_url = options.download_base_url,
    data_dir = options.data_dir,
    force = force,
  }, function(path, error_message)
    status.set_installing(false)
    if error_message then
      notify(error_message, vim.log.levels.ERROR)
      if callback then
        callback(nil, error_message)
      end
      return
    end

    notify('Symfony LSP ' .. version .. ' installed.')
    configure({ path })
    if callback then
      callback(path)
    end
  end)
end

local function append_statusline()
  _G.symfony_lsp_statusline = M.statusline
  local expression = '%{%v:lua.symfony_lsp_statusline()%}'
  if not vim.o.statusline:find(expression, 1, true) then
    vim.o.statusline = vim.o.statusline .. ' ' .. expression
  end
end

function M.setup(user_options)
  if not vim.version.ge(vim.version(), { 0, 11, 3 }) then
    error('Symfony LSP requires Neovim 0.11.3 or later')
  end
  if configured then
    return
  end
  configured = true
  options = vim.tbl_deep_extend('force', {
    auto_install = true,
    status = {},
    statusline = false,
  }, user_options or {})

  status.setup(options.status)
  commands.setup({
    install = function(force)
      install(force)
    end,
  })
  if options.statusline then
    append_statusline()
  end

  local configured_command = normalize_command(options.cmd)
  if configured_command then
    configure(configured_command)
    return
  end
  local installed = installer.executable(version, { data_dir = options.data_dir })
  if installed then
    configure({ installed })
    return
  end
  if
    path_server_matches() or (not options.auto_install and vim.fn.executable('symfony-lsp') == 1)
  then
    configure({ 'symfony-lsp' })
    return
  end
  if options.auto_install then
    install(false)
    return
  end

  notify('Symfony LSP is not installed; run :SymfonyLspInstall.', vim.log.levels.WARN)
end

function M.install(force, callback)
  install(force or false, callback)
end

function M.statusline()
  return status.statusline()
end

function M.status()
  return status.current()
end

function M.refresh_index()
  commands.refresh_index()
end

function M.index_status()
  commands.index_status()
end

function M.switch_environment(environment)
  commands.switch_environment(environment)
end

function M.command()
  return command
end

return M
