//! Starts and stops log streams from the Gateway's HTTPS stream list (ADR 0153), and hands their events
//! to the realtime connection. A channel message only prompts a fetch; it never names what to read.
use super::{
    docker, journal, laravel,
    laravel::Unavailable,
    limits::{
        agent_bucket, AgentBucket, LineSink, StreamQueue, LIST_UNAVAILABLE, SOURCE_UNAVAILABLE,
    },
    parse_stream_list, Source, StreamSpec,
};
use crate::ClientFrame;
use serde_json::Value;
use std::{
    collections::{BTreeMap, BTreeSet},
    future::Future,
    sync::{Arc, Mutex, MutexGuard},
    time::Duration,
};
use tokio::{
    sync::mpsc,
    task::JoinHandle,
    time::{Instant, MissedTickBehavior},
};

/// While at least one stream runs, the list is fetched again this often.
pub const REFRESH: Duration = Duration::from_secs(15);
/// Without a list for this long, every stream stops.
pub const FAIL_CLOSED: Duration = Duration::from_secs(60);
pub const FETCH_TIMEOUT: Duration = Duration::from_secs(10);

#[derive(Debug, Default)]
struct Registry {
    streams: BTreeMap<String, Arc<StreamQueue>>,
    /// Streams whose end was sent, with its reason. They are not started again while the list still
    /// names them; the end is sent again instead, in case it was lost.
    ended: BTreeMap<String, &'static str>,
}

/// Shared between the controller and the realtime connection. It outlives reconnects.
#[derive(Clone)]
pub struct LogHub {
    registry: Arc<Mutex<Registry>>,
    prompt: mpsc::UnboundedSender<()>,
    agent: AgentBucket,
}

impl LogHub {
    pub fn new() -> (Self, mpsc::UnboundedReceiver<()>) {
        let (prompt, prompts) = mpsc::unbounded_channel();
        (
            Self {
                registry: Arc::default(),
                prompt,
                agent: agent_bucket(),
            },
            prompts,
        )
    }

    fn registry(&self) -> MutexGuard<'_, Registry> {
        self.registry
            .lock()
            .unwrap_or_else(|poisoned| poisoned.into_inner())
    }

    /// Asks the controller to fetch the stream list. Prompts coalesce.
    pub fn prompt(&self) {
        let _ = self.prompt.send(());
    }

    pub fn running(&self) -> usize {
        self.registry().streams.len()
    }

    /// The events that are due on the log channel at `now`, at most one batch per stream every 250 ms.
    pub fn flush(
        &self,
        channel: &str,
        now: Instant,
    ) -> Result<Vec<ClientFrame>, serde_json::Error> {
        let streams: Vec<_> = self.registry().streams.values().cloned().collect();
        let mut frames = Vec::new();
        for queue in streams {
            let flush = queue.flush(channel, now)?;
            frames.extend(flush.frames);
            if flush.finished {
                let mut registry = self.registry();
                registry.streams.remove(&queue.id);
                let reason = queue.end_reason().unwrap_or(SOURCE_UNAVAILABLE);
                registry.ended.insert(queue.id.clone(), reason);
            }
        }
        Ok(frames)
    }
}

/// Where the stream list comes from: the Gateway in production, a fake in tests.
pub trait ListSource: Send + Sync {
    fn fetch(&self) -> impl Future<Output = Result<Value, String>> + Send;
}

pub struct GatewayList {
    pub client: reqwest::Client,
    pub gateway: String,
}
impl ListSource for GatewayList {
    async fn fetch(&self) -> Result<Value, String> {
        let url = format!(
            "{}/api/v1/agent/log-streams",
            self.gateway.trim_end_matches('/')
        );
        let response = self
            .client
            .get(url)
            .timeout(FETCH_TIMEOUT)
            .send()
            .await
            .map_err(|error| error.without_url().to_string())?
            .error_for_status()
            .map_err(|error| error.without_url().to_string())?;
        response
            .json()
            .await
            .map_err(|error| error.without_url().to_string())
    }
}

