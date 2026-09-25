//! Line length, rate, and queue limits for live log streams (ADR 0153).
use super::{events, redact::Redactor};
use crate::ClientFrame;
use std::{
    collections::VecDeque,
    sync::{Arc, Mutex, MutexGuard},
    time::Duration,
};
use tokio::time::Instant;

pub const MAX_LINE_BYTES: usize = 8 * 1024;
pub const TRUNCATED: &str = " [truncated]";
pub const FIRST_LINES_BYTES: usize = 256 * 1024;
pub const STREAM_RATE: f64 = 32.0 * 1024.0;
pub const STREAM_BURST: f64 = 256.0 * 1024.0;
pub const AGENT_RATE: f64 = 256.0 * 1024.0;
pub const AGENT_BURST: f64 = 256.0 * 1024.0;
/// A stream never queues more than one burst.
pub const QUEUE_BYTES: usize = 256 * 1024;
pub const BATCH_INTERVAL: Duration = Duration::from_millis(250);
pub const SOURCE_UNAVAILABLE: &str = "source_unavailable";

/// Cuts a line to `max` bytes at a character boundary so that it ends with `[truncated]`.
pub fn cut_line(line: &str, max: usize) -> String {
    if line.len() <= max {
        return line.to_owned();
    }
    let keep = floor_char_boundary(line, max.saturating_sub(TRUNCATED.len()));
    format!("{}{TRUNCATED}", &line[..keep])
}

/// Cuts a line that is known to continue past what was read, so it always ends with `[truncated]`.
pub fn cut_continued_line(line: &str) -> String {
    let keep = floor_char_boundary(line, MAX_LINE_BYTES - TRUNCATED.len());
    format!("{}{TRUNCATED}", &line[..keep])
}

pub fn floor_char_boundary(text: &str, index: usize) -> usize {
    if index >= text.len() {
        return text.len();
    }
    let mut index = index;
    while !text.is_char_boundary(index) {
        index -= 1;
    }
    index
}

/// Converts raw log bytes to text: invalid UTF-8 becomes U+FFFD, and one trailing `\r` is removed.
pub fn line_text(bytes: &[u8]) -> String {
    let bytes = bytes.strip_suffix(b"\r").unwrap_or(bytes);
    String::from_utf8_lossy(bytes).into_owned()
}

#[derive(Debug, Clone)]
pub struct TokenBucket {
    tokens: f64,
    capacity: f64,
    rate: f64,
    updated: Instant,
}
impl TokenBucket {
    pub fn new(capacity: f64, rate: f64) -> Self {
        Self {
            tokens: capacity,
            capacity,
            rate,
            updated: Instant::now(),
        }
    }
    fn refill(&mut self, now: Instant) {
        let elapsed = now.saturating_duration_since(self.updated).as_secs_f64();
        self.tokens = (self.tokens + elapsed * self.rate).min(self.capacity);
        self.updated = now;
    }
    pub fn available(&mut self, now: Instant) -> f64 {
        self.refill(now);
        self.tokens
    }
    pub fn take(&mut self, amount: f64) {
        self.tokens -= amount;
    }
}

pub type AgentBucket = Arc<Mutex<TokenBucket>>;
pub fn agent_bucket() -> AgentBucket {
    Arc::new(Mutex::new(TokenBucket::new(AGENT_BURST, AGENT_RATE)))
}
fn lock<T>(mutex: &Mutex<T>) -> MutexGuard<'_, T> {
    mutex
        .lock()
        .unwrap_or_else(|poisoned| poisoned.into_inner())
}

#[derive(Debug)]
struct QueueState {
    lines: VecDeque<String>,
    bytes: usize,
    dropped: u64,
    skipped: u64,
    bucket: TokenBucket,
    ended: Option<&'static str>,
    sequence: u64,
    last_sent: Option<Instant>,
}

/// The queue between a stream's source and the realtime connection. It outlives reconnects.
#[derive(Debug)]
pub struct StreamQueue {
    pub id: String,
    state: Mutex<QueueState>,
    agent: AgentBucket,
}

/// What a flush produced for one stream.
#[derive(Debug, Default)]
pub struct Flush {
    pub frames: Vec<ClientFrame>,
    /// The stream's end was sent, so the stream is finished.
    pub finished: bool,
}

