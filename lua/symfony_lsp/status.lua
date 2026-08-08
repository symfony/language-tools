local M = {}

local configured = false
local statuses = {}
local pending = {}
local timer
local poll_interval = 5000
local installing = false

local icons = {
  ready = '✓',
  indexing = '…',
  stale = '!',
  failed = '×',
  installing = '↓',
}

local function clients()
  return vim
    .iter(vim.lsp.get_clients())
    :filter(function(client)
      return client.name == 'symfony_lsp' and client.initialized
    end)
    :totable()
end

local function redraw()
  vim.cmd('redrawstatus')
end

local function stop_timer()
  if timer then
    timer:stop()
    timer:close()
    timer = nil
  end
end

local function ensure_timer()
  if timer or #clients() == 0 then
    return
  end
  timer = assert(vim.uv.new_timer())
  timer:start(
    poll_interval,
    poll_interval,
    vim.schedule_wrap(function()
      M.refresh()
    end)
  )
end

local function path_contains(root, path)
  local separator = package.config:sub(1, 1)
  root = vim.fs.normalize(root)
  path = vim.fs.normalize(path)
  if vim.uv.os_uname().sysname == 'Windows_NT' then
    root = root:lower()
    path = path:lower()
  end

  return path == root or vim.startswith(path, root .. separator)
end

function M.update(client_id, result)
  statuses[client_id] = type(result) == 'table' and result or {}
  redraw()
end

function M.remove(client_id)
  statuses[client_id] = nil
  pending[client_id] = nil
  vim.schedule(function()
    if #clients() == 0 then
      stop_timer()
    end
  end)
  redraw()
end

function M.refresh_client(client, callback)
  if pending[client.id] then
    if callback then
      callback(nil, statuses[client.id])
    end
    return
  end
  pending[client.id] = true
  local sent = client:request('workspace/executeCommand', {
    command = 'symfony.indexStatus',
    arguments = {},
  }, function(error_response, result)
    pending[client.id] = nil
    if not error_response then
      M.update(client.id, result)
    end
    if callback then
      callback(error_response, result)
    end
  end)
  if not sent then
    pending[client.id] = nil
    if callback then
      callback({ message = 'unable to request Symfony LSP index status' })
    end
  end
end

function M.refresh(callback)
  local active_clients = clients()
  ensure_timer()
  if #active_clients == 0 then
    if callback then
      callback({})
    end
    return
  end

  local remaining = #active_clients
  local results = {}
  for _, client in ipairs(active_clients) do
    M.refresh_client(client, function(error_response, result)
      results[client.id] = { error = error_response, result = result }
      remaining = remaining - 1
      if remaining == 0 and callback then
        callback(results)
      end
    end)
  end
end

function M.all()
  local result = {}
  for _, client_statuses in pairs(statuses) do
    vim.list_extend(result, client_statuses)
  end
  table.sort(result, function(left, right)
    return left.root < right.root
  end)

  return result
end

function M.current(bufnr)
  bufnr = bufnr or 0
  local path = vim.api.nvim_buf_get_name(bufnr)
  local result
  for _, candidate in ipairs(M.all()) do
    if path ~= '' and path_contains(candidate.root, path) then
      if not result or #candidate.root > #result.root then
        result = candidate
      end
    end
  end

  return result or M.all()[1]
end

function M.describe(status)
  local runtime = not status.runtimeEnabled and 'disabled'
    or (status.trusted and status.runtime.state or 'static only')
  local summary = string.format(
    '%s: source %s, runtime %s, environment %s',
    status.root,
    status.source.state,
    runtime,
    status.environment
  )
  local errors = {}
  if status.source.error then
    table.insert(errors, status.source.error)
  end
  if status.runtime.error then
    table.insert(errors, status.runtime.error)
  end

  return #errors == 0 and summary or summary .. '. ' .. table.concat(errors, ' ')
end

function M.statusline()
  if installing then
    return 'Symfony ' .. icons.installing
  end
  local status = M.current()
  if not status then
    return ''
  end

  local runtime_active = status.runtimeEnabled and status.trusted
  if status.source.state == 'failed' or (runtime_active and status.runtime.state == 'failed') then
    return 'Symfony ' .. icons.failed
  end
  if
    status.source.state == 'indexing' or (runtime_active and status.runtime.state == 'indexing')
  then
    return 'Symfony ' .. icons.indexing
  end
  if not runtime_active then
    return 'Symfony static'
  end
  if status.runtime.state == 'stale' then
    return 'Symfony ' .. status.environment .. ' ' .. icons.stale
  end
  if status.source.state == 'ready' and status.runtime.state == 'ready' then
    return 'Symfony ' .. status.environment .. ' ' .. icons.ready
  end

  return 'Symfony ' .. status.environment
end

function M.set_installing(value)
  installing = value
  redraw()
end

function M.setup(options)
  options = options or {}
  if configured then
    return
  end
  configured = true
  poll_interval = options.poll_interval or poll_interval
  icons = vim.tbl_extend('force', icons, options.icons or {})

  local group = vim.api.nvim_create_augroup('SymfonyLspStatus', { clear = true })
  vim.api.nvim_create_autocmd('LspAttach', {
    group = group,
    callback = function(event)
      local client = vim.lsp.get_client_by_id(event.data.client_id)
      if client and client.name == 'symfony_lsp' then
        ensure_timer()
        M.refresh_client(client)
      end
    end,
  })
  vim.api.nvim_create_autocmd('LspDetach', {
    group = group,
    callback = function(event)
      M.remove(event.data.client_id)
    end,
  })
  vim.api.nvim_create_autocmd({ 'BufEnter', 'BufWritePost' }, {
    group = group,
    callback = function()
      vim.defer_fn(function()
        M.refresh()
      end, 300)
    end,
  })
  vim.api.nvim_create_autocmd('VimLeavePre', {
    group = group,
    callback = stop_timer,
  })
end

function M.stop()
  stop_timer()
  statuses = {}
  pending = {}
end

return M