/// Starts the reader of one stream.
pub fn spawn_stream(spec: StreamSpec, queue: Arc<StreamQueue>) -> JoinHandle<()> {
    tokio::spawn(async move {
        let mut sink = LineSink::new(queue.clone(), spec.lines);
        let result = match spec.source {
            Source::Laravel { path } => laravel::run(path, &mut sink).await,
            Source::Journal { unit } => journal::run(unit, &mut sink).await,
            Source::Docker {
                container,
                process_id,
            } => docker::run(container, process_id, &mut sink).await,
        };
        if let Err(Unavailable(what)) = result {
            eprintln!(
                "orbit-agent: log stream {} ended: {what} unavailable",
                spec.id
            );
            queue.end(SOURCE_UNAVAILABLE);
        }
    })
}

struct Controller<F> {
    hub: LogHub,
    running: BTreeMap<String, JoinHandle<()>>,
    start: F,
}

impl<F: FnMut(StreamSpec, Arc<StreamQueue>) -> JoinHandle<()>> Controller<F> {
    fn reconcile(&mut self, specs: Vec<StreamSpec>) {
        let listed: BTreeSet<String> = specs.iter().map(|spec| spec.id.clone()).collect();
        let unlisted: Vec<String> = self
            .running
            .keys()
            .filter(|id| !listed.contains(*id))
            .cloned()
            .collect();
        for id in unlisted {
            self.stop(&id);
        }
        {
            let mut registry = self.hub.registry();
            // An end still waiting to be sent for a stream the Gateway no longer lists is not needed.
            registry.streams.retain(|id, _| listed.contains(id));
            registry.ended.retain(|id, _| listed.contains(id));
        }
        for spec in specs {
            if self.running.contains_key(&spec.id) {
                continue;
            }
            let mut registry = self.hub.registry();
            if registry.streams.contains_key(&spec.id) {
                // Its end waits to be sent.
                continue;
            }
            if let Some(reason) = registry.ended.remove(&spec.id) {
                // The Gateway still lists a stream whose end was sent, so the end may have been lost:
                // send it again instead of reading the source again.
                let queue = Arc::new(StreamQueue::new(spec.id.clone(), self.hub.agent.clone()));
                queue.end(reason);
                registry.streams.insert(spec.id.clone(), queue);
                continue;
            }
            let queue = Arc::new(StreamQueue::new(spec.id.clone(), self.hub.agent.clone()));
            registry.streams.insert(spec.id.clone(), queue.clone());
            drop(registry);
            let id = spec.id.clone();
            let handle = (self.start)(spec, queue);
            self.running.insert(id, handle);
        }
    }

    fn stop(&mut self, id: &str) {
        if let Some(handle) = self.running.remove(id) {
            handle.abort();
        }
        self.hub.registry().streams.remove(id);
    }

    fn stop_all(&mut self) {
        let ids: Vec<String> = self.running.keys().cloned().collect();
        for id in ids {
            self.stop(&id);
        }
    }

    /// Stops reading every stream after 60 seconds without a list, and ends each one after the lines
    /// it already read. The viewer opens a new stream that catches up, so no line is lost or repeated;
    /// reading the same stream again later would send its first lines a second time.
    fn fail_closed(&mut self) {
        let running = std::mem::take(&mut self.running);
        let registry = self.hub.registry();
        for (id, handle) in running {
            handle.abort();
            if let Some(queue) = registry.streams.get(&id) {
                queue.end(LIST_UNAVAILABLE);
            }
        }
    }
}

