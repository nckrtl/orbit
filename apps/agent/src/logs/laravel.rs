//! Tails `storage/logs/laravel.log`, or the newest `laravel-*.log`, of an Instance checkout (ADR 0153).
//!
//! The checkout and `storage` may be links, as for today's SSH read. `logs` and the file are opened
//! relative to their parent directory without following a link. The file must be a regular file that
//! `root` does not own. Reads use `pread`; the agent never writes.
use super::limits::{cut_continued_line, line_text, LineSink, MAX_LINE_BYTES};
use rustix::{
    fd::OwnedFd,
    fs::{self, AtFlags, FileType, Mode, OFlags, Stat},
    io::Errno,
};
use std::{fs::File, os::unix::fs::FileExt, time::Duration};

pub const POLL_INTERVAL: Duration = Duration::from_millis(250);
/// Every eighth poll (2 seconds) looks for a newer file.
pub const RESCAN_EVERY: u32 = 8;
pub const FIRST_READ_BYTES: u64 = 256 * 1024;
pub const READ_AHEAD: u64 = 1024 * 1024;
pub const SKIP_BEHIND: u64 = 4 * 1024 * 1024;
pub const SKIP_KEEP: u64 = 64 * 1024;

/// The source cannot be read; the stream ends with `source_unavailable`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Unavailable(pub &'static str);

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
struct Meta {
    regular: bool,
    uid: u32,
    dev: u64,
    ino: u64,
    size: u64,
    mtime: (i64, i64),
}

#[allow(clippy::unnecessary_cast, clippy::useless_conversion)]
fn meta(st: &Stat) -> Meta {
    Meta {
        regular: FileType::from_raw_mode(st.st_mode) == FileType::RegularFile,
        uid: st.st_uid as u32,
        dev: st.st_dev as u64,
        ino: st.st_ino as u64,
        size: u64::try_from(st.st_size as i64).unwrap_or(0),
        mtime: (st.st_mtime as i64, st.st_mtime_nsec as i64),
    }
}

/// `laravel-{date or name}.log`: `^laravel-[A-Za-z0-9._-]+\.log$`.
pub fn is_daily_name(name: &[u8]) -> bool {
    name.strip_prefix(b"laravel-")
        .and_then(|rest| rest.strip_suffix(b".log"))
        .is_some_and(|middle| {
            !middle.is_empty()
                && middle
                    .iter()
                    .all(|b| b.is_ascii_alphanumeric() || matches!(b, b'.' | b'_' | b'-'))
        })
}

fn missing(error: Errno) -> bool {
    error == Errno::NOENT
}

/// Opens `{checkout}/storage/logs`. `Ok(None)` means it does not exist yet.
fn open_logs_dir(checkout: &str) -> Result<Option<OwnedFd>, Unavailable> {
    let directory = OFlags::RDONLY | OFlags::DIRECTORY | OFlags::CLOEXEC;
    let checkout = match fs::open(checkout, directory, Mode::empty()) {
        Ok(fd) => fd,
        Err(error) if missing(error) => return Ok(None),
        Err(_) => return Err(Unavailable("checkout")),
    };
    let storage = match fs::openat(&checkout, "storage", directory, Mode::empty()) {
        Ok(fd) => fd,
        Err(error) if missing(error) => return Ok(None),
        Err(_) => return Err(Unavailable("storage")),
    };
    match fs::openat(
        &storage,
        "logs",
        directory | OFlags::NOFOLLOW,
        Mode::empty(),
    ) {
        Ok(fd) => Ok(Some(fd)),
        Err(error) if missing(error) => Ok(None),
        // ELOOP for a link, ENOTDIR for a file, EACCES and the rest: all refused.
        Err(_) => Err(Unavailable("storage/logs")),
    }
}

