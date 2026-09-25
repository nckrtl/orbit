//! Task checkout Git state (ADR 0151). Reads Git metadata with libgit2 in process; never runs a program.
use git2::{
    DiffFindOptions, DiffOptions, ErrorCode, FileMode, Oid, Patch, Repository, RepositoryOpenFlags,
    StatusOptions,
};
use serde::{Deserialize, Serialize};
use serde_json::Value;
use std::{
    collections::{BTreeMap, BTreeSet},
    ffi::OsStr,
    io::Read,
    path::{Path, PathBuf},
    sync::{Arc, Mutex},
    time::Duration,
};
use tokio::{sync::mpsc, time::Instant};

pub const MAX_WORKSPACES: usize = 64;
pub const MAX_DIFF_FILES: usize = 5_000;
pub const MAX_COUNTED_FILE: u64 = 1 << 20;
pub const MAX_COMMITS: usize = 1_000;
pub const MAX_NAME_CHARS: usize = 255;
pub const LIST_INTERVAL: Duration = Duration::from_secs(60);
pub const CHECK_INTERVAL: Duration = Duration::from_secs(2);
pub const FULL_READ_INTERVAL: Duration = Duration::from_secs(30);

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct WatchEntry {
    pub instance_id: u64,
    pub path: String,
    pub base: String,
    pub start: Option<String>,
}
#[derive(Debug, Clone, Copy, Serialize, Deserialize, PartialEq, Eq)]
pub struct DiffCounts {
    pub files: u64,
    pub added: u64,
    pub removed: u64,
    /// True when the diff has more than `MAX_DIFF_FILES` files: `files` is exact, and no lines are counted.
    pub truncated: bool,
}
#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Eq)]
pub struct WorkspaceState {
    pub instance_id: u64,
    pub base: String,
    pub start: Option<String>,
    pub branch: Option<String>,
    pub head: Option<String>,
    pub dirty: Option<bool>,
    pub commits: Option<u64>,
    pub diff: Option<DiffCounts>,
}
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct GitDirs {
    pub gitdir: PathBuf,
    pub commondir: PathBuf,
}
/// Size, modification time in nanoseconds, and inode; `None` for a missing file.
pub type Stamp = Option<(u64, u128, u64)>;
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Fingerprint {
    pub head_ref: Option<String>,
    pub files: Vec<Stamp>,
}
pub type SharedWorkspaces = Arc<Mutex<BTreeMap<u64, WorkspaceState>>>;
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum WorkspaceUpdate {
    /// The state of one checkout changed; read it from the shared map when sending.
    Changed(u64),
    /// The set of reported checkouts changed; send the complete list.
    List,
}

pub fn valid_checkout_path(path: &str) -> bool {
    path.starts_with('/')
        && path.len() <= 4096
        && !path.contains('\0')
        && path.split('/').all(|part| part != "." && part != "..")
}
pub fn valid_base(base: &str) -> bool {
    !base.is_empty() && base.chars().count() <= MAX_NAME_CHARS && !base.contains('\0')
}
pub fn is_commit_hex(value: &str) -> bool {
    value.len() == 40
        && value
            .bytes()
            .all(|b| b.is_ascii_digit() || (b'a'..=b'f').contains(&b))
}
fn watch_entry(value: &Value) -> Option<WatchEntry> {
    let instance_id = value.get("instance_id")?.as_u64().filter(|id| *id > 0)?;
    let path = value
        .get("path")?
        .as_str()
        .filter(|p| valid_checkout_path(p))?;
    let base = value.get("base")?.as_str().filter(|b| valid_base(b))?;
    let start = match value.get("start") {
        None | Some(Value::Null) => None,
        Some(Value::String(start)) if is_commit_hex(start) => Some(start.clone()),
        Some(_) => return None,
    };
    Some(WatchEntry {
        instance_id,
        path: path.into(),
        base: base.into(),
        start,
    })
}
/// Parses `GET /api/v1/agent/workspaces`. Invalid entries and repeated Instances are dropped.
pub fn parse_watch_list(body: &Value) -> Result<Vec<WatchEntry>, &'static str> {
    let data = body
        .get("data")
        .and_then(Value::as_array)
        .ok_or("workspace list has no data array")?;
    let mut seen = BTreeSet::new();
    Ok(data
        .iter()
        .filter_map(watch_entry)
        .filter(|entry| seen.insert(entry.instance_id))
        .take(MAX_WORKSPACES)
        .collect())
}

/// Process-wide libgit2 settings. Call once before the first read.
pub fn configure_libgit2() -> Result<(), git2::Error> {
    // SAFETY: these only set libgit2 globals and run before any repository is opened.
    unsafe {
        // Checkouts belong to `orbit` and the agent runs as root. The agent runs no repository program.
        git2::opts::set_verify_owner_validation(false)?;
        git2::opts::set_mwindow_size(8 << 20)?;
        git2::opts::set_mwindow_mapped_limit(32 << 20)?;
        libgit2_sys::init();
        let size: isize = 8 << 20;
        if libgit2_sys::git_libgit2_opts(libgit2_sys::GIT_OPT_SET_CACHE_MAX_SIZE as _, size) < 0 {
            return Err(git2::Error::from_str(
                "cannot set the libgit2 object cache size",
            ));
        }
    }
    Ok(())
}

