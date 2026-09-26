//! Live log tails (ADR 0153). The agent reads a log only while the Gateway's HTTPS stream list names it,
//! redacts each line, and sends the lines on `presence-node-logs.{id}`. It runs no program for any source.
pub mod controller;
pub mod docker;
pub mod events;
pub mod journal;
#[cfg(test)]
mod journal_writer;
pub mod laravel;
pub mod limits;
pub mod redact;

use serde_json::Value;
use std::collections::BTreeSet;

pub const MAX_STREAMS: usize = 16;
pub const MAX_LINES: u64 = 1_000;
pub const MAX_PATH_BYTES: usize = 4_096;

#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Source {
    Laravel { path: String },
    Journal { unit: String },
    Docker { container: String, process_id: u64 },
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct StreamSpec {
    pub id: String,
    pub lines: usize,
    pub source: Source,
}

/// Exactly 32 lowercase hexadecimal characters.
pub fn valid_stream_id(id: &str) -> bool {
    id.len() == 32
        && id
            .bytes()
            .all(|b| b.is_ascii_digit() || (b'a'..=b'f').contains(&b))
}

/// A normalized absolute path: at most 4,096 bytes, no empty, `.`, or `..` part, no trailing slash,
/// and no control character.
pub fn valid_checkout_path(path: &str) -> bool {
    path.len() <= MAX_PATH_BYTES
        && path.len() > 1
        && path.starts_with('/')
        && !path.bytes().any(|b| b < 0x20 || b == 0x7f)
        && path[1..]
            .split('/')
            .all(|part| !part.is_empty() && part != "." && part != "..")
}

/// Splits `orbit-process-{id}-{name}` into its ID digits and name, with the Gateway's rules: a positive
/// ID without a leading zero, and a name of lowercase letters, digits, and inner hyphens.
fn process_name(value: &str) -> Option<&str> {
    let rest = value.strip_prefix("orbit-process-")?;
    let (id, name) = rest.split_once('-')?;
    let id_ok = id
        .bytes()
        .next()
        .is_some_and(|b| (b'1'..=b'9').contains(&b))
        && id.bytes().all(|b| b.is_ascii_digit());
    let lower = |b: u8| b.is_ascii_lowercase() || b.is_ascii_digit();
    let name_ok = name.bytes().next().is_some_and(lower)
        && name.bytes().last().is_some_and(lower)
        && name.bytes().all(|b| lower(b) || b == b'-');
    (id_ok && name_ok).then_some(id)
}

/// `orbit-process-{id}-{name}.service`.
pub fn valid_journal_unit(unit: &str) -> bool {
    unit.strip_suffix(".service")
        .and_then(process_name)
        .is_some()
}

/// `orbit-process-{id}-{name}` whose ID equals `process_id`.
pub fn valid_docker_container(container: &str, process_id: u64) -> bool {
    process_id > 0 && process_name(container) == Some(process_id.to_string().as_str())
}

fn parse_source(value: &Value) -> Option<Source> {
    let object = value.as_object()?;
    match object.get("type")?.as_str()? {
        "laravel" => {
            let path = object.get("path")?.as_str()?;
            valid_checkout_path(path).then(|| Source::Laravel { path: path.into() })
        }
        "journal" => {
            let unit = object.get("unit")?.as_str()?;
            valid_journal_unit(unit).then(|| Source::Journal { unit: unit.into() })
        }
        "docker" => {
            let container = object.get("container")?.as_str()?;
            let process_id = object.get("process_id")?.as_u64()?;
            valid_docker_container(container, process_id).then(|| Source::Docker {
                container: container.into(),
                process_id,
            })
        }
        _ => None,
    }
}

fn parse_entry(value: &Value) -> Result<StreamSpec, Option<String>> {
    let id = value
        .get("id")
        .and_then(Value::as_str)
        .filter(|id| valid_stream_id(id))
        .ok_or(None)?;
    let invalid = || Some(id.to_owned());
    let lines = value
        .get("lines")
        .and_then(Value::as_u64)
        .filter(|lines| (1..=MAX_LINES).contains(lines))
        .ok_or_else(invalid)?;
    let source = value
        .get("source")
        .and_then(parse_source)
        .ok_or_else(invalid)?;
    Ok(StreamSpec {
        id: id.into(),
        lines: lines as usize,
        source,
    })
}