/// `laravel.log` when it exists in any form, else the newest regular `laravel-*.log`.
fn choose_file(logs: &OwnedFd) -> Result<Option<(String, Meta)>, Unavailable> {
    match fs::statat(logs, "laravel.log", AtFlags::SYMLINK_NOFOLLOW) {
        Ok(st) => return Ok(Some(("laravel.log".into(), meta(&st)))),
        Err(error) if missing(error) => {}
        Err(_) => return Err(Unavailable("laravel.log")),
    }
    let dir = fs::Dir::read_from(logs).map_err(|_| Unavailable("storage/logs"))?;
    let mut newest: Option<(String, Meta)> = None;
    for entry in dir {
        let Ok(entry) = entry else {
            return Err(Unavailable("storage/logs"));
        };
        let name = entry.file_name().to_bytes();
        if !is_daily_name(name) {
            continue;
        }
        let Ok(name) = std::str::from_utf8(name) else {
            continue;
        };
        let Ok(st) = fs::statat(logs, name, AtFlags::SYMLINK_NOFOLLOW) else {
            continue;
        };
        let m = meta(&st);
        if !m.regular {
            continue;
        }
        let newer = newest
            .as_ref()
            .is_none_or(|(old, o)| (m.mtime, name) > (o.mtime, old.as_str()));
        if newer {
            newest = Some((name.into(), m));
        }
    }
    Ok(newest)
}

/// Opens one log file without following a link and checks what it is. `Ok(None)` when it vanished.
fn open_file(
    logs: &OwnedFd,
    name: &str,
    forbidden_uid: u32,
) -> Result<Option<(File, Meta)>, Unavailable> {
    let flags = OFlags::RDONLY | OFlags::NOFOLLOW | OFlags::NONBLOCK | OFlags::CLOEXEC;
    let fd = match fs::openat(logs, name, flags, Mode::empty()) {
        Ok(fd) => fd,
        Err(error) if missing(error) => return Ok(None),
        Err(_) => return Err(Unavailable("log file")),
    };
    let m = meta(&fs::fstat(&fd).map_err(|_| Unavailable("log file"))?);
    if !acceptable(m.regular, m.uid, forbidden_uid) {
        return Err(Unavailable("log file type or owner"));
    }
    Ok(Some((File::from(fd), m)))
}

/// A regular file that the forbidden owner (`root` in production) does not own.
pub fn acceptable(regular: bool, uid: u32, forbidden_uid: u32) -> bool {
    regular && uid != forbidden_uid
}

struct Current {
    file: File,
    name: String,
    dev: u64,
    ino: u64,
    offset: u64,
}

#[derive(Debug, Default, PartialEq, Eq)]
pub struct Output {
    pub lines: Vec<String>,
    pub skipped: u64,
}

/// The synchronous state of one `laravel` source. The async driver calls it from a blocking thread.
pub struct LaravelTail {
    checkout: String,
    forbidden_uid: u32,
    current: Option<Current>,
    partial: Vec<u8>,
    discarding: bool,
}

impl LaravelTail {
    pub fn new(checkout: &str) -> Self {
        Self::with_forbidden_owner(checkout, 0)
    }
    /// Tests pass their own user ID to exercise the owner refusal without `root`.
    pub fn with_forbidden_owner(checkout: &str, forbidden_uid: u32) -> Self {
        Self {
            checkout: checkout.into(),
            forbidden_uid,
            current: None,
            partial: Vec::new(),
            discarding: false,
        }
    }

    /// Opens the current file and returns its last `lines` lines from at most its last 256 KiB.
    pub fn start(&mut self, lines: usize) -> Result<Vec<String>, Unavailable> {
        let Some(logs) = open_logs_dir(&self.checkout)? else {
            return Ok(Vec::new());
        };
        let Some((name, _)) = choose_file(&logs)? else {
            return Ok(Vec::new());
        };
        let Some((file, m)) = open_file(&logs, &name, self.forbidden_uid)? else {
            return Ok(Vec::new());
        };
        let from = m.size.saturating_sub(FIRST_READ_BYTES);
        // One byte before `from` tells whether `from` starts a line.
        let read_from = from.saturating_sub(1);
        let chunk = read_range(&file, read_from, m.size)?;
        self.current = Some(Current {
            file,
            name,
            dev: m.dev,
            ino: m.ino,
            offset: read_from + chunk.len() as u64,
        });
        let mut body = &chunk[..];
        if from > 0 {
            match body.iter().position(|b| *b == b'\n') {
                Some(newline) => body = &body[newline + 1..],
                None => {
                    self.discarding = true;
                    return Ok(Vec::new());
                }
            }
        }
        let mut out = Output::default();
        self.feed(body, &mut out);
        let start = out.lines.len().saturating_sub(lines);
        Ok(out.lines.split_off(start))
    }