fn safe_ref_path(name: &str) -> bool {
    !name.is_empty()
        && !name.starts_with('/')
        && !name.contains('\0')
        && name
            .split('/')
            .all(|part| !part.is_empty() && part != "." && part != "..")
}
fn stamp(path: &Path) -> Stamp {
    let meta = std::fs::metadata(path).ok()?;
    let mtime = meta
        .modified()
        .ok()
        .and_then(|time| time.duration_since(std::time::UNIX_EPOCH).ok())
        .map_or(0, |since| since.as_nanos());
    #[cfg(unix)]
    let inode = std::os::unix::fs::MetadataExt::ino(&meta);
    #[cfg(not(unix))]
    let inode = 0;
    Some((meta.len(), mtime, inode))
}
/// The ref that `HEAD` names, read from at most 4 KiB of the file; `None` when detached or unreadable.
pub fn head_ref(gitdir: &Path) -> Option<String> {
    let mut text = String::new();
    std::fs::File::open(gitdir.join("HEAD"))
        .ok()?
        .take(4096)
        .read_to_string(&mut text)
        .ok()?;
    let name = text.strip_prefix("ref: ")?.trim();
    safe_ref_path(name).then(|| name.to_owned())
}
/// Stats the Git files whose change means the checkout must be read again.
pub fn fingerprint(dirs: &GitDirs, base: &str) -> Fingerprint {
    let head_ref = head_ref(&dirs.gitdir);
    let mut paths = vec![
        dirs.gitdir.join("HEAD"),
        dirs.gitdir.join("index"),
        dirs.commondir.join("packed-refs"),
    ];
    if let Some(name) = &head_ref {
        paths.push(dirs.commondir.join(name));
    }
    for name in [
        format!("refs/heads/{base}"),
        format!("refs/remotes/origin/{base}"),
    ] {
        if safe_ref_path(&name) {
            paths.push(dirs.commondir.join(name));
        }
    }
    Fingerprint {
        head_ref,
        files: paths.iter().map(|path| stamp(path)).collect(),
    }
}

