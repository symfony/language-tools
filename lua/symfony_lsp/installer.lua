local M = {}

local installs = {}

local function join(...)
  return vim.fs.joinpath(...)
end

local function executable_names()
  if vim.uv.os_uname().sysname == 'Windows_NT' then
    return 'symfony-lsp.exe', 'symfony-lsp-tree-sitter.exe'
  end

  return 'symfony-lsp', 'symfony-lsp-tree-sitter'
end

local function read_file(path)
  local stat, stat_error = vim.uv.fs_stat(path)
  if not stat then
    return nil, stat_error
  end
  local file, open_error = vim.uv.fs_open(path, 'r', 438)
  if not file then
    return nil, open_error
  end
  local contents, read_error = vim.uv.fs_read(file, stat.size, 0)
  vim.uv.fs_close(file)

  return contents, read_error
end

local function remove(path)
  if vim.uv.fs_stat(path) then
    vim.fn.delete(path, 'rf')
  end
end

local function run(command, callback)
  vim.system(
    command,
    { text = true },
    vim.schedule_wrap(function(result)
      if result.code ~= 0 then
        local message = vim.trim(result.stderr or '')
        callback(nil, message ~= '' and message or ('command failed with status ' .. result.code))
        return
      end

      callback(result)
    end)
  )
end

local function finish(key, path, error_message)
  local callbacks = installs[key] or {}
  installs[key] = nil
  for _, callback in ipairs(callbacks) do
    callback(path, error_message)
  end
end

local function checksum_command(platform, path)
  if vim.startswith(platform, 'linux-') then
    return { 'sha256sum', path }, 'sha256sum'
  end
  if vim.startswith(platform, 'macos-') then
    return { 'shasum', '-a', '256', path }, 'shasum'
  end

  return { 'certutil', '-hashfile', path, 'SHA256' }, 'certutil'
end

local function checksum(output)
  for token in output:gmatch('[0-9a-fA-F]+') do
    if #token == 64 then
      return token:lower()
    end
  end
end

local function expected_checksum(contents, archive)
  for line in contents:gmatch('[^\r\n]+') do
    local hash, name = line:match('^([0-9a-fA-F]+)%s+%*?(.+)$')
    if name == archive and #hash == 64 then
      return hash:lower()
    end
  end
end

local function architecture(machine)
  machine = machine:lower()
  if machine == 'x86_64' or machine == 'amd64' then
    return 'x64'
  end
  if machine == 'arm64' or machine == 'aarch64' then
    return 'arm64'
  end
end

function M.platform(uname)
  uname = uname or vim.uv.os_uname()
  local arch = architecture(uname.machine)
  if not arch then
    return nil, 'unsupported architecture: ' .. uname.machine
  end
  if uname.sysname == 'Linux' then
    return 'linux-' .. arch
  end
  if uname.sysname == 'Darwin' then
    return 'macos-' .. arch
  end
  if uname.sysname == 'Windows_NT' and arch == 'x64' then
    return 'windows-x64'
  end

  return nil, 'unsupported platform: ' .. uname.sysname .. '-' .. arch
end

function M.archive(version, platform)
  local extension = platform == 'windows-x64' and '.zip' or '.tar.gz'
  return 'symfony-lsp-v' .. version .. '-' .. platform .. extension
end

function M.data_dir(options)
  return options and options.data_dir or join(vim.fn.stdpath('data'), 'symfony-lsp')
end

function M.executable(version, options)
  local platform = M.platform()
  if not platform then
    return nil
  end
  local server = executable_names()
  local path = join(M.data_dir(options), version, platform, server)

  return vim.uv.fs_stat(path) and path or nil
end

