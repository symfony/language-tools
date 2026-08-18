local repo = vim.fn.getcwd()
local fixture = repo .. '/tests/Fixtures/RuntimeApplication'
local route_file = fixture .. '/config/routes.yaml'
local test_directory = fixture .. '/src/NeovimE2e'
local consumer_file = test_directory .. '/RouteConsumer.php'
local route_file_contents

vim.opt.runtimepath:prepend(repo .. '/editor/neovim')
require('vim.lsp._watchfiles')._watchfunc = require('vim._watch').watchdirs
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
    init_options = vim.tbl_deep_extend(
      'force',
      vim.deepcopy(vim.lsp.config.symfony_lsp.init_options),
      {
        workspaceTrust = true,
      }
    ),
    settings = {
      symfonyLsp = vim.tbl_deep_extend(
        'force',
        vim.deepcopy(vim.lsp.config.symfony_lsp.settings.symfonyLsp),
        { translationDiagnostics = true }
      ),
    },
  }
  vim.lsp.config('symfony_lsp', setup)
  vim.lsp.enable('symfony_lsp')

  local client = wait_for('the Symfony Language Tools client', function()
    for _, candidate in ipairs(vim.lsp.get_clients({ bufnr = message_bufnr })) do
      if candidate.name == 'symfony_lsp' and candidate.initialized then
        return candidate
      end
    end
  end, 10000)
  assert(client.offset_encoding == 'utf-8', client.offset_encoding)
  assert(client.server_info.name == 'Symfony Language Tools')
  assert(client.settings.symfonyLsp.phpCommand[1] == 'php')
  assert(client.settings.symfonyLsp.bridgeTimeout == 300)
  assert(client.settings.symfonyLsp.translationDiagnostics)
  wait_for('watched-file registration', function()
    local registrations = client.registrations['workspace/didChangeWatchedFiles'] or {}
    return #registrations == 1
  end, 10000)

  phase('waiting for indexes')
  local statuses = wait_for('ready Symfony indexes', function()
    local current = request(client, message_bufnr, 'workspace/executeCommand', {
      command = 'symfony.indexStatus',
      arguments = {},
    })
    local project = current and current[1]
    return project
      and project.source.state == 'ready'
      and project.runtime.state == 'ready'
      and current
  end, 60000)
  assert(statuses[1].trusted)

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

namespace App\NeovimE2e;

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
end

local succeeded, failure = xpcall(test, debug.traceback)
cleanup()
if not succeeded then
  error(failure)
end

print('Neovim integration tests passed')
vim.cmd.qall({ bang = true })
