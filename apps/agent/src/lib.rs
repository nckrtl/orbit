use rand::Rng;
use serde::{Deserialize, Serialize};
use serde_json::{json, Value};
use std::{collections::BTreeMap, fs::File, io::BufReader};

pub const CONFIG_PATH: &str = "/etc/orbit/agent/config.toml";
pub const CA_PATH: &str = "/etc/orbit/agent/ca.pem";
pub const PUSHER_FRAME_LIMIT: usize = 9_900;
pub const CHANGE_MERGE_WINDOW: std::time::Duration = std::time::Duration::from_millis(250);

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Config {
    pub gateway_url: String,
}
impl Config {
    pub fn load() -> Result<Self, Box<dyn std::error::Error + Send + Sync>> {
        let config: Self = toml::from_str(&std::fs::read_to_string(CONFIG_PATH)?)?;
        let url = reqwest::Url::parse(&config.gateway_url)?;
        if url.scheme() != "https" || url.host_str() != Some("gateway.orbit") {
            return Err("gateway_url must be https://gateway.orbit".into());
        }
        Ok(config)
    }
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Eq, PartialOrd, Ord)]
pub struct Unit {
    pub name: String,
    pub runtime: String,
    pub runtime_status: String,
}
pub fn process_unit_name(name: &str) -> Option<String> {
    let name = name.strip_suffix(".service").unwrap_or(name);
    (name.starts_with("orbit-process-") && name.len() > "orbit-process-".len())
        .then(|| name.to_owned())
}
pub fn docker_container_name(names: &[String]) -> Option<String> {
    names
        .iter()
        .map(|name| name.trim_start_matches('/'))
        .find_map(process_unit_name)
}
pub fn docker_status(state: &str) -> &'static str {
    match state {
        "running" => "running",
        "exited" => "exited",
        "restarting" => "restarting",
        "paused" => "paused",
        "created" => "created",
        "dead" => "dead",
        _ => "exited",
    }
}
#[derive(Debug, Clone, Serialize)]
pub struct Envelope<T> {
    pub sequence: u64,
    pub at: String,
    #[serde(flatten)]
    pub data: T,
}
#[derive(Debug, Clone, Serialize)]
pub struct ProcessData {
    pub unit: Unit,
}
#[derive(Debug, Clone, Serialize)]
pub struct SnapshotData {
    pub docker: &'static str,
    pub part: usize,
    pub parts: usize,
    pub units: Vec<Unit>,
}
#[derive(Debug, Clone, Serialize)]
pub struct HeartbeatData {}
#[derive(Debug, Clone, Serialize, Deserialize, PartialEq)]
pub struct ClientFrame {
    pub event: String,
    pub channel: String,
    pub data: Value,
}

#[derive(Default)]
pub struct Sequencer(u64);
impl Sequencer {
    pub fn advance(&mut self) -> u64 {
        self.0 += 1;
        self.0
    }
    pub fn current(&self) -> u64 {
        self.0
    }
}

pub fn coalesce(changes: impl IntoIterator<Item = Unit>) -> Vec<Unit> {
    let mut latest = BTreeMap::new();
    for unit in changes {
        latest.insert((unit.runtime.clone(), unit.name.clone()), unit);
    }
    latest.into_values().collect()
}