function M.install(version, options, callback)
  options = options or {}
  local platform, platform_error = M.platform(options.uname)
  if not platform then
    callback(nil, platform_error)
    return
  end
  if vim.fn.executable('curl') ~= 1 then
    callback(nil, 'curl is required to install Symfony LSP')
    return
  end
  if vim.fn.executable('tar') ~= 1 then
    callback(nil, 'tar is required to install Symfony LSP')
    return
  end
  local _, checksum_tool = checksum_command(platform, '')
  if vim.fn.executable(checksum_tool) ~= 1 then
    callback(nil, checksum_tool .. ' is required to verify Symfony LSP downloads')
    return
  end

  local data_dir = M.data_dir(options)
  local target = join(data_dir, version, platform)
  local server_name, sidecar_name = executable_names()
  local installed_server = join(target, server_name)
  if not options.force and vim.uv.fs_stat(installed_server) then
    callback(installed_server)
    return
  end
  if installs[target] then
    table.insert(installs[target], callback)
    return
  end
  installs[target] = { callback }

  vim.fn.mkdir(join(data_dir, version), 'p')
  local temporary = target .. '.tmp-' .. vim.uv.os_getpid()
  remove(temporary)
  vim.fn.mkdir(temporary, 'p')
  local archive_name = M.archive(version, platform)
  local archive_path = join(temporary, archive_name)
  local checksums_path = join(temporary, 'SHA256SUMS')
  local base_url = options.base_url or 'https://github.com/symfony/lsp/releases/download'
  local release_url = base_url .. '/v' .. version

  local function fail(message)
    remove(temporary)
    finish(target, nil, message)
  end

  run({
    'curl',
    '--fail',
    '--location',
    '--silent',
    '--show-error',
    '--output',
    checksums_path,
    release_url .. '/SHA256SUMS',
  }, function(_, checksum_download_error)
    if checksum_download_error then
      fail('unable to download Symfony LSP checksums: ' .. checksum_download_error)
      return
    end

    run({
      'curl',
      '--fail',
      '--location',
      '--silent',
      '--show-error',
      '--output',
      archive_path,
      release_url .. '/' .. archive_name,
    }, function(_, archive_download_error)
      if archive_download_error then
        fail('unable to download Symfony LSP: ' .. archive_download_error)
        return
      end

      local checksums, checksums_error = read_file(checksums_path)
      if not checksums then
        fail('unable to read Symfony LSP checksums: ' .. tostring(checksums_error))
        return
      end
      local expected = expected_checksum(checksums, archive_name)
      if not expected then
        fail('Symfony LSP archive is missing from SHA256SUMS')
        return
      end
      local checksum_process = checksum_command(platform, archive_path)
      run(checksum_process, function(checksum_result, checksum_error)
        if checksum_error then
          fail('unable to verify Symfony LSP archive: ' .. checksum_error)
          return
        end
        if expected ~= checksum(checksum_result.stdout or '') then
          fail('Symfony LSP archive checksum verification failed')
          return
        end

        run(
          { 'tar', platform == 'windows-x64' and '-xf' or '-xzf', archive_path, '-C', temporary },
          function(_, extraction_error)
            if extraction_error then
              fail('unable to extract Symfony LSP: ' .. extraction_error)
              return
            end

            local extracted = join(temporary, 'symfony-lsp-v' .. version .. '-' .. platform)
            local extracted_server = join(extracted, server_name)
            local extracted_sidecar = join(extracted, sidecar_name)
            if not vim.uv.fs_stat(extracted_server) or not vim.uv.fs_stat(extracted_sidecar) then
              fail('Symfony LSP archive does not contain the server and Tree-sitter sidecar')
              return
            end
            if platform ~= 'windows-x64' then
              vim.uv.fs_chmod(extracted_server, 493)
              vim.uv.fs_chmod(extracted_sidecar, 493)
            end

            run({ extracted_server, '--version' }, function(result, verification_error)
              if verification_error then
                fail('unable to run the installed Symfony LSP: ' .. verification_error)
                return
              end
              if vim.trim(result.stdout or '') ~= 'Symfony LSP ' .. version then
                fail('installed Symfony LSP reported an unexpected version')
                return
              end

              local previous = target .. '.old'
              remove(previous)
              if vim.uv.fs_stat(target) then
                local moved, move_error = vim.uv.fs_rename(target, previous)
                if not moved then
                  fail('unable to replace Symfony LSP: ' .. tostring(move_error))
                  return
                end
              end
              local installed, install_error = vim.uv.fs_rename(extracted, target)
              if not installed then
                if vim.uv.fs_stat(previous) then
                  vim.uv.fs_rename(previous, target)
                end
                fail('unable to install Symfony LSP: ' .. tostring(install_error))
                return
              end
              remove(previous)
              remove(temporary)
              finish(target, installed_server)
            end)
          end
        )
      end)
    end)
  end)
end

return M