fn cost(line: &str) -> usize {
    line.len().max(1)
}

impl StreamQueue {
    pub fn new(id: String, agent: AgentBucket) -> Self {
        Self {
            id,
            state: Mutex::new(QueueState {
                lines: VecDeque::new(),
                bytes: 0,
                dropped: 0,
                skipped: 0,
                bucket: TokenBucket::new(STREAM_BURST, STREAM_RATE),
                ended: None,
                sequence: 0,
                last_sent: None,
            }),
            agent,
        }
    }

    /// Queues the first lines of a stream. They are bounded by `FIRST_LINES_BYTES` instead of the rates.
    pub fn push_first(&self, lines: Vec<String>) {
        let mut state = lock(&self.state);
        for line in lines {
            let size = cost(&line);
            if state.bytes + size > QUEUE_BYTES {
                state.dropped += 1;
                continue;
            }
            state.bytes += size;
            state.lines.push_back(line);
        }
    }

    /// Whether a line of about `size` bytes would pass the rates and the queue bound now.
    pub fn would_accept(&self, size: usize) -> bool {
        let now = Instant::now();
        let mut state = lock(&self.state);
        let size = size.max(1);
        if state.bytes + size > QUEUE_BYTES || state.bucket.available(now) < size as f64 {
            return false;
        }
        lock(&self.agent).available(now) >= size as f64
    }

    /// Queues one line when both rates and the queue bound allow it; otherwise counts it as dropped.
    pub fn push(&self, line: String) -> bool {
        let now = Instant::now();
        let size = cost(&line);
        let mut state = lock(&self.state);
        let accepted =
            state.bytes + size <= QUEUE_BYTES && state.bucket.available(now) >= size as f64 && {
                let mut agent = lock(&self.agent);
                let ok = agent.available(now) >= size as f64;
                if ok {
                    agent.take(size as f64);
                }
                ok
            };
        if accepted {
            state.bucket.take(size as f64);
            state.bytes += size;
            state.lines.push_back(line);
        } else {
            state.dropped += 1;
        }
        accepted
    }

    pub fn add_dropped(&self, count: u64) {
        let mut state = lock(&self.state);
        state.dropped = state.dropped.saturating_add(count);
    }
    pub fn add_skipped(&self, bytes: u64) {
        let mut state = lock(&self.state);
        state.skipped = state.skipped.saturating_add(bytes);
    }
    /// Marks the source as ended; the end is sent after the queued lines.
    pub fn end(&self, reason: &'static str) {
        lock(&self.state).ended.get_or_insert(reason);
    }
    pub fn queued_bytes(&self) -> usize {
        lock(&self.state).bytes
    }
    pub fn counts(&self) -> (u64, u64) {
        let state = lock(&self.state);
        (state.dropped, state.skipped)
    }

    /// Takes what is due at `now`: at most one batch every `BATCH_INTERVAL`, split into frames.
    pub fn flush(&self, channel: &str, now: Instant) -> Result<Flush, serde_json::Error> {
        let mut state = lock(&self.state);
        if state
            .last_sent
            .is_some_and(|last| now < last + BATCH_INTERVAL)
        {
            return Ok(Flush::default());
        }
        let mut flush = Flush::default();
        if !state.lines.is_empty() || state.dropped > 0 || state.skipped > 0 {
            let lines = std::mem::take(&mut state.lines).into_iter().collect();
            let (dropped, skipped) = (state.dropped, state.skipped);
            let mut sequence = state.sequence;
            flush.frames =
                events::log_frames(channel, &self.id, &mut sequence, lines, dropped, skipped)?;
            state.sequence = sequence;
            state.bytes = 0;
            state.dropped = 0;
            state.skipped = 0;
            state.last_sent = Some(now);
        }
        if let Some(reason) = state.ended {
            flush
                .frames
                .push(events::log_end_frame(channel, &self.id, reason));
            flush.finished = true;
        }
        Ok(flush)
    }
}

