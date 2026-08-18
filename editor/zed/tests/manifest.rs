const EXTENSION_MANIFEST: &str = include_str!("../extension.toml");
const PROJECT_LICENSE: &str = include_str!("../../../LICENSE");
const EXTENSION_LICENSE: &str = include_str!("../LICENSE");
const ZED_EXTENSION_API_LICENSE: &[u8] =
    include_bytes!("../THIRD_PARTY_LICENSES/zed_extension_api/LICENSE-APACHE");

#[test]
fn extension_manifest_matches_the_package() {
    assert_eq!(
        "\"symfony-language-tools\"",
        top_level_value(EXTENSION_MANIFEST, "id"),
    );
    assert_eq!(
        format!("\"{}\"", env!("CARGO_PKG_VERSION")),
        top_level_value(EXTENSION_MANIFEST, "version"),
    );
    assert_eq!("1", top_level_value(EXTENSION_MANIFEST, "schema_version"));
    assert_eq!(
        vec![
            "PHP",
            "Twig",
            "YAML",
            "JSON",
            "XML",
            "JavaScript",
            "TypeScript",
        ],
        section_array(
            EXTENSION_MANIFEST,
            "language_servers.symfony-language-tools",
            "languages",
        ),
    );
}

#[test]
fn extension_licenses_are_current() {
    assert_eq!(PROJECT_LICENSE, EXTENSION_LICENSE);
    assert!(ZED_EXTENSION_API_LICENSE.len() > 10_000);
}

fn top_level_value<'a>(document: &'a str, key: &str) -> &'a str {
    let prefix = format!("{key} = ");

    document
        .lines()
        .take_while(|line| !line.starts_with('['))
        .find_map(|line| line.strip_prefix(&prefix))
        .unwrap_or_else(|| panic!("missing top-level {key} value"))
}

fn section_array<'a>(document: &'a str, section: &str, key: &str) -> Vec<&'a str> {
    let section_header = format!("[{section}]");
    let array_header = format!("{key} = [");
    let mut in_section = false;
    let mut in_array = false;
    let mut values = Vec::new();

    for line in document.lines() {
        if line == section_header {
            in_section = true;
            continue;
        }
        if in_section && line.starts_with('[') {
            break;
        }
        if !in_section {
            continue;
        }
        if line == array_header {
            in_array = true;
            continue;
        }
        if !in_array {
            continue;
        }
        if line == "]" {
            return values;
        }

        let value = line
            .trim()
            .trim_end_matches(',')
            .strip_prefix('"')
            .and_then(|value| value.strip_suffix('"'))
            .unwrap_or_else(|| panic!("invalid {key} array value"));
        values.push(value);
    }

    panic!("missing {key} array in {section}")
}