/// Parses `GET /api/v1/agent/log-streams`. Invalid entries are skipped one by one; a repeated ID keeps
/// its first entry; at most 16 streams are returned.
pub fn parse_stream_list(body: &Value) -> Result<Vec<StreamSpec>, &'static str> {
    let data = body
        .get("data")
        .and_then(Value::as_array)
        .ok_or("log stream list has no data array")?;
    let mut seen = BTreeSet::new();
    let mut streams = Vec::new();
    for entry in data {
        match parse_entry(entry) {
            Ok(spec) if seen.insert(spec.id.clone()) => streams.push(spec),
            Ok(_) => {}
            Err(Some(id)) => eprintln!("orbit-agent: skipped invalid log stream {id}"),
            Err(None) => eprintln!("orbit-agent: skipped a log stream entry without a valid ID"),
        }
    }
    streams.truncate(MAX_STREAMS);
    Ok(streams)
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    const ID: &str = "3f9c2a6b0d1e4f5a8b7c6d5e4f3a2b1c";

    #[test]
    fn stream_ids_are_32_lowercase_hex() {
        assert!(valid_stream_id(ID));
        assert!(!valid_stream_id(&ID.to_uppercase()));
        assert!(!valid_stream_id(&ID[1..]));
        assert!(!valid_stream_id(&format!("{ID}0")));
        assert!(!valid_stream_id("3f9c2a6b0d1e4f5a8b7c6d5e4f3a2b1g"));
    }

    #[test]
    fn checkout_paths_must_be_normalized_and_absolute() {
        assert!(valid_checkout_path("/home/orbit/apps/shop/main"));
        assert!(valid_checkout_path("/a"));
        for bad in [
            "",
            "/",
            "relative/path",
            "/home/../etc",
            "/home/./orbit",
            "/home//orbit",
            "/home/orbit/",
            "/..",
            "/.",
            "/home/orbit/..",
            "/home/orb\nit",
            "/home/orb\0it",
            "/home/orb\x7fit",
            "/home/orb\x1bit",
        ] {
            assert!(!valid_checkout_path(bad), "{bad:?}");
        }
        assert!(valid_checkout_path(&format!("/{}", "a".repeat(4095))));
        assert!(!valid_checkout_path(&format!("/{}", "a".repeat(4096))));
        assert!(valid_checkout_path("/home/orbit/..hidden/x..y/.z"));
    }

    #[test]
    fn journal_units_follow_the_gateway_rules() {
        assert!(valid_journal_unit("orbit-process-41-queue.service"));
        assert!(valid_journal_unit("orbit-process-1-a.service"));
        assert!(valid_journal_unit("orbit-process-10-web-api-2.service"));
        assert!(valid_journal_unit("orbit-process-10-a--b.service"));
        for bad in [
            "orbit-process-41-queue",
            "orbit-process-0-queue.service",
            "orbit-process-041-queue.service",
            "orbit-process--queue.service",
            "orbit-process-41-.service",
            "orbit-process-41-queue-.service",
            "orbit-process-41--queue.service",
            "orbit-process-41-Queue.service",
            "orbit-process-41-que_ue.service",
            "orbit-process-41-../x.service",
            "ssh.service",
            "orbit-process-4a-queue.service",
            "orbit-process-41-queue.service.service",
        ] {
            assert!(!valid_journal_unit(bad), "{bad}");
        }
    }

    #[test]
    fn docker_containers_must_carry_their_process_id() {
        assert!(valid_docker_container("orbit-process-42-web", 42));
        assert!(!valid_docker_container("orbit-process-42-web", 43));
        assert!(!valid_docker_container("orbit-process-42-web", 0));
        assert!(!valid_docker_container("/orbit-process-42-web", 42));
        assert!(!valid_docker_container("orbit-process-042-web", 42));
        assert!(!valid_docker_container("orbit-process-42-web.service", 42));
        assert!(!valid_docker_container("orbit-process-42-Web", 42));
    }

    #[test]
    fn list_skips_invalid_entries_one_by_one_and_keeps_16() {
        let mut data = vec![
            json!({"id": ID, "lines": 100, "source": {"type": "laravel", "path": "/home/orbit/apps/shop/main"}}),
            json!({"id": "8a1d0c2e4b6f4a3c9e7d5b1a0f2c4e6d", "lines": 500, "source": {"type": "journal", "unit": "orbit-process-41-queue.service"}}),
            json!({"id": "c0ffee00c0ffee00c0ffee00c0ffee00", "lines": 100, "source": {"type": "docker", "container": "orbit-process-42-web", "process_id": 42}}),
            json!({"id": "c0ffee00c0ffee00c0ffee00c0ffee01", "lines": 0, "source": {"type": "journal", "unit": "orbit-process-41-queue.service"}}),
            json!({"id": "c0ffee00c0ffee00c0ffee00c0ffee02", "lines": 1001, "source": {"type": "journal", "unit": "orbit-process-41-queue.service"}}),
            json!({"id": "c0ffee00c0ffee00c0ffee00c0ffee03", "lines": 1, "source": {"type": "laravel", "path": "/home/../etc"}}),
            json!({"id": "c0ffee00c0ffee00c0ffee00c0ffee04", "lines": 1, "source": {"type": "docker", "container": "orbit-process-42-web", "process_id": 41}}),
            json!({"id": "c0ffee00c0ffee00c0ffee00c0ffee05", "lines": 1, "source": {"type": "file", "path": "/etc/shadow"}}),
            json!({"id": "C0FFEE00C0FFEE00C0FFEE00C0FFEE06", "lines": 1, "source": {"type": "journal", "unit": "orbit-process-41-queue.service"}}),
            json!({"id": ID, "lines": 1, "source": {"type": "journal", "unit": "orbit-process-41-queue.service"}}),
            json!("not an object"),
        ];
        for n in 0..20 {
            data.push(json!({"id": format!("{n:032x}"), "lines": 1, "source": {"type": "journal", "unit": "orbit-process-41-queue.service"}}));
        }
        let streams = parse_stream_list(&json!({"data": data, "meta": {}})).unwrap();
        assert_eq!(streams.len(), 16);
        assert_eq!(
            streams[0],
            StreamSpec {
                id: ID.into(),
                lines: 100,
                source: Source::Laravel {
                    path: "/home/orbit/apps/shop/main".into()
                }
            }
        );
        assert_eq!(
            streams[2].source,
            Source::Docker {
                container: "orbit-process-42-web".into(),
                process_id: 42
            }
        );
        assert_eq!(streams[3].id, format!("{:032x}", 0));
        assert!(parse_stream_list(&json!({"data": {}})).is_err());
    }
}