/// Redacts, cuts, and rate-limits the lines of one stream before they reach its queue.
pub struct LineSink {
    queue: Arc<StreamQueue>,
    redactor: Redactor,
    lines: usize,
}
impl LineSink {
    pub fn new(queue: Arc<StreamQueue>, lines: usize) -> Self {
        Self {
            queue,
            redactor: Redactor::default(),
            lines,
        }
    }

    /// Sends the first lines, oldest first: at most the stream's `lines`, and at most 256 KiB.
    pub fn first(&mut self, raw: Vec<String>) {
        let start = raw.len().saturating_sub(self.lines);
        let redacted: Vec<String> = raw
            .into_iter()
            .skip(start)
            .filter_map(|line| self.redactor.line(&line))
            .map(|line| cut_line(&line, MAX_LINE_BYTES))
            .collect();
        let mut total = 0;
        let mut keep = redacted.len();
        while keep > 0 {
            let size = cost(&redacted[keep - 1]);
            if total + size > FIRST_LINES_BYTES {
                break;
            }
            total += size;
            keep -= 1;
        }
        self.queue
            .push_first(redacted.into_iter().skip(keep).collect());
    }

    /// Sends one new line, or counts it as dropped when a rate or the queue bound does not allow it.
    pub fn line(&mut self, raw: &str) {
        if !self.queue.would_accept(raw.len().min(MAX_LINE_BYTES)) {
            self.redactor.observe(raw);
            self.queue.add_dropped(1);
            return;
        }
        if let Some(line) = self.redactor.line(raw) {
            self.queue.push(cut_line(&line, MAX_LINE_BYTES));
        }
    }