fn fail(message: &str) -> git2::Error {
    git2::Error::from_str(message)
}
/// Opens exactly `path` as a non-bare work tree, never searching parent directories.
pub fn open_checkout(path: &str) -> Result<Repository, git2::Error> {
    let repo = Repository::open_ext(path, RepositoryOpenFlags::NO_SEARCH, &[] as &[&OsStr])?;
    let workdir = repo.workdir().ok_or_else(|| fail("not a work tree"))?;
    let same = match (std::fs::canonicalize(workdir), std::fs::canonicalize(path)) {
        (Ok(a), Ok(b)) => a == b,
        _ => false,
    };
    if repo.is_bare() || !same {
        return Err(fail("the path is not the root of a work tree"));
    }
    Ok(repo)
}
/// Reads one checkout's state. An error means the checkout is left out of reports.
pub fn read_checkout(entry: &WatchEntry) -> Result<(WorkspaceState, GitDirs), git2::Error> {
    let repo = open_checkout(&entry.path)?;
    let dirs = GitDirs {
        gitdir: repo.path().to_path_buf(),
        commondir: repo.commondir().to_path_buf(),
    };
    let head_reference = repo.find_reference("HEAD")?;
    let branch = head_reference
        .symbolic_target()
        .and_then(|target| target.strip_prefix("refs/heads/"))
        .map(str::to_owned);
    if branch
        .as_ref()
        .is_some_and(|b| b.chars().count() > MAX_NAME_CHARS)
    {
        return Err(fail("branch name is longer than 255 characters"));
    }
    let head = match repo.head() {
        Ok(reference) => Some(reference.peel_to_commit()?.id()),
        Err(error) if matches!(error.code(), ErrorCode::UnbornBranch | ErrorCode::NotFound) => None,
        Err(error) => return Err(error),
    };
    let state = WorkspaceState {
        instance_id: entry.instance_id,
        base: entry.base.clone(),
        start: entry.start.clone(),
        branch,
        head: head.map(|oid| oid.to_string()),
        dirty: dirty(&repo),
        commits: head.and_then(|head| commits(&repo, head, entry.start.as_deref()?)),
        diff: head.and_then(|head| diff_counts(&repo, &entry.base, head)),
    };
    Ok((state, dirs))
}
fn dirty(repo: &Repository) -> Option<bool> {
    let mut options = StatusOptions::new();
    options
        .include_untracked(true)
        .recurse_untracked_dirs(false)
        .include_ignored(false)
        .exclude_submodules(true);
    repo.statuses(Some(&mut options))
        .ok()
        .map(|statuses| !statuses.is_empty())
}
fn commits(repo: &Repository, head: Oid, start: &str) -> Option<u64> {
    let start = repo.find_commit(Oid::from_str(start).ok()?).ok()?.id();
    let mut walk = repo.revwalk().ok()?;
    walk.push(head).ok()?;
    walk.hide(start).ok()?;
    let mut count = 0;
    for oid in walk.take(MAX_COMMITS) {
        oid.ok()?;
        count += 1;
    }
    Some(count)
}
/// `git diff --numstat base...HEAD` totals within the agent's limits.
pub fn diff_counts(repo: &Repository, base: &str, head: Oid) -> Option<DiffCounts> {
    diff_counts_within(repo, base, head, MAX_DIFF_FILES)
}
fn diff_counts_within(
    repo: &Repository,
    base: &str,
    head: Oid,
    max_files: usize,
) -> Option<DiffCounts> {
    let base = repo.revparse_single(base).ok()?.peel_to_commit().ok()?.id();
    let merge_base = repo.merge_base(base, head).ok()?;
    let old_tree = repo.find_commit(merge_base).ok()?.tree().ok()?;
    let new_tree = repo.find_commit(head).ok()?.tree().ok()?;
    let mut options = DiffOptions::new();
    options.max_size(MAX_COUNTED_FILE as i64);
    let mut diff = repo
        .diff_tree_to_tree(Some(&old_tree), Some(&new_tree), Some(&mut options))
        .ok()?;
    if diff.deltas().len() > max_files {
        // Exact renames match by object id without loading blobs, so a rename counts as one file.
        diff.find_similar(Some(
            DiffFindOptions::new().renames(true).exact_match_only(true),
        ))
        .ok()?;
        return Some(DiffCounts {
            files: diff.deltas().len() as u64,
            added: 0,
            removed: 0,
            truncated: true,
        });
    }
    // Rename detection loads whole blobs, so with a large added or deleted file only exact renames count.
    let odb = repo.odb().ok()?;
    let large = diff.deltas().any(|delta| {
        let file = match delta.status() {
            git2::Delta::Added => delta.new_file(),
            git2::Delta::Deleted => delta.old_file(),
            _ => return false,
        };
        file.mode() != FileMode::Commit
            && odb
                .read_header(file.id())
                .map_or(true, |(size, _)| size as u64 > MAX_COUNTED_FILE)
    });
    if large {
        diff.find_similar(Some(
            DiffFindOptions::new().renames(true).exact_match_only(true),
        ))
        .ok()?;
    } else {
        diff.find_similar(None).ok()?;
    }
    let mut counts = DiffCounts {
        files: 0,
        added: 0,
        removed: 0,
        truncated: false,
    };
    for (index, delta) in diff.deltas().enumerate() {
        counts.files += 1;
        // A changed submodule counts as one file with no lines, and is never opened.
        if delta.old_file().mode() == FileMode::Commit
            || delta.new_file().mode() == FileMode::Commit
        {
            continue;
        }
        if let Some(patch) = Patch::from_diff(&diff, index).ok()? {
            let (_, added, removed) = patch.line_stats().ok()?;
            counts.added += added as u64;
            counts.removed += removed as u64;
        }
    }
    Some(counts)
}

struct Watched {
    entry: WatchEntry,
    dirs: Option<GitDirs>,
    fingerprint: Option<Fingerprint>,
    last_read: Option<Instant>,
    error: Option<String>,
}
impl Watched {
    fn new(entry: WatchEntry) -> Self {
        Self {
            entry,
            dirs: None,
            fingerprint: None,
            last_read: None,
            error: None,
        }
    }
}

