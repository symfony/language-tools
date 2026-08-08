local repo = vim.fn.getcwd()
local fixture = repo .. '/tests/Fixtures/RuntimeApplication'
local route_file = fixture .. '/config/routes.yaml'
local test_directory = fixture .. '/var/neovim-e2e'
local consumer_file = test_directory .. '/RouteConsumer.php'
local notifications = {}
local route_file_contents

vim.opt.runtimepath:prepend(repo)
vim.notify = function(message, level, options)
  table.insert(notifications, {
    message = tostring(message),
    level = level,
    title = options and options.title,
  })
end

local function write(path, contents)
  vim.fn.mkdir(vim.fs.dirname(path), 'p')
  local file = assert(io.open(path, 'wb'))
  assert(file:write(contents))
  file:close()
end

local function position_after(text, needle)
  local start = assert(text:find(needle, 1, true), 'needle not found: ' .. needle)
  local prefix = text:sub(1, start + #needle - 1)
  local line = select(2, prefix:gsub('\n', ''))
  local line_start = prefix:match('.*\n()') or 1

  return { line = line, character = #prefix - line_start + 1 }
end

local function request(client, bufnr, method, params)
  local response, request_error = client:request_sync(method, params, 10000, bufnr)
  assert(response, request_error)
  assert(not response.err, vim.inspect(response.err))

  return response.result
end

local function labels(items)
  local result = {}
  for _, item in ipairs(items or {}) do
    result[item.label] = true
  end

  return result
end

local function wait_for(description, callback, timeout)
  local value
  local ready = vim.wait(timeout or 30000, function()
    value = callback()
    return value ~= nil and value ~= false
  end, 100)
  assert(ready, 'timed out waiting for ' .. description)

  return value
end

local function stop_clients()
  for _, client in ipairs(vim.lsp.get_clients()) do
    if client.name == 'symfony_lsp' then
      client:stop()
    end
  end
  vim.wait(5000, function()
    return not vim.iter(vim.lsp.get_clients()):any(function(client)
      return client.name == 'symfony_lsp'
    end)
  end, 50)
end

local function cleanup()
  pcall(vim.cmd, 'cclose')
  stop_clients()
  if route_file_contents then
    write(route_file, route_file_contents)
  end
  vim.fn.delete(test_directory, 'rf')
end

local function phase(message)
  print('Neovim integration: ' .. message)
end

local function test()
  phase('starting client')
  local message_file = fixture .. '/src/Message/Ping.php'
  vim.cmd.edit(vim.fn.fnameescape(message_file))
  local message_bufnr = vim.api.nvim_get_current_buf()
  local setup = {
    cmd = { repo .. '/bin/symfony-lsp' },
    workspace_trust = true,
    settings = { translationDiagnostics = true },
    statusline = true,
    status = { poll_interval = 250 },
  }
  if vim.env.SYMFONY_LSP_TREE_SITTER then
    setup.cmd_env = { SYMFONY_LSP_TREE_SITTER = vim.env.SYMFONY_LSP_TREE_SITTER }
  end
  require('symfony_lsp').setup(setup)

  local client = wait_for('the Symfony LSP client', function()
    for _, candidate in ipairs(vim.lsp.get_clients({ bufnr = message_bufnr })) do
      if candidate.name == 'symfony_lsp' and candidate.initialized then
        return candidate
      end
    end
  end, 10000)
  assert(client.offset_encoding == 'utf-8', client.offset_encoding)
  assert(client.server_info.name == 'Symfony LSP')
  assert(client.settings.symfonyLsp.phpCommand[1] == 'php')
  assert(client.settings.symfonyLsp.translationDiagnostics)
  wait_for('watched-file registration', function()
    local registrations = client.registrations['workspace/didChangeWatchedFiles'] or {}
    return #registrations == 1
  end, 10000)

  phase('waiting for indexes')
  local current_status = wait_for('ready Symfony indexes', function()
    local current = require('symfony_lsp').status()
    return current
      and current.source.state == 'ready'
      and current.runtime.state == 'ready'
      and current
  end, 60000)
  assert(current_status.trusted)
  assert(require('symfony_lsp').statusline() == 'Symfony dev ✓')
  assert(vim.o.statusline:find('symfony_lsp_statusline', 1, true))

  phase('running code lens')
  local lenses = request(client, message_bufnr, 'textDocument/codeLens', {
    textDocument = { uri = vim.uri_from_fname(message_file) },
  })
  assert(lenses[1] and lenses[1].command.title == '1 Messenger handler', vim.inspect(lenses))
  client:exec_cmd(lenses[1].command, { bufnr = message_bufnr })
  local quickfix = vim.fn.getqflist({ title = 1, items = 1 })
  assert(quickfix.title == '1 Messenger handler', vim.inspect(quickfix))
  assert(#quickfix.items == 1, vim.inspect(quickfix))
  vim.cmd.cclose()

  phase('checking language features')
  local consumer = [[<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

final class RouteConsumer extends AbstractController
{
    public function consume(): void
    {
        $this->generateUrl('fixture_home');
        $this->generateUrl('neovim_');
        $this->generateUrl('missing_neovim_route');
    }
}
]]
  write(consumer_file, consumer)
  vim.cmd.edit(vim.fn.fnameescape(consumer_file))
  local consumer_bufnr = vim.api.nvim_get_current_buf()
  wait_for('the route consumer attachment', function()
    for _, attached in ipairs(vim.lsp.get_clients({ bufnr = consumer_bufnr })) do
      if attached.id == client.id then
        return true
      end
    end
  end, 10000)

  local fixture_position = position_after(consumer, "generateUrl('fixture_")
  local fixture_completions = request(client, consumer_bufnr, 'textDocument/completion', {
    textDocument = { uri = vim.uri_from_bufnr(consumer_bufnr) },
    position = fixture_position,
  })
  assert(labels(fixture_completions).fixture_home)

  local hover_position = position_after(consumer, "generateUrl('fixture_h")
  local hover = request(client, consumer_bufnr, 'textDocument/hover', {
    textDocument = { uri = vim.uri_from_bufnr(consumer_bufnr) },
    position = hover_position,
  })
  assert(hover.contents.value:find('Path: `/fixture`', 1, true), vim.inspect(hover))

  local definitions = request(client, consumer_bufnr, 'textDocument/definition', {
    textDocument = { uri = vim.uri_from_bufnr(consumer_bufnr) },
    position = hover_position,
  })
  assert(definitions[1].uri:find('/config/routes.yaml', 1, true), vim.inspect(definitions))

  wait_for('the missing route diagnostic', function()
    for _, diagnostic in ipairs(vim.diagnostic.get(consumer_bufnr)) do
      if diagnostic.code == 'route.not_found' then
        return true
      end
    end
  end, 10000)

  phase('checking watched files')
  local route_file_handle = assert(io.open(route_file, 'rb'))
  route_file_contents = assert(route_file_handle:read('*a'))
  route_file_handle:close()
  write(route_file, route_file_contents .. [[
neovim_external:
    path: /neovim
    controller: App\Controller\HomeController
]])
  local external_route_ready = false
  local function request_external_route()
    client:request('textDocument/completion', {
      textDocument = { uri = vim.uri_from_bufnr(consumer_bufnr) },
      position = position_after(consumer, "generateUrl('neovim_"),
    }, function(error_response, completions)
      if not error_response and labels(completions).neovim_external then
        external_route_ready = true
        return
      end
      vim.defer_fn(request_external_route, 200)
    end, consumer_bufnr)
  end
  request_external_route()
  wait_for('the externally created route', function()
    return external_route_ready
  end, 30000)

  phase('switching environment')
  vim.cmd('SymfonyLspSwitchEnvironment test')
  wait_for('the test environment', function()
    local selected = require('symfony_lsp').status()
    return selected
      and selected.environment == 'test'
      and selected.runtime.state == 'ready'
      and selected
  end, 60000)
  assert(require('symfony_lsp').statusline() == 'Symfony test ✓')

  phase('refreshing indexes')
  vim.cmd('SymfonyLspRefreshIndex')
  wait_for('the refreshed index report', function()
    for _, notification in ipairs(notifications) do
      if notification.message:find('source ready, runtime ready, environment test', 1, true) then
        return true
      end
    end
  end, 60000)

  phase('showing index status')
  vim.cmd('SymfonyLspIndexStatus')
  wait_for('the index status report', function()
    for _, notification in ipairs(notifications) do
      if
        notification.title == 'Symfony LSP'
        and notification.message:find(fixture, 1, true)
        and notification.message:find('environment test', 1, true)
      then
        return true
      end
    end
  end, 10000)
end

local succeeded, failure = xpcall(test, debug.traceback)
cleanup()
if not succeeded then
  error(failure)
end

print('Neovim integration tests passed')
vim.cmd.qall({ bang = true })