    /// Reads what was appended since the last poll. `rescan` also looks for a newer file, after the
    /// current file is read, so the end of the old file is not lost.
    pub fn poll(&mut self, rescan: bool) -> Result<Output, Unavailable> {
        let mut out = Output::default();
        self.read_current(&mut out)?;
        if rescan && self.rescan(&mut out)? {
            self.read_current(&mut out)?;
        }
        Ok(out)
    }

    fn read_current(&mut self, out: &mut Output) -> Result<(), Unavailable> {
        let Some(current) = self.current.as_mut() else {
            return Ok(());
        };
        let size = meta(&fs::fstat(&current.file).map_err(|_| Unavailable("log file"))?).size;
        if size < current.offset {
            // Truncated: start again from the beginning.
            current.offset = 0;
            self.partial.clear();
            self.discarding = false;
        }
        if size - current.offset > SKIP_BEHIND {
            let target = size - SKIP_KEEP;
            let window = read_range(&current.file, target, size)?;
            let start = match window.iter().position(|b| *b == b'\n') {
                Some(newline) => target + newline as u64 + 1,
                None => size,
            };
            out.skipped += start - current.offset + self.partial.len() as u64;
            current.offset = start;
            self.partial.clear();
            self.discarding = false;
        }
        let end = size.min(current.offset + READ_AHEAD);
        let chunk = read_range(&current.file, current.offset, end)?;
        current.offset += chunk.len() as u64;
        self.feed(&chunk, out);
        Ok(())
    }

    /// Switches to a newer file. Returns whether it switched.
    fn rescan(&mut self, out: &mut Output) -> Result<bool, Unavailable> {
        let Some(logs) = open_logs_dir(&self.checkout)? else {
            return Ok(false);
        };
        let Some((name, m)) = choose_file(&logs)? else {
            return Ok(false);
        };
        let same = self
            .current
            .as_ref()
            .is_some_and(|c| c.name == name && c.dev == m.dev && c.ino == m.ino);
        if same {
            return Ok(false);
        }
        let Some((file, m)) = open_file(&logs, &name, self.forbidden_uid)? else {
            return Ok(false);
        };
        if self.current.is_some() && !self.discarding && !self.partial.is_empty() {
            out.lines.push(line_text(&self.partial));
        }
        self.partial.clear();
        self.discarding = false;
        self.current = Some(Current {
            file,
            name,
            dev: m.dev,
            ino: m.ino,
            offset: 0,
        });
        Ok(true)
    }

    /// Splits bytes into lines. A line longer than 8 KiB is cut once and the rest of it is skipped.
    fn feed(&mut self, mut bytes: &[u8], out: &mut Output) {
        while !bytes.is_empty() {
            let newline = bytes.iter().position(|b| *b == b'\n');
            let (piece, rest, complete) = match newline {
                Some(index) => (&bytes[..index], &bytes[index + 1..], true),
                None => (bytes, &[][..], false),
            };
            bytes = rest;
            if self.discarding {
                self.discarding = !complete;
                continue;
            }
            let room = MAX_LINE_BYTES + 1 - self.partial.len();
            self.partial
                .extend_from_slice(&piece[..piece.len().min(room)]);
            if self.partial.len() > MAX_LINE_BYTES {
                out.lines
                    .push(cut_continued_line(&line_text(&self.partial)));
                self.partial.clear();
                self.discarding = !complete;
            } else if complete {
                out.lines.push(line_text(&self.partial));
                self.partial.clear();
            }
        }
    }
}

fn read_range(file: &File, from: u64, to: u64) -> Result<Vec<u8>, Unavailable> {
    let mut buffer = vec![0; to.saturating_sub(from) as usize];
    let mut filled = 0;
    while filled < buffer.len() {
        match file.read_at(&mut buffer[filled..], from + filled as u64) {
            Ok(0) => break,
            Ok(n) => filled += n,
            Err(error) if error.kind() == std::io::ErrorKind::Interrupted => {}
            Err(_) => return Err(Unavailable("read")),
        }
    }
    buffer.truncate(filled);
    Ok(buffer)
}

