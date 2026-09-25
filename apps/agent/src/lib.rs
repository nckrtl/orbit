use rand::Rng;
use serde::{Deserialize, Serialize};
use serde_json::{json, Value};
use std::{
    collections::BTreeMap,
    fs::File,
    io::BufReader,
    net::{IpAddr, SocketAddr},
};
pub mod workspace;
use workspace::WorkspaceState;

pub const CONFIG_PATH: &str = "/etc/orbit/agent/config.toml";
pub const CA_PATH: &str = "/etc/orbit/agent/ca.pem";
/// The agent holds an exclusive lock on this directory, so one Node runs one agent.
pub const LOCK_PATH: &str = "/etc/orbit/agent";
pub const PUSHER_FRAME_LIMIT: usize = 9_900;
pub const CHANGE_MERGE_WINDOW: std::time::Duration = std::time::Duration::from_millis(250);
/// A complete snapshot also goes out this long after the last one, so a lost one heals.
pub const SNAPSHOT_INTERVAL: std::time::Duration = std::time::Duration::from_secs(60);
/// The agent pings Reverb after this long without a message from it.
pub const PING_AFTER: std::time::Duration = std::time::Duration::from_secs(15);
/// The agent reconnects when Reverb sends nothing for this long after its ping.
pub const PONG_TIMEOUT: std::time::Duration = std::time::Duration::from_secs(10);
/// Every step from the TCP connect to `pusher_internal:subscription_succeeded` fits in this time.
pub const JOIN_TIMEOUT: std::time::Duration = std::time::Duration::from_secs(30);
/// A session that stayed joined this long starts the backoff again.
pub const HEALTHY_SESSION: std::time::Duration = std::time::Duration::from_secs(60);

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Config {
    pub gateway_url: String,
    pub gateway_address: IpAddr,
}
impl Config {
    pub fn load() -> Result<Self, Box<dyn std::error::Error + Send + Sync>> {
        let config: Self = toml::from_str(&std::fs::read_to_string(CONFIG_PATH)?)?;
        let url = reqwest::Url::parse(&config.gateway_url)?;
        if url.scheme() != "https"
            || url.host_str() != Some("gateway.orbit")
            || url.port_or_known_default() != Some(443)
        {
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
#[derive(Debug, Clone, Serialize)]
pub struct WorkspacesData {
    pub part: usize,
    pub parts: usize,
    pub workspaces: Vec<WorkspaceState>,
}
#[derive(Debug, Clone, Serialize)]
pub struct WorkspaceData {
    pub workspace: WorkspaceState,
}
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
    let chunks = chunk_for_frames(
        channel,
        "client-snapshot",
        units,
        |units| json!({"sequence":u64::MAX,"at":"9999-12-31T23:59:59.999999Z","docker":docker,"part":usize::MAX,"parts":usize::MAX,"units":units}),
    )?;
    let parts = chunks.len();
    Ok(chunks
        .into_iter()
        .enumerate()
        .map(|(index, units)| SnapshotData {
            docker,
            part: index + 1,
            parts,
            units,
        })
        .collect())
}
pub fn split_workspaces(
    channel: &str,
    workspaces: &[WorkspaceState],
) -> Result<Vec<WorkspacesData>, serde_json::Error> {
    let chunks = chunk_for_frames(
        channel,
        "client-workspaces",
        workspaces,
        |workspaces| json!({"sequence":u64::MAX,"at":"9999-12-31T23:59:59.999999Z","part":usize::MAX,"parts":usize::MAX,"workspaces":workspaces}),
    )?;
    let parts = chunks.len();
    Ok(chunks
        .into_iter()
        .enumerate()
        .map(|(index, workspaces)| WorkspacesData {
            part: index + 1,
            parts,
            workspaces,
        })
        .collect())
}
pub fn workspaces_frames(
    channel: &str,
    sequence: &mut Sequencer,
    workspaces: &[WorkspaceState],
) -> Result<Vec<ClientFrame>, serde_json::Error> {
    split_workspaces(channel, workspaces)?
        .into_iter()
        .map(|part| {
            let payload = Envelope {
                sequence: sequence.advance(),
                at: iso_now(),
                data: part,
            };
            Ok(frame(
                channel,
                "client-workspaces",
                serde_json::to_value(payload)?,
            ))
        })
        .collect()
}
pub fn workspace_frame(
    channel: &str,
    sequence: u64,
    workspace: WorkspaceState,
) -> Result<ClientFrame, serde_json::Error> {
    let payload = Envelope {
        sequence,
        at: iso_now(),
        data: WorkspaceData { workspace },
    };
    Ok(frame(
        channel,
        "client-workspace",
        serde_json::to_value(payload)?,
    ))
}
fn frame_limit_error(message: &'static str) -> serde_json::Error {
    serde_json::Error::io(std::io::Error::new(
        std::io::ErrorKind::InvalidData,
        message,
    ))
}
/// Splits items into the fewest consecutive parts whose worst-case frame stays within the Pusher limit.
/// Always returns at least one part, so an empty list still sends one frame.
fn chunk_for_frames<T: Clone + Serialize>(
    channel: &str,
    event: &str,
    items: &[T],
    probe: impl Fn(&[T]) -> Value,
) -> Result<Vec<Vec<T>>, serde_json::Error> {
    let fits = |items: &[T]| -> Result<bool, serde_json::Error> {
        Ok(serde_json::to_vec(&frame(channel, event, probe(items)))?.len() <= PUSHER_FRAME_LIMIT)
    };
    let mut chunks: Vec<Vec<T>> = Vec::new();
    let mut current = Vec::new();
    for item in items {
        current.push(item.clone());
        if !fits(&current)? {
            let last = current.pop().expect("just appended");
            if current.is_empty() {
                return Err(frame_limit_error(
                    "one entry exceeds the Pusher frame limit",
                ));
            }
            chunks.push(std::mem::take(&mut current));
            current.push(last);
        }
    }
    if !current.is_empty() || chunks.is_empty() {
        chunks.push(current);
    }
    for chunk in &chunks {
        if !fits(chunk)? {
            return Err(frame_limit_error("snapshot part exceeds frame limit"));
        }
    }
    Ok(chunks)
}
pub fn iso_now() -> String {
    time::OffsetDateTime::now_utc()
        .format(&time::format_description::well_known::Rfc3339)
        .unwrap_or_else(|_| "1970-01-01T00:00:00Z".into())
}
/// The attempt number for the retry after a connection ended. `joined_for` is how long the session
/// stayed joined, or `None` when it never joined. Only a session that stayed joined for
/// `HEALTHY_SESSION` starts the backoff again, so a session that fails right after each join keeps
/// backing off. The first retry waits `retry_delay(1)`, about 2 seconds.
pub fn next_attempt(attempt: u32, joined_for: Option<std::time::Duration>) -> u32 {
    if joined_for.is_some_and(|duration| duration >= HEALTHY_SESSION) {
        1
    } else {
        attempt.saturating_add(1)
    }
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
pub fn gateway_client(
    address: IpAddr,
) -> Result<reqwest::Client, Box<dyn std::error::Error + Send + Sync>> {
    let mut reader = BufReader::new(File::open(CA_PATH)?);
    let mut builder = reqwest::Client::builder()
        .connect_timeout(std::time::Duration::from_secs(10))
        .timeout(std::time::Duration::from_secs(30))
        .https_only(true)
        .redirect(reqwest::redirect::Policy::none())
        .tls_built_in_root_certs(false)
        .resolve("gateway.orbit", SocketAddr::new(address, 443));
    for cert in rustls_pemfile::certs(&mut reader) {
        builder = builder.add_root_certificate(reqwest::Certificate::from_der(&cert?)?);
    }
    Ok(builder.build()?)
}

/// Takes the lock that keeps a second agent off the Node. A second agent would publish as the same
/// Reverb member, and Reverb announces neither its join nor its exit, so subscribers would mix two
/// event streams. The lock ends with the process.
pub fn lock_single_instance(path: &str) -> Result<File, Box<dyn std::error::Error + Send + Sync>> {
    let file = File::open(path)?;
    match file.try_lock() {
        Ok(()) => Ok(file),
        Err(std::fs::TryLockError::WouldBlock) => {
            Err("another orbit-agent already runs on this Node".into())
        }
        Err(std::fs::TryLockError::Error(error)) => Err(error.into()),
    }
}

/// Tells a connected session when to ping Reverb and when to give its connection up. The agent
/// only publishes, so on a quiet channel nothing else would show that the connection died.
#[derive(Debug)]
pub struct Liveness {
    last_message: tokio::time::Instant,
    ping_sent: Option<tokio::time::Instant>,
}
#[derive(Debug, PartialEq, Eq)]
pub enum LivenessCheck {
    Alive,
    Ping,
    Dead,
}
impl Liveness {
    pub fn new(now: tokio::time::Instant) -> Self {
        Self {
            last_message: now,
            ping_sent: None,
        }
    }
    /// Reverb sent something: the connection is alive.
    pub fn message(&mut self, now: tokio::time::Instant) {
        self.last_message = now;
        self.ping_sent = None;
    }
    pub fn check(&mut self, now: tokio::time::Instant) -> LivenessCheck {
        match self.ping_sent {
            Some(sent) if now >= sent + PONG_TIMEOUT => LivenessCheck::Dead,
            Some(_) => LivenessCheck::Alive,
            None if now >= self.last_message + PING_AFTER => {
                self.ping_sent = Some(now);
                LivenessCheck::Ping
            }
            None => LivenessCheck::Alive,
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn a_second_agent_cannot_take_the_lock_until_the_first_one_ends() {
        let dir = std::env::temp_dir().join(format!("orbit-agent-lock-{}", std::process::id()));
        std::fs::create_dir_all(&dir).unwrap();
        let path = dir.to_str().unwrap();
        let first = lock_single_instance(path).unwrap();
        let refused = lock_single_instance(path).unwrap_err();
        assert!(refused.to_string().contains("another orbit-agent"));
        drop(first);
        assert!(lock_single_instance(path).is_ok());
        std::fs::remove_dir_all(&dir).unwrap();
    }
    #[test]
    fn only_a_session_that_stayed_joined_starts_the_backoff_again() {
        assert_eq!(next_attempt(5, None), 6);
        assert_eq!(next_attempt(5, Some(std::time::Duration::from_secs(1))), 6);
        assert_eq!(
            next_attempt(
                5,
                Some(HEALTHY_SESSION - std::time::Duration::from_millis(1))
            ),
            6
        );
        assert_eq!(next_attempt(5, Some(HEALTHY_SESSION)), 1);
        assert_eq!(next_attempt(u32::MAX, None), u32::MAX);
        // A session that fails right after every join keeps backing off up to 30 seconds.
        let mut attempt = 0;
        for _ in 0..10 {
            attempt = next_attempt(attempt, Some(std::time::Duration::from_secs(1)));
        }
        assert!(retry_delay(attempt) >= std::time::Duration::from_secs(30));
        assert!(
            retry_delay(next_attempt(attempt, Some(HEALTHY_SESSION)))
                < std::time::Duration::from_secs(3)
        );
    }
    #[test]
    fn liveness_pings_after_a_quiet_spell_and_gives_up_without_an_answer() {
        let start = tokio::time::Instant::now();
        let mut liveness = Liveness::new(start);
        assert_eq!(liveness.check(start + PING_AFTER / 2), LivenessCheck::Alive);
        assert_eq!(liveness.check(start + PING_AFTER), LivenessCheck::Ping);
        assert_eq!(
            liveness.check(start + PING_AFTER + PONG_TIMEOUT / 2),
            LivenessCheck::Alive
        );
        liveness.message(start + PING_AFTER + PONG_TIMEOUT / 2);
        assert_eq!(
            liveness.check(start + PING_AFTER + PONG_TIMEOUT),
            LivenessCheck::Alive
        );
        let pinged = start + PING_AFTER * 2 + PONG_TIMEOUT;
        assert_eq!(liveness.check(pinged), LivenessCheck::Ping);
        assert_eq!(liveness.check(pinged + PONG_TIMEOUT), LivenessCheck::Dead);
    }
    fn unit(name: &str, status: &str, runtime: &str) -> Unit {
        Unit {
            name: name.into(),
            runtime: runtime.into(),
            runtime_status: status.into(),
        }
    }
    #[test]
    fn gateway_address_is_required_and_parsed_as_an_ip_literal() {
        assert!(toml::from_str::<Config>(r#"gateway_url = "https://gateway.orbit""#).is_err());

        let config: Config = toml::from_str(
            r#"gateway_url = "https://gateway.orbit"
gateway_address = "10.44.0.1""#,
        )
        .unwrap();

        assert_eq!(
            config.gateway_address,
            "10.44.0.1".parse::<IpAddr>().unwrap()
        );
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
    fn workspace(id: u64) -> WorkspaceState {
        WorkspaceState {
            instance_id: id,
            base: format!("\"{}", "b".repeat(254)),
            start: Some("a".repeat(40)),
            branch: Some(format!("\u{1}{}", "t".repeat(254))),
            head: Some("f".repeat(40)),
            dirty: Some(true),
            commits: Some(1000),
            diff: Some(workspace::DiffCounts {
                files: 5000,
                added: u64::MAX,
                removed: u64::MAX,
                truncated: false,
            }),
        }
    }
    #[test]
    fn workspace_list_frames_fit_limit_with_consecutive_sequences() {
        let workspaces = (1..=64).map(workspace).collect::<Vec<_>>();
        let mut seq = Sequencer::default();
        seq.advance();
        let frames = workspaces_frames("presence-node.12", &mut seq, &workspaces).unwrap();
        assert!(frames.len() > 1);
        let mut seen = Vec::new();
        for (index, frame) in frames.iter().enumerate() {
            assert_eq!(frame.event, "client-workspaces");
            assert_eq!(frame.channel, "presence-node.12");
            assert!(serde_json::to_vec(frame).unwrap().len() <= PUSHER_FRAME_LIMIT);
            assert_eq!(frame.data["sequence"], index as u64 + 2);
            assert_eq!(frame.data["part"], index + 1);
            assert_eq!(frame.data["parts"], frames.len());
            assert!(frame.data["at"].is_string());
            for w in frame.data["workspaces"].as_array().unwrap() {
                seen.push(serde_json::from_value::<WorkspaceState>(w.clone()).unwrap());
            }
        }
        assert_eq!(seen, workspaces);
        assert_eq!(seq.current(), frames.len() as u64 + 1);
    }
    #[test]
    fn empty_workspace_list_is_one_frame_and_one_change_is_one_event() {
        let mut seq = Sequencer::default();
        let frames = workspaces_frames("presence-node.3", &mut seq, &[]).unwrap();
        assert_eq!(frames.len(), 1);
        assert_eq!(frames[0].data["part"], 1);
        assert_eq!(frames[0].data["parts"], 1);
        assert_eq!(frames[0].data["workspaces"], json!([]));
        let event = workspace_frame("presence-node.3", 9, workspace(7)).unwrap();
        assert_eq!(event.event, "client-workspace");
        assert_eq!(event.data["sequence"], 9);
        assert_eq!(event.data["workspace"]["instance_id"], 7);
        assert_eq!(
            event.data.as_object().unwrap().keys().collect::<Vec<_>>(),
            ["at", "sequence", "workspace"]
        );
        assert!(serde_json::to_vec(&event).unwrap().len() <= PUSHER_FRAME_LIMIT);
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