#[derive(Default)]
pub struct ChangeBatch {
    pending: BTreeMap<(String, String), Unit>,
    first_change: Option<tokio::time::Instant>,
}
impl ChangeBatch {
    /// Returns true only when this push starts an empty batch and sets its fixed deadline.
    pub fn push(&mut self, unit: Unit, now: tokio::time::Instant) -> bool {
        let starts_batch = self.pending.is_empty();
        if starts_batch {
            self.first_change = Some(now);
        }
        self.pending
            .insert((unit.runtime.clone(), unit.name.clone()), unit);
        starts_batch
    }
    pub fn due(&self, now: tokio::time::Instant) -> bool {
        self.first_change
            .is_some_and(|start| now >= start + CHANGE_MERGE_WINDOW)
    }
    pub fn is_empty(&self) -> bool {
        self.pending.is_empty()
    }
    pub fn take(&mut self) -> Vec<Unit> {
        self.first_change = None;
        coalesce(std::mem::take(&mut self.pending).into_values())
    }
}
pub fn frame(channel: &str, event: &str, data: Value) -> ClientFrame {
    ClientFrame {
        event: event.into(),
        channel: channel.into(),
        data,
    }
}
pub fn process_frame(
    channel: &str,
    sequence: u64,
    unit: Unit,
) -> Result<ClientFrame, serde_json::Error> {
    let payload = Envelope {
        sequence,
        at: iso_now(),
        data: ProcessData { unit },
    };
    Ok(frame(
        channel,
        "client-process",
        serde_json::to_value(payload)?,
    ))
}
pub fn snapshot_frames(
    channel: &str,
    sequence: &mut Sequencer,
    units: &[Unit],
    docker: &'static str,
) -> Result<Vec<ClientFrame>, serde_json::Error> {
    let chunks = split_snapshot(channel, units, docker)?;
    chunks
        .into_iter()
        .map(|part| {
            let payload = Envelope {
                sequence: sequence.advance(),
                at: iso_now(),
                data: part,
            };
            Ok(frame(
                channel,
                "client-snapshot",
                serde_json::to_value(payload)?,
            ))
        })
        .collect()
}
pub fn split_snapshot(
    channel: &str,
    units: &[Unit],
    docker: &'static str,
) -> Result<Vec<SnapshotData>, serde_json::Error> {
    let mut chunks: Vec<Vec<Unit>> = Vec::new();
    let mut current = Vec::new();
    for unit in units {
        current.push(unit.clone());
        let probe = SnapshotData {
            docker,
            part: usize::MAX,
            parts: usize::MAX,
            units: current.clone(),
        };
        let frame = frame(
            channel,
            "client-snapshot",
            json!({"sequence":u64::MAX,"at":"9999-12-31T23:59:59.999999Z","docker":probe.docker,"part":probe.part,"parts":probe.parts,"units":probe.units}),
        );
        if serde_json::to_vec(&frame)?.len() > PUSHER_FRAME_LIMIT {
            let last = current.pop().expect("just appended");
            if current.is_empty() {
                return Err(serde_json::Error::io(std::io::Error::new(
                    std::io::ErrorKind::InvalidData,
                    "one unit exceeds the Pusher frame limit",
                )));
            }
            chunks.push(std::mem::take(&mut current));
            current.push(last);
        }
    }
    if !current.is_empty() || chunks.is_empty() {
        chunks.push(current);
    }
    let parts = chunks.len();
    let snapshots = chunks
        .into_iter()
        .enumerate()
        .map(|(index, units)| SnapshotData {
            docker,
            part: index + 1,
            parts,
            units,
        })
        .collect::<Vec<_>>();
    for part in &snapshots {
        let probe = frame(
            channel,
            "client-snapshot",
            json!({"sequence":u64::MAX,"at":"9999-12-31T23:59:59.999999Z","docker":part.docker,"part":usize::MAX,"parts":usize::MAX,"units":part.units}),
        );
        if serde_json::to_vec(&probe)?.len() > PUSHER_FRAME_LIMIT {
            return Err(serde_json::Error::io(std::io::Error::new(
                std::io::ErrorKind::InvalidData,
                "snapshot part exceeds frame limit",
            )));
        }
    }
    Ok(snapshots)
}
pub fn iso_now() -> String {
    time::OffsetDateTime::now_utc()
        .format(&time::format_description::well_known::Rfc3339)
        .unwrap_or_else(|_| "1970-01-01T00:00:00Z".into())
}
pub fn retry_delay(attempt: u32) -> std::time::Duration {
    let base = 1_u64.checked_shl(attempt.min(5)).unwrap_or(30).min(30);
    let jitter = rand::thread_rng().gen_range(0..=base / 4);
    std::time::Duration::from_secs((base + jitter).min(30))
}
pub fn tls_config(
) -> Result<std::sync::Arc<rustls::ClientConfig>, Box<dyn std::error::Error + Send + Sync>> {
    let mut roots = rustls::RootCertStore::empty();
    let mut reader = BufReader::new(File::open(CA_PATH)?);
    let certs = rustls_pemfile::certs(&mut reader).collect::<Result<Vec<_>, _>>()?;
    if certs.is_empty() {
        return Err("CA bundle contains no certificates".into());
    }
    for cert in certs {
        roots.add(cert)?;
    }
    Ok(std::sync::Arc::new(
        rustls::ClientConfig::builder()
            .with_root_certificates(roots)
            .with_no_client_auth(),
    ))
}
pub fn gateway_client() -> Result<reqwest::Client, Box<dyn std::error::Error + Send + Sync>> {
    let mut reader = BufReader::new(File::open(CA_PATH)?);
    let mut builder = reqwest::Client::builder()
        .https_only(true)
        .tls_built_in_root_certs(false);
    for cert in rustls_pemfile::certs(&mut reader) {
        builder = builder.add_root_certificate(reqwest::Certificate::from_der(&cert?)?);
    }
    Ok(builder.build()?)
}

