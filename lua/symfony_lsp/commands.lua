local status = require('symfony_lsp.status')

local M = {}

local configured = false
local install

local function client_for_buffer()
  for _, client in ipairs(vim.lsp.get_clients({ bufnr = 0 })) do
    if client.name == 'symfony_lsp' then
      return client
    end
  end
  for _, client in ipairs(vim.lsp.get_clients()) do
    if client.name == 'symfony_lsp' then
      return client
    end
  end
end

local function notify_error(message)
  vim.notify(message, vim.log.levels.ERROR, { title = 'Symfony LSP' })
end

local function execute(command, arguments, callback)
  local client = client_for_buffer()
  if not client then
    notify_error('Symfony LSP is not running.')
    return
  end

  local sent = client:request('workspace/executeCommand', {
    command = command,
    arguments = arguments or {},
  }, function(error_response, result)
    if error_response then
      notify_error(error_response.message or 'Symfony LSP command failed.')
      return
    end
    status.update(client.id, result)
    if callback then
      callback(result)
    end
  end, 0)
  if not sent then
    notify_error('Unable to send the Symfony LSP command.')
  end
end

local function show_statuses(result)
  if type(result) ~= 'table' or #result == 0 then
    vim.notify('Symfony LSP did not discover a Symfony application.', nil, {
      title = 'Symfony LSP',
    })
    return
  end
  local descriptions = vim.tbl_map(status.describe, result)
  vim.notify(table.concat(descriptions, '\n'), nil, { title = 'Symfony LSP' })
end

function M.refresh_index()
  local current = status.current()
  execute('symfony.refreshIndex', current and { current.root } or {}, show_statuses)
end

function M.index_status()
  execute('symfony.indexStatus', {}, show_statuses)
end

function M.switch_environment(environment)
  local current = status.current()
  local function switch(value)
    if not value or value == '' then
      return
    end
    if not value:match('^[A-Za-z0-9_.-]+$') then
      notify_error('Use letters, numbers, dots, underscores or hyphens for the environment.')
      return
    end
    execute(
      'symfony.switchEnvironment',
      { current and current.root or vim.NIL, value },
      show_statuses
    )
  end

  if environment and environment ~= '' then
    switch(environment)
    return
  end
  vim.ui.input({
    prompt = 'Symfony environment: ',
    default = current and current.environment or 'dev',
  }, switch)
end

function M.setup(options)
  if configured then
    return
  end
  configured = true
  install = options.install

  vim.api.nvim_create_user_command('SymfonyLspRefreshIndex', M.refresh_index, {})
  vim.api.nvim_create_user_command('SymfonyLspIndexStatus', M.index_status, {})
  vim.api.nvim_create_user_command('SymfonyLspSwitchEnvironment', function(command)
    M.switch_environment(command.args)
  end, { nargs = '?' })
  vim.api.nvim_create_user_command('SymfonyLspInstall', function(command)
    install(command.bang)
  end, { bang = true })
end

return M