async fn fetch_watch_list(
    client: &reqwest::Client,
    gateway: &str,
) -> Result<Vec<WatchEntry>, Box<dyn std::error::Error + Send + Sync>> {
    let body: Value = client
        .get(format!(
            "{}/api/v1/agent/workspaces",
            gateway.trim_end_matches('/')
        ))
        .timeout(Duration::from_secs(15))
        .send()
        .await?
        .error_for_status()?
        .json()
        .await?;
    Ok(parse_watch_list(&body)?)
}
/// Locks the shared states, recovering from a poisoned lock.
pub fn lock_workspaces(
    shared: &SharedWorkspaces,
) -> std::sync::MutexGuard<'_, BTreeMap<u64, WorkspaceState>> {
    shared
        .lock()
        .unwrap_or_else(|poisoned| poisoned.into_inner())
}
/// Replaces the watched set. Returns true when an Instance was added or removed, or its path, base, or start changed.
fn apply_watch_list(
    watched: &mut BTreeMap<u64, Watched>,
    shared: &SharedWorkspaces,
    entries: Vec<WatchEntry>,
) -> bool {
    let mut changed = entries.len() != watched.len();
    let mut next = BTreeMap::new();
    for entry in entries {
        match watched.remove(&entry.instance_id) {
            Some(existing) if existing.entry == entry => {
                next.insert(entry.instance_id, existing);
            }
            _ => {
                changed = true;
                next.insert(entry.instance_id, Watched::new(entry));
            }
        }
    }
    *watched = next;
    if changed {
        lock_workspaces(shared).retain(|id, state| {
            watched.get(id).is_some_and(|w| {
                w.entry.base == state.base && w.entry.start == state.start && w.dirs.is_some()
            })
        });
    }
    changed
}
/// Reads every checkout whose Git files changed, that was never read, or that was read 30 s ago.
async fn check_pass(
    watched: &mut BTreeMap<u64, Watched>,
    shared: &SharedWorkspaces,
    tx: &mpsc::UnboundedSender<WorkspaceUpdate>,
    send_events: bool,
) {
    let mut list_changed = false;
    for (id, item) in watched.iter_mut() {
        let before = item
            .dirs
            .as_ref()
            .map(|dirs| fingerprint(dirs, &item.entry.base));
        let due = item.dirs.is_none()
            || before != item.fingerprint
            || item
                .last_read
                .is_none_or(|at| at.elapsed() >= FULL_READ_INTERVAL);
        if !due {
            continue;
        }
        let entry = item.entry.clone();
        let result = tokio::task::spawn_blocking(move || read_checkout(&entry))
            .await
            .map_err(|error| error.to_string())
            .and_then(|read| read.map_err(|error| error.message().to_owned()));
        item.last_read = Some(Instant::now());
        match result {
            Ok((state, dirs)) => {
                item.fingerprint =
                    Some(before.unwrap_or_else(|| fingerprint(&dirs, &item.entry.base)));
                item.dirs = Some(dirs);
                item.error = None;
                let mut map = lock_workspaces(shared);
                if map.get(id) != Some(&state) {
                    map.insert(*id, state);
                    drop(map);
                    if send_events {
                        let _ = tx.send(WorkspaceUpdate::Changed(*id));
                    }
                }
            }
            Err(error) => {
                item.fingerprint = before;
                if item.error.as_ref() != Some(&error) {
                    eprintln!(
                        "orbit-agent: cannot read the task checkout of Instance {id}: {error}"
                    );
                    item.error = Some(error);
                }
                list_changed |= lock_workspaces(shared).remove(id).is_some();
            }
        }
    }
    if list_changed && send_events {
        let _ = tx.send(WorkspaceUpdate::List);
    }
}
/// Pulls the watch list every 60 s and checks the listed checkouts every 2 s, also while realtime is down.
pub async fn watch_workspaces(
    client: reqwest::Client,
    gateway: String,
    shared: SharedWorkspaces,
    tx: mpsc::UnboundedSender<WorkspaceUpdate>,
) {
    let mut watched = BTreeMap::new();
    let mut list_tick = tokio::time::interval(LIST_INTERVAL);
    let mut check_tick = tokio::time::interval(CHECK_INTERVAL);
    list_tick.set_missed_tick_behavior(tokio::time::MissedTickBehavior::Delay);
    check_tick.set_missed_tick_behavior(tokio::time::MissedTickBehavior::Delay);
    let mut last_error: Option<String> = None;
    loop {
        tokio::select! {
            biased;
            _ = list_tick.tick() => match fetch_watch_list(&client, &gateway).await {
                Ok(entries) => {
                    last_error = None;
                    if apply_watch_list(&mut watched, &shared, entries) {
                        check_pass(&mut watched, &shared, &tx, false).await;
                        let _ = tx.send(WorkspaceUpdate::List);
                    }
                }
                Err(error) => {
                    let error = error.to_string();
                    if last_error.as_ref() != Some(&error) {
                        eprintln!("orbit-agent: task workspace list failed: {error}");
                        last_error = Some(error);
                    }
                }
            },
            _ = check_tick.tick() => check_pass(&mut watched, &shared, &tx, true).await,
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use git2::{BranchType, IndexEntry, IndexTime, RepositoryInitOptions, Signature};
    use serde_json::json;

    struct TempDir(PathBuf);
    impl TempDir {
        fn new(name: &str) -> Self {
            let path = std::env::temp_dir().join(format!(
                "orbit-agent-{name}-{}-{}",
                std::process::id(),
                rand::random::<u64>()
            ));
            std::fs::create_dir_all(&path).unwrap();
            Self(std::fs::canonicalize(path).unwrap())
        }
        fn path(&self, sub: &str) -> PathBuf {
            self.0.join(sub)
        }
    }
    impl Drop for TempDir {
        fn drop(&mut self) {
            let _ = std::fs::remove_dir_all(&self.0);
        }
    }
    fn init(dir: &Path) -> Repository {
        configure_libgit2().unwrap();
        Repository::init_opts(dir, RepositoryInitOptions::new().initial_head("main")).unwrap()
    }
    fn sig() -> Signature<'static> {
        Signature::now("Orbit", "orbit@example.test").unwrap()
    }
    /// Writes and stages files (None deletes), then commits on HEAD.
    fn commit(repo: &Repository, files: &[(&str, Option<&[u8]>)], message: &str) -> Oid {
        let workdir = repo.workdir().unwrap().to_path_buf();
        let mut index = repo.index().unwrap();
        for (name, content) in files {
            match content {
                Some(bytes) => {
                    std::fs::write(workdir.join(name), bytes).unwrap();
                    index.add_path(Path::new(name)).unwrap();
                }
                None => {
                    std::fs::remove_file(workdir.join(name)).unwrap();
                    index.remove_path(Path::new(name)).unwrap();
                }
            }
        }
        index.write().unwrap();
        commit_index(repo, &mut index, message)
    }
    fn commit_index(repo: &Repository, index: &mut git2::Index, message: &str) -> Oid {
        let tree = repo.find_tree(index.write_tree().unwrap()).unwrap();
        let parents = repo
            .head()
            .ok()
            .map(|head| vec![head.peel_to_commit().unwrap()])
            .unwrap_or_default();
        let parents = parents.iter().collect::<Vec<_>>();
        repo.commit(Some("HEAD"), &sig(), &sig(), message, &tree, &parents)
            .unwrap()
    }
    fn entry(path: &Path, base: &str, start: Option<Oid>) -> WatchEntry {
        WatchEntry {
            instance_id: 31,
            path: path.to_str().unwrap().into(),
            base: base.into(),
            start: start.map(|oid| oid.to_string()),
        }
    }
    fn lines(from: usize, to: usize) -> Vec<u8> {
        (from..to)
            .map(|n| format!("line {n}\n"))
            .collect::<String>()
            .into_bytes()
    }

    /// main: a.txt, b.txt, r.txt. task-1: edits a, adds c, a 1.2 MiB file, a submodule, deletes b, renames r.
    /// main then moves on with d.txt, which the three-dot diff leaves out.
    fn task_repo(dir: &TempDir) -> (Repository, Oid, Oid) {
        let repo = init(&dir.path("repo"));
        let root = commit(
            &repo,
            &[
                ("a.txt", Some(b"one\ntwo\nthree\n")),
                ("b.txt", Some(b"bee\nbee\n")),
                ("r.txt", Some(&lines(0, 5))),
            ],
            "root",
        );
        let root_commit = repo.find_commit(root).unwrap();
        repo.branch("task-1", &root_commit, false).unwrap();
        repo.set_head("refs/heads/task-1").unwrap();
        let big = "x".repeat(99).to_string() + "\n";
        commit(
            &repo,
            &[
                ("a.txt", Some(b"one\nTWO\nthree\nfour\n")),
                ("c.txt", Some(b"c1\nc2\nc3\n")),
                ("big.txt", Some(big.repeat(12_000).as_bytes())),
            ],
            "task one",
        );
        let mut index = repo.index().unwrap();
        index
            .add(&IndexEntry {
                ctime: IndexTime::new(0, 0),
                mtime: IndexTime::new(0, 0),
                dev: 0,
                ino: 0,
                mode: 0o160000,
                uid: 0,
                gid: 0,
                file_size: 0,
                id: root,
                flags: 3, // path length
                flags_extended: 0,
                path: b"sub".to_vec(),
            })
            .unwrap();
        let workdir = repo.workdir().unwrap().to_path_buf();
        std::fs::rename(workdir.join("r.txt"), workdir.join("r2.txt")).unwrap();
        index.remove_path(Path::new("r.txt")).unwrap();
        index.add_path(Path::new("r2.txt")).unwrap();
        index.write().unwrap();
        commit_index(&repo, &mut index, "task two");
        std::fs::create_dir_all(workdir.join("sub")).unwrap();
        let head = commit(&repo, &[("b.txt", None)], "task three");
        // main moves on without checking it out.
        let mut main_index = git2::Index::new().unwrap();
        main_index.read_tree(&root_commit.tree().unwrap()).unwrap();
        let blob = repo.blob(b"d\n").unwrap();
        main_index
            .add(&IndexEntry {
                ctime: IndexTime::new(0, 0),
                mtime: IndexTime::new(0, 0),
                dev: 0,
                ino: 0,
                mode: 0o100644,
                uid: 0,
                gid: 0,
                file_size: 2,
                id: blob,
                flags: 5,
                flags_extended: 0,
                path: b"d.txt".to_vec(),
            })
            .unwrap();
        let tree = repo
            .find_tree(main_index.write_tree_to(&repo).unwrap())
            .unwrap();
        repo.commit(
            Some("refs/heads/main"),
            &sig(),
            &sig(),
            "main moves",
            &tree,
            &[&root_commit],
        )
        .unwrap();
        drop(tree);
        drop(root_commit);
        (repo, root, head)
    }

    #[test]
    fn checkout_paths_must_be_absolute_and_plain() {
        assert!(valid_checkout_path("/home/orbit/apps/x/task-58"));
        assert!(valid_checkout_path("/home/orbit/apps/x/task-58/"));
        for bad in [
            "",
            "relative/path",
            "/home/orbit/../root",
            "/home/./orbit",
            "/home/orbit/..",
            "/home/orbit/.",
            "/home/or\0bit",
        ] {
            assert!(!valid_checkout_path(bad), "{bad:?}");
        }
        assert!(!valid_checkout_path(&format!("/{}", "a".repeat(4096))));
        assert!(valid_checkout_path("/home/orbit/.git-like/..x"));
    }

    #[test]
    fn watch_list_keeps_valid_unique_entries_up_to_64() {
        let hex = "9f2c4be07d1a6c35e8f0b2a4d6c8e0f1a3b5c7d9";
        let body = json!({"data": [
            {"instance_id": 31, "path": "/home/orbit/apps/x/y", "base": "main", "start": hex},
            {"instance_id": 32, "path": "/home/orbit/apps/x/z", "base": "main", "start": null},
            {"instance_id": 31, "path": "/other", "base": "main", "start": null},
            {"instance_id": 0, "path": "/a", "base": "main", "start": null},
            {"instance_id": -3, "path": "/a", "base": "main", "start": null},
            {"instance_id": 3.5, "path": "/a", "base": "main", "start": null},
            {"instance_id": "4", "path": "/a", "base": "main", "start": null},
            {"instance_id": 5, "path": "a", "base": "main", "start": null},
            {"instance_id": 6, "path": "/a/../b", "base": "main", "start": null},
            {"instance_id": 7, "path": "/a", "base": "", "start": null},
            {"instance_id": 8, "path": "/a", "base": "m".repeat(256), "start": null},
            {"instance_id": 9, "path": "/a", "base": "ma\u{0}in", "start": null},
            {"instance_id": 10, "path": "/a", "base": "main", "start": hex.to_uppercase()},
            {"instance_id": 11, "path": "/a", "base": "main", "start": "abc"},
            {"instance_id": 12, "path": "/a", "base": "main", "start": 5},
            {"instance_id": 13, "base": "main"},
            "not an object"
        ], "meta": {"request_id": "x"}});
        let entries = parse_watch_list(&body).unwrap();
        assert_eq!(
            entries,
            vec![
                WatchEntry {
                    instance_id: 31,
                    path: "/home/orbit/apps/x/y".into(),
                    base: "main".into(),
                    start: Some(hex.into())
                },
                WatchEntry {
                    instance_id: 32,
                    path: "/home/orbit/apps/x/z".into(),
                    base: "main".into(),
                    start: None
                },
            ]
        );
        let many = json!({"data": (1..=100).map(|id| json!({"instance_id": id, "path": format!("/w/{id}"), "base": "main", "start": null})).collect::<Vec<_>>()});
        let entries = parse_watch_list(&many).unwrap();
        assert_eq!(entries.len(), MAX_WORKSPACES);
        assert_eq!(entries.last().unwrap().instance_id, 64);
        assert!(parse_watch_list(&json!({"data": {}})).is_err());
        assert!(parse_watch_list(&json!({"message": "x"})).is_err());
        assert!(parse_watch_list(&json!({"data": []})).unwrap().is_empty());
    }

    #[test]
    fn workspace_state_serializes_with_every_field() {
        let state = WorkspaceState {
            instance_id: 31,
            base: "main".into(),
            start: Some("9f2c4be07d1a6c35e8f0b2a4d6c8e0f1a3b5c7d9".into()),
            branch: Some("task-58".into()),
            head: Some("1f2c4be07d1a6c35e8f0b2a4d6c8e0f1a3b5c7d9".into()),
            dirty: Some(true),
            commits: Some(3),
            diff: Some(DiffCounts {
                files: 4,
                added: 120,
                removed: 7,
                truncated: false,
            }),
        };
        assert_eq!(
            serde_json::to_string(&state).unwrap(),
            r#"{"instance_id":31,"base":"main","start":"9f2c4be07d1a6c35e8f0b2a4d6c8e0f1a3b5c7d9","branch":"task-58","head":"1f2c4be07d1a6c35e8f0b2a4d6c8e0f1a3b5c7d9","dirty":true,"commits":3,"diff":{"files":4,"added":120,"removed":7,"truncated":false}}"#
        );
        let empty = WorkspaceState {
            instance_id: 1,
            base: "main".into(),
            start: None,
            branch: None,
            head: None,
            dirty: None,
            commits: None,
            diff: None,
        };
        assert_eq!(
            serde_json::to_value(&empty).unwrap(),
            json!({"instance_id":1,"base":"main","start":null,"branch":null,"head":null,"dirty":null,"commits":null,"diff":null})
        );
    }

    #[test]
    fn reads_branch_commits_and_three_dot_diff() {
        let dir = TempDir::new("state");
        let (repo, root, head) = task_repo(&dir);
        let path = repo.workdir().unwrap().to_path_buf();
        let (state, dirs) = read_checkout(&entry(&path, "main", Some(root))).unwrap();
        assert_eq!(dirs.gitdir, dirs.commondir);
        assert_eq!(
            state,
            WorkspaceState {
                instance_id: 31,
                base: "main".into(),
                start: Some(root.to_string()),
                branch: Some("task-1".into()),
                head: Some(head.to_string()),
                dirty: Some(false),
                commits: Some(3),
                // a.txt +2 -1, c.txt +3, big.txt 0 (over 1 MiB), sub 0, b.txt -2, r.txt => r2.txt 0.
                diff: Some(DiffCounts {
                    files: 6,
                    added: 5,
                    removed: 3,
                    truncated: false
                }),
            }
        );
        // An untracked file makes the checkout dirty; an ignored one does not.
        std::fs::write(path.join(".gitignore"), "ignored.log\n").unwrap();
        let mut index = repo.index().unwrap();
        index.add_path(Path::new(".gitignore")).unwrap();
        index.write().unwrap();
        let head = commit_index(&repo, &mut index, "ignore");
        std::fs::write(path.join("ignored.log"), "x").unwrap();
        let (state, _) = read_checkout(&entry(&path, "main", Some(root))).unwrap();
        assert_eq!(state.dirty, Some(false));
        assert_eq!(state.commits, Some(4));
        std::fs::write(path.join("new.txt"), "x").unwrap();
        let (state, _) = read_checkout(&entry(&path, "main", Some(root))).unwrap();
        assert_eq!(state.dirty, Some(true));
        // Unknown start and unknown base read as null; a missing start is null too.
        let unknown = Oid::from_str(&"0".repeat(40)).unwrap();
        let (state, _) = read_checkout(&entry(&path, "nope", Some(unknown))).unwrap();
        assert_eq!((state.commits, state.diff), (None, None));
        let (state, _) = read_checkout(&entry(&path, "main", None)).unwrap();
        assert_eq!(state.commits, None);
        assert!(state.diff.is_some());
        // Detached HEAD has no branch.
        repo.set_head_detached(head).unwrap();
        let (state, _) = read_checkout(&entry(&path, "main", Some(root))).unwrap();
        assert_eq!(state.branch, None);
        assert_eq!(state.head, Some(head.to_string()));
    }

    #[test]
    fn diff_counts_match_numstat_for_text_files_and_the_commit_cap() {
        let dir = TempDir::new("numstat");
        let repo = init(&dir.path("repo"));
        let root = commit(&repo, &[("f.txt", Some(&lines(0, 100)))], "root");
        let mut edited = lines(0, 100);
        edited.truncate(edited.len() - lines(90, 100).len());
        edited.extend(lines(200, 230));
        commit(
            &repo,
            &[("f.txt", Some(&edited)), ("n.txt", Some(b"no newline"))],
            "edit",
        );
        // numstat: f.txt 30 added 10 removed; n.txt 1 added.
        let head = repo.head().unwrap().target().unwrap();
        let counts = diff_counts(&repo, &root.to_string(), head).unwrap();
        assert_eq!(
            counts,
            DiffCounts {
                files: 2,
                added: 31,
                removed: 10,
                truncated: false
            }
        );
        // Over the file limit: the file count stays exact and no lines are counted.
        assert_eq!(
            diff_counts_within(&repo, &root.to_string(), head, 1).unwrap(),
            DiffCounts {
                files: 2,
                added: 0,
                removed: 0,
                truncated: true
            }
        );
        // Same commit: an empty diff.
        assert_eq!(
            diff_counts(&repo, "main", head).unwrap(),
            DiffCounts {
                files: 0,
                added: 0,
                removed: 0,
                truncated: false
            }
        );
        for n in 0..1005 {
            commit(
                &repo,
                &[("f.txt", Some(format!("{n}\n").as_bytes()))],
                "step",
            );
        }
        let path = repo.workdir().unwrap().to_path_buf();
        let (state, _) = read_checkout(&entry(&path, "main", Some(root))).unwrap();
        assert_eq!(state.commits, Some(MAX_COMMITS as u64));
    }

    #[test]
    fn empty_repository_has_a_branch_but_no_head() {
        let dir = TempDir::new("empty");
        let repo = init(&dir.path("repo"));
        let path = repo.workdir().unwrap().to_path_buf();
        let (state, _) = read_checkout(&entry(&path, "main", None)).unwrap();
        assert_eq!(state.branch.as_deref(), Some("main"));
        assert_eq!((state.head, state.commits, state.diff), (None, None, None));
        assert_eq!(state.dirty, Some(false));
    }

    #[test]
    fn opens_only_the_named_work_tree() {
        let dir = TempDir::new("open");
        let (repo, root, _) = task_repo(&dir);
        let path = repo.workdir().unwrap().to_path_buf();
        std::fs::create_dir_all(path.join("nested")).unwrap();
        // A subdirectory never walks up to the parent repository.
        assert!(read_checkout(&entry(&path.join("nested"), "main", None)).is_err());
        // The Git directory itself is not a work tree root.
        assert!(read_checkout(&entry(&path.join(".git"), "main", None)).is_err());
        assert!(read_checkout(&entry(&dir.path("missing"), "main", None)).is_err());
        let bare = Repository::init_bare(dir.path("bare.git")).unwrap();
        assert!(read_checkout(&entry(bare.path(), "main", None)).is_err());
        // A linked worktree reads through its `.git` file.
        let linked = dir.path("linked");
        let worktree = repo.worktree("wt-7", &linked, None).unwrap();
        let (state, dirs) = read_checkout(&entry(worktree.path(), "main", Some(root))).unwrap();
        assert_eq!(state.branch.as_deref(), Some("wt-7"));
        assert_eq!(
            state.head,
            Some(repo.head().unwrap().target().unwrap().to_string())
        );
        assert_ne!(dirs.gitdir, dirs.commondir);
        assert_eq!(state.dirty, Some(false));
        assert_eq!(state.commits, Some(3));
        assert_eq!(state.diff.map(|d| d.files), Some(6));
        assert!(repo.find_branch("wt-7", BranchType::Local).is_ok());
    }

    #[test]
    fn fingerprint_changes_with_head_and_refs() {
        let dir = TempDir::new("fingerprint");
        let (repo, _, _) = task_repo(&dir);
        let path = repo.workdir().unwrap().to_path_buf();
        let (_, dirs) = read_checkout(&entry(&path, "main", None)).unwrap();
        let first = fingerprint(&dirs, "main");
        assert_eq!(first.head_ref.as_deref(), Some("refs/heads/task-1"));
        assert!(first.files.iter().all(Option::is_some) || first.files.len() == 6);
        assert_eq!(first, fingerprint(&dirs, "main"));
        commit(&repo, &[("e.txt", Some(b"e\n"))], "more");
        let second = fingerprint(&dirs, "main");
        assert_ne!(first, second);
        repo.set_head("refs/heads/main").unwrap();
        let third = fingerprint(&dirs, "main");
        assert_ne!(second, third);
        assert_eq!(third.head_ref.as_deref(), Some("refs/heads/main"));
        let main = repo.head().unwrap().peel_to_commit().unwrap();
        repo.reference(
            "refs/heads/main",
            main.parent_id(0).unwrap_or(main.id()),
            true,
            "move",
        )
        .unwrap();
        assert_ne!(third, fingerprint(&dirs, "main"));
        // A base that cannot name a ref file is skipped, not joined.
        assert_eq!(fingerprint(&dirs, "../../x").files.len(), 4);
    }

    #[tokio::test]
    async fn watch_list_changes_drop_stale_states() {
        let dir = TempDir::new("watch");
        let (repo, root, _) = task_repo(&dir);
        let path = repo.workdir().unwrap().to_path_buf();
        let shared = SharedWorkspaces::default();
        let (tx, mut rx) = mpsc::unbounded_channel();
        let mut watched = BTreeMap::new();
        let first = entry(&path, "main", Some(root));
        assert!(apply_watch_list(&mut watched, &shared, vec![first.clone()]));
        check_pass(&mut watched, &shared, &tx, false).await;
        assert!(rx.try_recv().is_err());
        assert_eq!(lock_workspaces(&shared)[&31].commits, Some(3));
        assert!(!apply_watch_list(
            &mut watched,
            &shared,
            vec![first.clone()]
        ));
        check_pass(&mut watched, &shared, &tx, true).await;
        assert!(rx.try_recv().is_err(), "no event without a change");
        commit(&repo, &[("e.txt", Some(b"e\n"))], "more");
        check_pass(&mut watched, &shared, &tx, true).await;
        assert_eq!(rx.try_recv().unwrap(), WorkspaceUpdate::Changed(31));
        assert_eq!(lock_workspaces(&shared)[&31].commits, Some(4));
        let moved = WatchEntry {
            base: "task-1".into(),
            ..first
        };
        assert!(apply_watch_list(&mut watched, &shared, vec![moved]));
        assert!(lock_workspaces(&shared).is_empty());
        assert!(apply_watch_list(&mut watched, &shared, vec![]));
        let broken = WatchEntry {
            path: dir.path("missing").to_str().unwrap().into(),
            ..entry(&path, "main", None)
        };
        assert!(apply_watch_list(&mut watched, &shared, vec![broken]));
        check_pass(&mut watched, &shared, &tx, true).await;
        assert!(lock_workspaces(&shared).is_empty());
        assert!(rx.try_recv().is_err());
    }
}
