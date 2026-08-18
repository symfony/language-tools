use std::fs;

use zed_extension_api::settings::LspSettings;
use zed_extension_api::{self as zed, Architecture, LanguageServerId, Os, Result};

const LANGUAGE_SERVER_ID: &str = "symfony-language-tools";
const RELEASE_REPOSITORY: &str = "symfony/language-tools";
const INSTALLATION_PREFIX: &str = "symfony-language-tools-";

struct SymfonyLanguageToolsExtension {
    cached_binary_path: Option<String>,
}

#[derive(Debug, PartialEq)]
struct ReleasePackage {
    asset_name: String,
    installation_directory: String,
    binary_path: String,
}

impl zed::Extension for SymfonyLanguageToolsExtension {
    fn new() -> Self {
        Self {
            cached_binary_path: None,
        }
    }

    fn language_server_command(
        &mut self,
        language_server_id: &LanguageServerId,
        worktree: &zed::Worktree,
    ) -> Result<zed::Command> {
        if language_server_id.as_ref() != LANGUAGE_SERVER_ID {
            return Err(format!("unknown language server: {language_server_id}"));
        }

        let (os, architecture) = zed::current_platform();
        platform_name(os, architecture)?;

        let settings = LspSettings::for_worktree(LANGUAGE_SERVER_ID, worktree).unwrap_or_default();
        let arguments = settings
            .binary
            .as_ref()
            .and_then(|binary| binary.arguments.clone())
            .unwrap_or_default();
        let environment = settings
            .binary
            .as_ref()
            .and_then(|binary| binary.env.clone())
            .unwrap_or_default()
            .into_iter()
            .collect();

        let command = settings
            .binary
            .as_ref()
            .and_then(|binary| binary.path.as_ref())
            .filter(|path| !path.is_empty())
            .cloned()
            .or_else(|| worktree.which("symfony-lsp"))
            .map(Ok)
            .unwrap_or_else(|| {
                self.download_language_server(language_server_id, os, architecture)
            })?;

        Ok(zed::Command {
            command,
            args: arguments,
            env: environment,
        })
    }

    fn language_server_initialization_options(
        &mut self,
        _language_server_id: &LanguageServerId,
        worktree: &zed::Worktree,
    ) -> Result<Option<zed::serde_json::Value>> {
        Ok(LspSettings::for_worktree(LANGUAGE_SERVER_ID, worktree)
            .ok()
            .and_then(|settings| settings.initialization_options))
    }

    fn language_server_workspace_configuration(
        &mut self,
        _language_server_id: &LanguageServerId,
        worktree: &zed::Worktree,
    ) -> Result<Option<zed::serde_json::Value>> {
        let settings = LspSettings::for_worktree(LANGUAGE_SERVER_ID, worktree)
            .ok()
            .and_then(|settings| settings.settings)
            .unwrap_or_default();

        Ok(Some(zed::serde_json::json!({ "symfonyLsp": settings })))
    }
}

impl SymfonyLanguageToolsExtension {
    fn download_language_server(
        &mut self,
        language_server_id: &LanguageServerId,
        os: Os,
        architecture: Architecture,
    ) -> Result<String> {
        if let Some(path) = &self.cached_binary_path
            && fs::metadata(path).is_ok_and(|metadata| metadata.is_file())
        {
            return Ok(path.clone());
        }

        zed::set_language_server_installation_status(
            language_server_id,
            &zed::LanguageServerInstallationStatus::CheckingForUpdate,
        );
        let release = zed::latest_github_release(
            RELEASE_REPOSITORY,
            zed::GithubReleaseOptions {
                require_assets: true,
                pre_release: false,
            },
        )?;
        let package = release_package(&release.version, os, architecture)?;
        let asset = release
            .assets
            .iter()
            .find(|asset| asset.name == package.asset_name)
            .ok_or_else(|| format!("no release asset found matching {:?}", package.asset_name))?;

        if !fs::metadata(&package.binary_path).is_ok_and(|metadata| metadata.is_file()) {
            zed::set_language_server_installation_status(
                language_server_id,
                &zed::LanguageServerInstallationStatus::Downloading,
            );
            fs::create_dir_all(&package.installation_directory)
                .map_err(|error| format!("failed to create installation directory: {error}"))?;
            zed::download_file(
                &asset.download_url,
                &package.installation_directory,
                zed::DownloadedFileType::GzipTar,
            )
            .map_err(|error| format!("failed to download Symfony Language Tools: {error}"))?;
            zed::make_file_executable(&package.binary_path)?;
            remove_outdated_installations(&package.installation_directory)?;
        }

        self.cached_binary_path = Some(package.binary_path.clone());

        Ok(package.binary_path)
    }
}