    pub fn dropped(&self, count: u64) {
        if count > 0 {
            self.queue.add_dropped(count);
        }
    }
    pub fn skipped(&self, bytes: u64) {
        if bytes > 0 {
            self.queue.add_skipped(bytes);
        }
    }
    /// The stream's `lines`: how many earlier lines to send first.
    pub fn lines(&self) -> usize {
        self.lines
    }
    pub fn queue(&self) -> &Arc<StreamQueue> {
        &self.queue
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn queue() -> Arc<StreamQueue> {
        Arc::new(StreamQueue::new("a".repeat(32), agent_bucket()))
    }

    #[test]
    fn long_lines_are_cut_at_a_char_boundary_and_end_with_truncated() {
        let line = "é".repeat(5000);
        let cut = cut_line(&line, MAX_LINE_BYTES);
        assert!(cut.len() <= MAX_LINE_BYTES);
        assert!(cut.ends_with("[truncated]"));
        assert!(cut.len() >= MAX_LINE_BYTES - 1 - TRUNCATED.len());
        let exact = "x".repeat(MAX_LINE_BYTES);
        assert_eq!(cut_line(&exact, MAX_LINE_BYTES), exact);
        let over = "x".repeat(MAX_LINE_BYTES + 1);
        let cut = cut_line(&over, MAX_LINE_BYTES);
        assert_eq!(cut.len(), MAX_LINE_BYTES);
        assert!(cut.ends_with(" [truncated]"));
        assert!(cut_continued_line("short").ends_with("short [truncated]"));
    }

    #[test]
    fn line_text_replaces_invalid_utf8_and_strips_carriage_return() {
        assert_eq!(line_text(b"ok\xff\r"), "ok\u{fffd}");
    }

    #[tokio::test(start_paused = true)]
    async fn stream_rate_allows_one_burst_then_32_kib_per_second() {
        let q = queue();
        let line = "x".repeat(1024);
        let mut accepted = 0;
        for _ in 0..300 {
            accepted += usize::from(q.push(line.clone()));
        }
        assert_eq!(accepted, 256);
        assert_eq!(q.counts().0, 44);
        let _ = q.flush("c", Instant::now()).unwrap();
        tokio::time::advance(Duration::from_secs(1)).await;
        let mut accepted = 0;
        for _ in 0..100 {
            accepted += usize::from(q.push(line.clone()));
        }
        assert_eq!(accepted, 32);
    }

    #[tokio::test(start_paused = true)]
    async fn agent_rate_is_shared_by_all_streams() {
        let agent = agent_bucket();
        let a = StreamQueue::new("a".repeat(32), agent.clone());
        let b = StreamQueue::new("b".repeat(32), agent);
        let line = "x".repeat(1024);
        let accepted_a = (0..300).filter(|_| a.push(line.clone())).count();
        assert_eq!(accepted_a, 256);
        // Stream b has a full bucket of its own, but the agent-wide bucket is empty.
        assert_eq!((0..10).filter(|_| b.push(line.clone())).count(), 0);
        tokio::time::advance(Duration::from_millis(500)).await;
        assert_eq!((0..300).filter(|_| b.push(line.clone())).count(), 128);
    }

    #[tokio::test(start_paused = true)]
    async fn a_flood_keeps_memory_bounded_and_counts_drops() {
        let q = queue();
        let mut sink = LineSink::new(q.clone(), 100);
        let line = "flood line with some text password=hunter2 ".repeat(20);
        let mut fed = 0usize;
        let mut sent_lines = 0usize;
        let mut dropped = 0u64;
        while fed < 50 * 1024 * 1024 {
            for _ in 0..1000 {
                sink.line(&line);
                fed += line.len();
                assert!(q.queued_bytes() <= QUEUE_BYTES);
            }
            tokio::time::advance(Duration::from_millis(10)).await;
            let flush = q.flush("presence-node-logs.1", Instant::now()).unwrap();
            for frame in flush.frames {
                assert!(serde_json::to_vec(&frame).unwrap().len() <= crate::PUSHER_FRAME_LIMIT);
                sent_lines += frame.data["lines"].as_array().unwrap().len();
                dropped += frame.data["dropped"].as_u64().unwrap();
                for l in frame.data["lines"].as_array().unwrap() {
                    assert!(!l.as_str().unwrap().contains("hunter2"));
                }
            }
        }
        assert!(dropped > 0);
        // One burst plus 32 KiB per second of virtual time, and nothing more.
        let elapsed = 50.0 * 1024.0 * 1024.0 / (line.len() as f64 * 1000.0) * 0.01;
        let allowed = (STREAM_BURST + STREAM_RATE * (elapsed + 1.0)) / line.len() as f64;
        assert!(sent_lines as f64 <= allowed, "{sent_lines} > {allowed}");
        assert!(sent_lines > 0);
    }

    #[tokio::test(start_paused = true)]
    async fn one_batch_every_250_ms_and_end_after_lines() {
        let q = queue();
        q.push("a".into());
        let first = q.flush("c", Instant::now()).unwrap();
        assert_eq!(first.frames.len(), 1);
        assert_eq!(first.frames[0].data["sequence"], 1);
        q.push("b".into());
        tokio::time::advance(Duration::from_millis(249)).await;
        assert!(q.flush("c", Instant::now()).unwrap().frames.is_empty());
        tokio::time::advance(Duration::from_millis(1)).await;
        q.end(SOURCE_UNAVAILABLE);
        let second = q.flush("c", Instant::now()).unwrap();
        assert!(second.finished);
        assert_eq!(second.frames.len(), 2);
        assert_eq!(second.frames[0].event, "client-log");
        assert_eq!(second.frames[0].data["sequence"], 2);
        assert_eq!(second.frames[0].data["lines"], serde_json::json!(["b"]));
        assert_eq!(second.frames[1].event, "client-log-end");
        assert_eq!(
            second.frames[1].data,
            serde_json::json!({"stream": "a".repeat(32), "reason": "source_unavailable"})
        );
    }

    #[test]
    fn first_lines_keep_the_newest_within_count_and_256_kib() {
        let q = queue();
        let mut sink = LineSink::new(q.clone(), 1000);
        let raw: Vec<String> = (0..1000)
            .map(|i| format!("{i:04}{}", "y".repeat(996)))
            .collect();
        sink.first(raw);
        assert_eq!(q.queued_bytes(), 262 * 1000);
        let q = queue();
        let mut sink = LineSink::new(q.clone(), 3);
        sink.first(vec!["1".into(), "2".into(), "API_KEY=x".into(), "4".into()]);
        let flush = q.flush("c", Instant::now()).unwrap();
        assert_eq!(
            flush.frames[0].data["lines"],
            serde_json::json!(["2", "API_KEY=[REDACTED]", "4"])
        );
    }
}