/// Runs a `laravel` source until it cannot be read.
pub async fn run(checkout: String, sink: &mut LineSink) -> Result<(), Unavailable> {
    let lines = sink.lines();
    let mut tail = LaravelTail::new(&checkout);
    let (returned, first) = blocking(move || {
        let first = tail.start(lines);
        (tail, first)
    })
    .await?;
    tail = returned;
    sink.first(first?);
    let mut tick = tokio::time::interval(POLL_INTERVAL);
    tick.set_missed_tick_behavior(tokio::time::MissedTickBehavior::Delay);
    tick.tick().await;
    let mut count = 0u32;
    loop {
        tick.tick().await;
        count = count.wrapping_add(1);
        let rescan = count.checked_rem(RESCAN_EVERY) == Some(0);
        let (returned, output) = blocking(move || {
            let output = tail.poll(rescan);
            (tail, output)
        })
        .await?;
        tail = returned;
        let output = output?;
        sink.skipped(output.skipped);
        for line in output.lines {
            sink.line(&line);
        }
    }
}

pub(crate) async fn blocking<T: Send + 'static>(
    work: impl FnOnce() -> T + Send + 'static,
) -> Result<T, Unavailable> {
    tokio::task::spawn_blocking(work)
        .await
        .map_err(|_| Unavailable("reader task"))
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::{
        io::Write,
        os::unix::fs::MetadataExt,
        path::{Path, PathBuf},
    };

    struct TempDir(PathBuf);
    impl TempDir {
        fn new() -> Self {
            let path = std::env::temp_dir().join(format!(
                "orbit-agent-laravel-{}-{}",
                std::process::id(),
                rand::random::<u64>()
            ));
            std::fs::create_dir_all(path.join("storage/logs")).unwrap();
            Self(std::fs::canonicalize(path).unwrap())
        }
        fn checkout(&self) -> &str {
            self.0.to_str().unwrap()
        }
        fn logs(&self) -> PathBuf {
            self.0.join("storage/logs")
        }
    }
    impl Drop for TempDir {
        fn drop(&mut self) {
            let _ = std::fs::remove_dir_all(&self.0);
        }
    }
    fn append(path: &Path, text: &[u8]) {
        std::fs::OpenOptions::new()
            .create(true)
            .append(true)
            .open(path)
            .unwrap()
            .write_all(text)
            .unwrap();
    }
    /// A user ID that the test files do not have, so the owner check passes as it would for `orbit`.
    fn other_uid(dir: &TempDir) -> u32 {
        let own = std::fs::metadata(&dir.0).unwrap().uid();
        if own == 0 {
            u32::MAX
        } else {
            0
        }
    }
    fn tail(dir: &TempDir) -> LaravelTail {
        LaravelTail::with_forbidden_owner(dir.checkout(), other_uid(dir))
    }

    #[test]
    fn daily_names() {
        assert!(is_daily_name(b"laravel-2026-09-25.log"));
        assert!(is_daily_name(b"laravel-cli_1.2.log"));
        assert!(!is_daily_name(b"laravel-.log"));
        assert!(!is_daily_name(b"laravel.log"));
        assert!(!is_daily_name(b"laravel-2026 09.log"));
        assert!(!is_daily_name(b"laravel-x.log.gz"));
        assert!(!is_daily_name(b"worker-2026-09-25.log"));
    }

    #[test]
    fn root_owned_or_irregular_files_are_refused() {
        assert!(acceptable(true, 1000, 0));
        assert!(!acceptable(true, 0, 0));
        assert!(!acceptable(false, 1000, 0));
    }

    #[test]
    fn first_lines_are_the_last_lines_and_new_lines_follow() {
        let dir = TempDir::new();
        let log = dir.logs().join("laravel.log");
        append(&log, b"one\ntwo\r\nthree\nfour\npartial");
        let mut t = tail(&dir);
        assert_eq!(t.start(2).unwrap(), ["three", "four"]);
        assert_eq!(t.poll(false).unwrap(), Output::default());
        append(&log, b" end\nfive\n");
        assert_eq!(t.poll(false).unwrap().lines, ["partial end", "five"]);
    }

    #[test]
    fn missing_logs_wait_and_the_file_is_read_from_its_start_when_it_appears() {
        let dir = TempDir::new();
        std::fs::remove_dir_all(dir.logs()).unwrap();
        let mut t = tail(&dir);
        assert!(t.start(10).unwrap().is_empty());
        assert!(t.poll(true).unwrap().lines.is_empty());
        std::fs::create_dir_all(dir.logs()).unwrap();
        assert!(t.poll(true).unwrap().lines.is_empty());
        append(&dir.logs().join("laravel.log"), b"a\nb\n");
        assert_eq!(t.poll(false).unwrap().lines, Vec::<String>::new());
        assert_eq!(t.poll(true).unwrap().lines, ["a", "b"]);
    }

    #[test]
    fn a_link_at_storage_logs_is_refused() {
        let dir = TempDir::new();
        std::fs::remove_dir_all(dir.logs()).unwrap();
        let target = TempDir::new();
        append(&target.logs().join("laravel.log"), b"secret\n");
        std::os::unix::fs::symlink(target.logs(), dir.logs()).unwrap();
        assert_eq!(tail(&dir).start(10), Err(Unavailable("storage/logs")));
    }

    #[test]
    fn links_are_allowed_for_the_checkout_and_storage() {
        let real = TempDir::new();
        append(&real.logs().join("laravel.log"), b"ok\n");
        let links = TempDir::new();
        let checkout = links.0.join("checkout");
        std::os::unix::fs::symlink(&real.0, &checkout).unwrap();
        let mut t = LaravelTail::with_forbidden_owner(checkout.to_str().unwrap(), other_uid(&real));
        assert_eq!(t.start(10).unwrap(), ["ok"]);
        let second = TempDir::new();
        std::fs::remove_dir_all(second.0.join("storage")).unwrap();
        std::os::unix::fs::symlink(real.0.join("storage"), second.0.join("storage")).unwrap();
        let mut t = tail(&second);
        assert_eq!(t.start(10).unwrap(), ["ok"]);
    }

    #[test]
    fn a_link_at_the_log_file_is_refused() {
        let dir = TempDir::new();
        let other = TempDir::new();
        append(&other.0.join("secret.txt"), b"secret\n");
        std::os::unix::fs::symlink(other.0.join("secret.txt"), dir.logs().join("laravel.log"))
            .unwrap();
        assert_eq!(tail(&dir).start(10), Err(Unavailable("log file")));
        // A link that is not named laravel.log is not a candidate at all.
        let dir = TempDir::new();
        std::os::unix::fs::symlink(
            other.0.join("secret.txt"),
            dir.logs().join("laravel-2099-01-01.log"),
        )
        .unwrap();
        assert!(tail(&dir).start(10).unwrap().is_empty());
    }

    #[test]
    fn traversal_names_cannot_escape_storage_logs() {
        let dir = TempDir::new();
        std::fs::create_dir_all(dir.0.join("storage/logs/sub")).unwrap();
        append(&dir.logs().join("sub/laravel.log"), b"nested\n");
        append(&dir.0.join("laravel-x.log"), b"outside\n");
        let mut t = tail(&dir);
        assert!(t.start(10).unwrap().is_empty());
        assert!(!is_daily_name(b"laravel-../x.log"));
        assert!(!is_daily_name(b"laravel-/x.log"));
    }

    #[test]
    fn owner_check_refuses_a_file_of_the_forbidden_user() {
        let dir = TempDir::new();
        append(&dir.logs().join("laravel.log"), b"x\n");
        let own = std::fs::metadata(dir.logs().join("laravel.log"))
            .unwrap()
            .uid();
        let mut t = LaravelTail::with_forbidden_owner(dir.checkout(), own);
        assert_eq!(t.start(10), Err(Unavailable("log file type or owner")));
    }

    #[test]
    fn a_directory_named_laravel_log_is_refused() {
        let dir = TempDir::new();
        std::fs::create_dir(dir.logs().join("laravel.log")).unwrap();
        assert_eq!(
            tail(&dir).start(10),
            Err(Unavailable("log file type or owner"))
        );
    }

    #[test]
    fn newest_daily_file_and_switch_to_the_next_day() {
        let dir = TempDir::new();
        let old = dir.logs().join("laravel-2026-09-24.log");
        let new = dir.logs().join("laravel-2026-09-25.log");
        append(&old, b"old\n");
        std::thread::sleep(Duration::from_millis(20));
        append(&new, b"new\n");
        let mut t = tail(&dir);
        assert_eq!(t.start(10).unwrap(), ["new"]);
        append(&new, b"last of day");
        std::thread::sleep(Duration::from_millis(20));
        let next = dir.logs().join("laravel-2026-09-26.log");
        append(&next, b"next day\n");
        let out = t.poll(true).unwrap();
        assert_eq!(out.lines, ["last of day", "next day"]);
        // laravel.log appearing wins over daily files.
        append(&dir.logs().join("laravel.log"), b"single\n");
        assert_eq!(t.poll(true).unwrap().lines, ["single"]);
    }

    #[test]
    fn a_replaced_file_with_the_same_name_is_read_from_its_start() {
        let dir = TempDir::new();
        let log = dir.logs().join("laravel.log");
        append(&log, b"first\n");
        let mut t = tail(&dir);
        assert_eq!(t.start(10).unwrap(), ["first"]);
        std::fs::rename(&log, dir.logs().join("rotated")).unwrap();
        append(&log, b"fresh\n");
        assert_eq!(t.poll(true).unwrap().lines, ["fresh"]);
    }

    #[test]
    fn truncation_restarts_at_the_beginning() {
        let dir = TempDir::new();
        let log = dir.logs().join("laravel.log");
        append(&log, b"aaaaaaaaaa\nbbbbbbbbbb\n");
        let mut t = tail(&dir);
        t.start(10).unwrap();
        std::fs::write(&log, b"new\n").unwrap();
        assert_eq!(t.poll(false).unwrap().lines, ["new"]);
    }

    #[test]
    fn long_lines_are_cut_once_and_the_rest_is_skipped() {
        let dir = TempDir::new();
        let log = dir.logs().join("laravel.log");
        append(&log, b"start\n");
        let mut t = tail(&dir);
        t.start(10).unwrap();
        append(&log, &vec![b'x'; 5000]);
        assert!(t.poll(false).unwrap().lines.is_empty());
        append(&log, &vec![b'y'; 5000]);
        let out = t.poll(false).unwrap();
        assert_eq!(out.lines.len(), 1);
        assert_eq!(out.lines[0].len(), MAX_LINE_BYTES);
        assert!(out.lines[0].ends_with("y [truncated]"));
        append(&log, b"yyy\nnext\n");
        assert_eq!(t.poll(false).unwrap().lines, ["next"]);
    }

    #[test]
    fn far_behind_skips_to_the_newest_64_kib_and_reads_1_mib_per_poll() {
        let dir = TempDir::new();
        let log = dir.logs().join("laravel.log");
        append(&log, b"start\n");
        let mut t = tail(&dir);
        t.start(10).unwrap();
        let line = format!("{}\n", "z".repeat(99));
        let big = line.repeat(50_000);
        append(&log, big.as_bytes());
        let out = t.poll(false).unwrap();
        assert!(out.skipped > 4 * 1024 * 1024);
        assert_eq!(out.lines.len(), 655);
        assert_eq!(out.skipped + 655 * 100, 5_000_000);
        let medium = line.repeat(20_000);
        append(&log, medium.as_bytes());
        let out = t.poll(false).unwrap();
        assert_eq!(out.skipped, 0);
        assert_eq!(out.lines.len(), 10_485);
        let out = t.poll(false).unwrap();
        assert_eq!(out.lines.len(), 20_000 - 10_485);
    }

    #[test]
    fn first_read_is_limited_to_the_last_256_kib() {
        let dir = TempDir::new();
        let log = dir.logs().join("laravel.log");
        let line = format!("{}\n", "q".repeat(1023));
        append(&log, line.repeat(1000).as_bytes());
        let mut t = tail(&dir);
        let first = t.start(1000).unwrap();
        assert_eq!(first.len(), 256);
    }
}