fn platform_name(os: Os, architecture: Architecture) -> Result<&'static str> {
    match (os, architecture) {
        (Os::Linux, Architecture::X8664) => Ok("linux-x64"),
        (Os::Linux, Architecture::Aarch64) => Ok("linux-arm64"),
        (Os::Mac, Architecture::X8664) => Ok("macos-x64"),
        (Os::Mac, Architecture::Aarch64) => Ok("macos-arm64"),
        (Os::Windows, _) => Err("Symfony Language Tools for Zed does not support Windows".into()),
        (_, Architecture::X86) => {
            Err("Symfony Language Tools does not support 32-bit systems".into())
        }
    }
}

fn release_package(version: &str, os: Os, architecture: Architecture) -> Result<ReleasePackage> {
    let platform = platform_name(os, architecture)?;
    let version = if version.starts_with('v') {
        version.to_string()
    } else {
        format!("v{version}")
    };
    let package_name = format!("symfony-lsp-{version}-{platform}");
    let installation_directory = format!("{INSTALLATION_PREFIX}{version}-{platform}");

    Ok(ReleasePackage {
        asset_name: format!("{package_name}.tar.gz"),
        binary_path: format!("{installation_directory}/{package_name}/symfony-lsp"),
        installation_directory,
    })
}

fn remove_outdated_installations(current: &str) -> Result<()> {
    let entries = fs::read_dir(".")
        .map_err(|error| format!("failed to list extension installations: {error}"))?;
    for entry in entries {
        let entry = entry.map_err(|error| format!("failed to inspect installation: {error}"))?;
        let name = entry.file_name();
        let Some(name) = name.to_str() else {
            continue;
        };
        if name.starts_with(INSTALLATION_PREFIX) && name != current {
            fs::remove_dir_all(entry.path()).ok();
        }
    }

    Ok(())
}

zed::register_extension!(SymfonyLanguageToolsExtension);

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn builds_linux_x64_release_package() {
        assert_eq!(
            ReleasePackage {
                asset_name: "symfony-lsp-v0.9.2-linux-x64.tar.gz".into(),
                installation_directory: "symfony-language-tools-v0.9.2-linux-x64".into(),
                binary_path:
                    "symfony-language-tools-v0.9.2-linux-x64/symfony-lsp-v0.9.2-linux-x64/symfony-lsp"
                        .into(),
            },
            release_package("v0.9.2", Os::Linux, Architecture::X8664).unwrap(),
        );
    }

    #[test]
    fn builds_macos_arm64_release_package() {
        assert_eq!(
            "symfony-lsp-v0.9.2-macos-arm64.tar.gz",
            release_package("0.9.2", Os::Mac, Architecture::Aarch64)
                .unwrap()
                .asset_name,
        );
    }

    #[test]
    fn rejects_windows() {
        assert_eq!(
            "Symfony Language Tools for Zed does not support Windows",
            release_package("v0.9.2", Os::Windows, Architecture::X8664).unwrap_err(),
        );
    }

    #[test]
    fn rejects_32_bit_systems() {
        assert_eq!(
            "Symfony Language Tools does not support 32-bit systems",
            release_package("v0.9.2", Os::Linux, Architecture::X86).unwrap_err(),
        );
    }
}