#[cfg(test)]
mod tests {
    use super::*;
    fn unit(name: &str, status: &str, runtime: &str) -> Unit {
        Unit {
            name: name.into(),
            runtime: runtime.into(),
            runtime_status: status.into(),
        }
    }
    #[test]
    fn names_filter_and_parse() {
        assert_eq!(
            process_unit_name("orbit-process-9-api.service").as_deref(),
            Some("orbit-process-9-api")
        );
        assert!(process_unit_name("ssh.service").is_none());
        assert_eq!(
            docker_container_name(&["/other".into(), "/orbit-process-3-web".into()]).as_deref(),
            Some("orbit-process-3-web")
        );
    }
    #[test]
    fn state_vocabularies() {
        assert_eq!(docker_status("running"), "running");
        assert_eq!(docker_status("unknown"), "exited");
    }
    #[test]
    fn merges_updates_to_latest_value() {
        assert_eq!(
            coalesce([
                unit("orbit-process-1-web", "active", "systemd"),
                unit("orbit-process-1-web", "failed", "systemd")
            ])[0]
                .runtime_status,
            "failed"
        );
    }
    #[test]
    fn serialized_snapshot_frames_fit_limit() {
        let units = (0..1000)
            .map(|n| {
                unit(
                    &format!("orbit-process-{n}-{}", "x".repeat(35)),
                    "active",
                    "systemd",
                )
            })
            .collect::<Vec<_>>();
        let parts = split_snapshot("presence-node.12", &units, "available").unwrap();
        assert!(parts.len() > 1);
        let mut seq = Sequencer::default();
        let frames = snapshot_frames("presence-node.12", &mut seq, &units, "available").unwrap();
        assert_eq!(frames.len(), parts.len());
        for (index, frame) in frames.iter().enumerate() {
            assert_eq!(frame.data["sequence"], index as u64 + 1);
            assert!(serde_json::to_vec(frame).unwrap().len() <= PUSHER_FRAME_LIMIT);
            assert_eq!(frame.channel, "presence-node.12");
        }
    }
    #[tokio::test(start_paused = true)]
    async fn two_changes_merge_to_latest_state_after_250ms() {
        let mut batch = ChangeBatch::default();
        let start = tokio::time::Instant::now();
        assert!(batch.push(unit("orbit-process-1-web", "starting", "docker"), start));
        tokio::time::advance(std::time::Duration::from_millis(100)).await;
        assert!(!batch.push(
            unit("orbit-process-1-web", "running", "docker"),
            tokio::time::Instant::now()
        ));
        tokio::time::advance(std::time::Duration::from_millis(149)).await;
        assert!(!batch.due(tokio::time::Instant::now()));
        tokio::time::advance(std::time::Duration::from_millis(1)).await;
        assert!(batch.due(tokio::time::Instant::now()));
        let latest = batch.take();
        assert_eq!(latest.len(), 1);
        assert_eq!(latest[0].runtime_status, "running");
        assert!(batch.is_empty());
    }

    #[tokio::test(start_paused = true)]
    async fn continuous_changes_flush_250ms_after_the_first_change() {
        let mut batch = ChangeBatch::default();
        batch.push(
            unit("orbit-process-1-web", "starting", "docker"),
            tokio::time::Instant::now(),
        );
        for status in ["running", "restarting"] {
            tokio::time::advance(std::time::Duration::from_millis(100)).await;
            assert!(!batch.push(
                unit("orbit-process-1-web", status, "docker"),
                tokio::time::Instant::now()
            ));
        }
        tokio::time::advance(std::time::Duration::from_millis(49)).await;
        assert!(!batch.due(tokio::time::Instant::now()));
        tokio::time::advance(std::time::Duration::from_millis(1)).await;
        assert!(batch.due(tokio::time::Instant::now()));
        assert_eq!(batch.take()[0].runtime_status, "restarting");
    }
    #[test]
    fn snapshot_parts_have_consecutive_sequences() {
        let mut sequence = Sequencer::default();
        let frames = snapshot_frames(
            "presence-node.1",
            &mut sequence,
            &(0..800)
                .map(|i| {
                    unit(
                        &format!("orbit-process-{i}-{}", "x".repeat(50)),
                        "active",
                        "systemd",
                    )
                })
                .collect::<Vec<_>>(),
            "absent",
        )
        .unwrap();
        assert!(frames.len() > 1);
        for (index, f) in frames.iter().enumerate() {
            assert_eq!(f.data["sequence"], index as u64 + 1);
        }
    }
}
