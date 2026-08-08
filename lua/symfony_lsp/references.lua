local M = {}

function M.show(command, ctx)
  local client = assert(vim.lsp.get_client_by_id(ctx.client_id))
  local arguments = command.arguments or {}
  local uri = arguments[1]
  local position = arguments[2]
  local references = arguments[3]
  if type(uri) ~= 'string' or type(position) ~= 'table' or type(references) ~= 'table' then
    vim.notify('Symfony LSP returned an invalid reference command.', vim.log.levels.ERROR)
    return
  end

  local items = vim.lsp.util.locations_to_items(references, client.offset_encoding)
  vim.fn.setqflist({}, ' ', {
    title = command.title,
    items = items,
    context = {
      command = command,
      bufnr = ctx.bufnr,
    },
  })
  vim.lsp.util.show_document({
    uri = uri,
    range = {
      start = position,
      ['end'] = position,
    },
  }, client.offset_encoding)
  vim.cmd('botright copen')
end

return M