/// Fetches the list when prompted and every 15 seconds while a stream runs, with at most one fetch in
/// flight, and starts and stops streams to match it. Without a list for 60 seconds, it stops all.
pub async fn run_controller<L: ListSource>(
    hub: LogHub,
    mut prompts: mpsc::UnboundedReceiver<()>,
    list: L,
    start: impl FnMut(StreamSpec, Arc<StreamQueue>) -> JoinHandle<()>,
) {
    let mut controller = Controller {
        hub,
        running: BTreeMap::new(),
        start,
    };
    let mut refresh = tokio::time::interval_at(Instant::now() + REFRESH, REFRESH);
    refresh.set_missed_tick_behavior(MissedTickBehavior::Delay);
    let mut last_ok: Option<Instant> = None;
    let mut last_error: Option<String> = None;
    loop {
        let deadline = last_ok.map(|at| at + FAIL_CLOSED);
        let armed = deadline.is_some() && !controller.running.is_empty();
        tokio::select! {
            prompt = prompts.recv() => if prompt.is_none() {
                controller.stop_all();
                return;
            },
            _ = refresh.tick() => if controller.running.is_empty() {
                continue;
            },
            _ = tokio::time::sleep_until(deadline.unwrap_or_else(Instant::now)), if armed => {
                eprintln!("orbit-agent: no log stream list for 60 seconds; ending every stream");
                controller.fail_closed();
                continue;
            },
        }
        loop {
            while prompts.try_recv().is_ok() {}
            let result = list
                .fetch()
                .await
                .and_then(|body| parse_stream_list(&body).map_err(str::to_owned));
            match result {
                Ok(specs) => {
                    last_ok = Some(Instant::now());
                    last_error = None;
                    controller.reconcile(specs);
                }
                Err(error) => {
                    if last_error.as_ref() != Some(&error) {
                        eprintln!("orbit-agent: log stream list failed: {error}");
                        last_error = Some(error);
                    }
                    let stale = last_ok.is_none_or(|at| at.elapsed() >= FAIL_CLOSED);
                    if stale && !controller.running.is_empty() {
                        eprintln!(
                            "orbit-agent: no log stream list for 60 seconds; ending every stream"
                        );
                        controller.fail_closed();
                    }
                }
            }
            // A prompt that arrived during the fetch gets one more fetch.
            if prompts.try_recv().is_err() {
                break;
            }
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;
    use std::sync::atomic::{AtomicUsize, Ordering};

    #[derive(Clone)]
    struct FakeList {
        body: Arc<Mutex<Result<Value, String>>>,
        fetches: Arc<AtomicUsize>,
        delay: Duration,
    }
    impl Default for FakeList {
        fn default() -> Self {
            Self {
                body: Arc::new(Mutex::new(Err("unset".into()))),
                fetches: Arc::default(),
                delay: Duration::ZERO,
            }
        }
    }
    impl FakeList {
        fn set(&self, body: Result<Value, String>) {
            *self.body.lock().unwrap() = body;
        }
    }
    impl ListSource for FakeList {
        async fn fetch(&self) -> Result<Value, String> {
            self.fetches.fetch_add(1, Ordering::SeqCst);
            tokio::time::sleep(self.delay).await;
            self.body.lock().unwrap().clone()
        }
    }

    fn list(ids: &[&str]) -> Result<Value, String> {
        Ok(json!({"data": ids.iter().map(|id| json!({
            "id": id, "lines": 10, "source": {"type": "journal", "unit": "orbit-process-1-a.service"}
        })).collect::<Vec<_>>(), "meta": {}}))
    }

    fn id(n: u8) -> String {
        format!("{n:032x}")
    }

    type Started = Arc<Mutex<Vec<String>>>;

    fn setup(fake: FakeList) -> (LogHub, Started, JoinHandle<()>) {
        let (hub, prompts) = LogHub::new();
        let started: Started = Arc::default();
        let record = started.clone();
        let controller = tokio::spawn(run_controller(
            hub.clone(),
            prompts,
            fake,
            move |spec, _queue| {
                record.lock().unwrap().push(spec.id.clone());
                tokio::spawn(std::future::pending::<()>())
            },
        ));
        (hub, started, controller)
    }

    async fn settle() {
        for _ in 0..20 {
            tokio::task::yield_now().await;
        }
    }

    #[tokio::test(start_paused = true)]
    async fn prompts_start_and_stop_listed_streams() {
        let fake = FakeList::default();
        fake.set(list(&[&id(1), &id(2)]));
        let (hub, started, _controller) = setup(fake.clone());
        settle().await;
        assert_eq!(
            fake.fetches.load(Ordering::SeqCst),
            0,
            "nothing without a prompt"
        );
        hub.prompt();
        settle().await;
        assert_eq!(*started.lock().unwrap(), [id(1), id(2)]);
        assert_eq!(hub.running(), 2);
        fake.set(list(&[&id(2), &id(3)]));
        hub.prompt();
        settle().await;
        assert_eq!(*started.lock().unwrap(), [id(1), id(2), id(3)]);
        assert_eq!(hub.running(), 2);
        let names: Vec<_> = hub.registry().streams.keys().cloned().collect();
        assert_eq!(names, [id(2), id(3)]);
    }

    #[tokio::test(start_paused = true)]
    async fn prompts_during_a_fetch_coalesce_into_one_more_fetch() {
        let fake = FakeList {
            delay: Duration::from_secs(1),
            ..FakeList::default()
        };
        fake.set(list(&[]));
        let (hub, _started, _controller) = setup(fake.clone());
        hub.prompt();
        settle().await;
        for _ in 0..5 {
            hub.prompt();
        }
        tokio::time::sleep(Duration::from_secs(5)).await;
        assert_eq!(fake.fetches.load(Ordering::SeqCst), 2);
    }

    #[tokio::test(start_paused = true)]
    async fn refreshes_every_15_seconds_only_while_streams_run() {
        let fake = FakeList::default();
        fake.set(list(&[]));
        let (hub, _started, _controller) = setup(fake.clone());
        hub.prompt();
        tokio::time::sleep(Duration::from_secs(61)).await;
        assert_eq!(fake.fetches.load(Ordering::SeqCst), 1);
        fake.set(list(&[&id(1)]));
        hub.prompt();
        settle().await;
        let before = fake.fetches.load(Ordering::SeqCst);
        tokio::time::sleep(Duration::from_secs(46)).await;
        assert_eq!(fake.fetches.load(Ordering::SeqCst), before + 3);
    }

    #[tokio::test(start_paused = true)]
    async fn fails_closed_after_60_seconds_without_a_list() {
        let fake = FakeList::default();
        fake.set(list(&[&id(1)]));
        let (hub, _started, _controller) = setup(fake.clone());
        hub.prompt();
        settle().await;
        assert_eq!(hub.running(), 1);
        fake.set(Err("gateway down".into()));
        tokio::time::sleep(Duration::from_secs(59)).await;
        assert_eq!(hub.running(), 1);
        tokio::time::sleep(Duration::from_secs(2)).await;
        let frames = hub.flush("presence-node-logs.1", Instant::now()).unwrap();
        assert_eq!(frames.len(), 1);
        assert_eq!(frames[0].event, "client-log-end");
        assert_eq!(frames[0].data["reason"], "list_unavailable");
        assert_eq!(hub.running(), 0);
    }

    #[tokio::test(start_paused = true)]
    async fn a_stream_ended_without_a_list_is_never_read_again_and_its_end_is_repeated() {
        let fake = FakeList::default();
        fake.set(list(&[&id(1)]));
        let (hub, started, _controller) = setup(fake.clone());
        hub.prompt();
        settle().await;
        hub.registry().streams[&id(1)].push("read before the outage".into());
        fake.set(Err("gateway down".into()));
        tokio::time::sleep(Duration::from_secs(61)).await;
        // The Gateway comes back and still lists the stream before the end reached it.
        fake.set(list(&[&id(1)]));
        hub.prompt();
        settle().await;
        assert_eq!(*started.lock().unwrap(), [id(1)], "never started again");
        // The lines read before the outage go out first, then the end.
        let frames = hub.flush("presence-node-logs.1", Instant::now()).unwrap();
        assert_eq!(lines_of(&frames), ["read before the outage"]);
        assert_eq!(frames.last().unwrap().data["reason"], "list_unavailable");
        // Listed again after the end was sent: the end is sent again, the source is not read.
        hub.prompt();
        settle().await;
        tokio::time::sleep(Duration::from_millis(300)).await;
        let frames = hub.flush("presence-node-logs.1", Instant::now()).unwrap();
        assert_eq!(frames.len(), 1);
        assert_eq!(frames[0].event, "client-log-end");
        assert_eq!(frames[0].data["reason"], "list_unavailable");
        assert_eq!(*started.lock().unwrap(), [id(1)]);
        // Once the Gateway closed it, nothing is left.
        fake.set(list(&[]));
        hub.prompt();
        settle().await;
        assert_eq!(hub.running(), 0);
        assert!(hub.registry().ended.is_empty());
    }

    #[tokio::test(start_paused = true)]
    async fn an_ended_stream_is_not_started_again_while_listed() {
        let fake = FakeList::default();
        fake.set(list(&[&id(1)]));
        let (hub, started, _controller) = setup(fake.clone());
        hub.prompt();
        settle().await;
        let queue = hub.registry().streams[&id(1)].clone();
        queue.end(SOURCE_UNAVAILABLE);
        let frames = hub.flush("presence-node-logs.1", Instant::now()).unwrap();
        assert_eq!(frames.len(), 1);
        assert_eq!(frames[0].event, "client-log-end");
        assert_eq!(hub.running(), 0);
        hub.prompt();
        settle().await;
        assert_eq!(started.lock().unwrap().len(), 1);
        fake.set(list(&[]));
        hub.prompt();
        settle().await;
        fake.set(list(&[&id(1)]));
        hub.prompt();
        settle().await;
        assert_eq!(started.lock().unwrap().len(), 2);
    }

    fn lines_of(frames: &[ClientFrame]) -> Vec<String> {
        frames
            .iter()
            .filter(|f| f.event == "client-log")
            .flat_map(|f| f.data["lines"].as_array().unwrap().clone())
            .map(|l| l.as_str().unwrap().to_owned())
            .collect()
    }

    async fn flush_after(hub: &LogHub, wait: Duration) -> Vec<ClientFrame> {
        tokio::time::sleep(wait).await;
        hub.flush("presence-node-logs.7", Instant::now()).unwrap()
    }

    #[tokio::test]
    async fn laravel_stream_end_to_end_redacts_and_ends_on_a_link() {
        use std::io::Write;
        let root = std::env::temp_dir().join(format!("orbit-agent-e2e-{}", rand::random::<u64>()));
        let logs = root.join("storage/logs");
        std::fs::create_dir_all(&logs).unwrap();
        let root = std::fs::canonicalize(&root).unwrap();
        let log = root.join("storage/logs/laravel.log");
        std::fs::write(&log, "old\nfirst DB_PASSWORD=hunter2\n").unwrap();
        let fake = FakeList::default();
        fake.set(Ok(serde_json::json!({"data": [
            {"id": id(1), "lines": 5, "source": {"type": "laravel", "path": root.to_str().unwrap()}}
        ]})));
        let (hub, prompts) = LogHub::new();
        tokio::spawn(run_controller(
            hub.clone(),
            prompts,
            fake.clone(),
            spawn_stream,
        ));
        hub.prompt();
        let frames = flush_after(&hub, Duration::from_millis(400)).await;
        assert_eq!(lines_of(&frames), ["old", "first DB_PASSWORD=[REDACTED]"]);
        assert_eq!(frames[0].data["sequence"], 1);
        assert_eq!(frames[0].data["stream"], id(1));
        std::fs::OpenOptions::new()
            .append(true)
            .open(&log)
            .unwrap()
            .write_all(b"Authorization: Bearer abcdefgh12345\n")
            .unwrap();
        let frames = flush_after(&hub, Duration::from_millis(700)).await;
        assert_eq!(lines_of(&frames), ["Authorization: [REDACTED]"]);
        assert_eq!(frames[0].data["sequence"], 2);
        // storage/logs becomes a link: the stream ends with source_unavailable.
        std::fs::rename(&logs, root.join("storage/real")).unwrap();
        std::os::unix::fs::symlink(root.join("storage/real"), &logs).unwrap();
        let frames = flush_after(&hub, Duration::from_millis(4_500)).await;
        let end = frames.last().unwrap();
        assert_eq!(end.event, "client-log-end");
        assert_eq!(end.data["reason"], "source_unavailable");
        assert_eq!(hub.running(), 0);
        // The Gateway still lists it until it has processed the end; it is not started again.
        hub.prompt();
        tokio::time::sleep(Duration::from_millis(100)).await;
        assert_eq!(hub.running(), 0);
        let _ = std::fs::remove_dir_all(&root);
    }

    #[tokio::test]
    async fn journal_stream_end_to_end() {
        use super::super::journal_writer::{Options, Writer};
        let dir = std::env::temp_dir().join(format!("orbit-agent-e2e-j-{}", rand::random::<u64>()));
        std::fs::create_dir_all(&dir).unwrap();
        let unit = "orbit-process-41-queue.service";
        let mut w = Writer::new(Options::default());
        let entry = |w: &mut Writer, n: u64, message: &str| {
            w.append(
                1_790_331_302_000_000 + n,
                &[
                    ("_SYSTEMD_UNIT", unit.as_bytes()),
                    ("_PID", b"7"),
                    ("SYSLOG_IDENTIFIER", b"queue"),
                    ("MESSAGE", message.as_bytes()),
                ],
            )
        };
        entry(&mut w, 1, "one");
        entry(&mut w, 2, "token=abc");
        w.write(&dir.join("system.journal"));
        let queue = Arc::new(StreamQueue::new(id(2), agent_bucket()));
        let mut sink = LineSink::new(queue.clone(), 10);
        let task_dir = dir.clone();
        let task = tokio::spawn(async move {
            let _ = journal::run_in(vec![task_dir], unit.into(), &mut sink).await;
        });
        tokio::time::sleep(Duration::from_millis(300)).await;
        let first = queue.flush("c", Instant::now()).unwrap();
        assert_eq!(
            lines_of(&first.frames),
            [
                "2026-09-25T10:15:02+00:00 queue[7]: one",
                "2026-09-25T10:15:02+00:00 queue[7]: token=[REDACTED]"
            ]
        );
        entry(&mut w, 3, "three");
        w.write(&dir.join("system.journal"));
        tokio::time::sleep(Duration::from_millis(800)).await;
        let next = queue.flush("c", Instant::now()).unwrap();
        assert_eq!(
            lines_of(&next.frames),
            ["2026-09-25T10:15:02+00:00 queue[7]: three"]
        );
        task.abort();
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[tokio::test(start_paused = true)]
    async fn invalid_lists_count_as_failures() {
        let fake = FakeList::default();
        fake.set(list(&[&id(1)]));
        let (hub, _started, _controller) = setup(fake.clone());
        hub.prompt();
        settle().await;
        fake.set(Ok(json!({"data": "nope"})));
        tokio::time::sleep(Duration::from_secs(61)).await;
        let frames = hub.flush("presence-node-logs.1", Instant::now()).unwrap();
        assert_eq!(frames[0].data["reason"], "list_unavailable");
        assert_eq!(hub.running(), 0);
    }
}
