//! An in-process reader of systemd journal files (ADR 0153), following
//! <https://systemd.io/JOURNAL_FILE_FORMAT/>. It reads with `pread`, never maps a file, and checks every
//! offset and size. A file that is being written can look inconsistent; the reader then tries that file
//! again at the next poll. It links no systemd library and runs no program.
use super::laravel::Unavailable;
use rustix::fs::{self as rfs, Mode, OFlags};
use std::{
    collections::{BTreeMap, HashMap, HashSet},
    fs::File,
    io::Read,
    os::unix::fs::{FileExt, MetadataExt},
    path::{Path, PathBuf},
    time::Duration,
};

pub const POLL_INTERVAL: Duration = Duration::from_millis(500);
/// Every fourth poll (2 seconds) looks for new journal files.
pub const RESCAN_EVERY: u32 = 4;
/// New matching entries read per data object and poll; older ones are skipped and counted as dropped.
pub const MAX_NEW_ENTRIES: u64 = 10_000;
/// Formatted bytes one poll reads at most; the rest is counted as dropped. The rates allow far less.
pub const MAX_POLL_BYTES: usize = 512 * 1024;
pub const MAX_MESSAGE_BYTES: usize = 16 * 1024;
const MAX_SMALL_FIELD: usize = 256;
const MAX_ENTRY_ITEMS: u64 = 4_096;
const MAX_CHAIN: u64 = 1 << 20;
const MAX_COMPRESSED_READ: u64 = 4 * 1024 * 1024;
const MAX_DECOMPRESSED: usize = 16 * 1024 * 1024;
pub const UNREADABLE: &str = "[orbit] entry not readable";

pub const SIGNATURE: &[u8; 8] = b"LPKSHHRH";
pub const INCOMPATIBLE_COMPRESSED_XZ: u32 = 1;
pub const INCOMPATIBLE_COMPRESSED_LZ4: u32 = 2;
pub const INCOMPATIBLE_KEYED_HASH: u32 = 4;
pub const INCOMPATIBLE_COMPRESSED_ZSTD: u32 = 8;
pub const INCOMPATIBLE_COMPACT: u32 = 16;
const INCOMPATIBLE_KNOWN: u32 = 31;
pub const OBJECT_DATA: u8 = 1;
pub const OBJECT_ENTRY: u8 = 3;
pub const OBJECT_ENTRY_ARRAY: u8 = 6;
pub const OBJECT_COMPRESSED_XZ: u8 = 1;
pub const OBJECT_COMPRESSED_LZ4: u8 = 2;
pub const OBJECT_COMPRESSED_ZSTD: u8 = 4;
pub const STATE_ARCHIVED: u8 = 2;
pub const HEADER_READ: usize = 272;
const HEADER_MIN: u64 = 208;

/// An inconsistency or read error. The file is tried again at the next poll.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Bad(pub &'static str);
type R<T> = Result<T, Bad>;

pub fn siphash24(data: &[u8], key: &[u8; 16]) -> u64 {
    let k0 = u64::from_le_bytes(key[..8].try_into().expect("8 bytes"));
    let k1 = u64::from_le_bytes(key[8..].try_into().expect("8 bytes"));
    let mut v = [
        0x736f_6d65_7073_6575 ^ k0,
        0x646f_7261_6e64_6f6d ^ k1,
        0x6c79_6765_6e65_7261 ^ k0,
        0x7465_6462_7974_6573 ^ k1,
    ];
    fn round(v: &mut [u64; 4]) {
        v[0] = v[0].wrapping_add(v[1]);
        v[1] = v[1].rotate_left(13) ^ v[0];
        v[0] = v[0].rotate_left(32);
        v[2] = v[2].wrapping_add(v[3]);
        v[3] = v[3].rotate_left(16) ^ v[2];
        v[0] = v[0].wrapping_add(v[3]);
        v[3] = v[3].rotate_left(21) ^ v[0];
        v[2] = v[2].wrapping_add(v[1]);
        v[1] = v[1].rotate_left(17) ^ v[2];
        v[2] = v[2].rotate_left(32);
    }
    let (chunks, rest) = data.as_chunks::<8>();
    for chunk in chunks {
        let m = u64::from_le_bytes(*chunk);
        v[3] ^= m;
        round(&mut v);
        round(&mut v);
        v[0] ^= m;
    }
    let mut last = (data.len() as u64) << 56;
    for (index, byte) in rest.iter().enumerate() {
        last |= u64::from(*byte) << (8 * index);
    }
    v[3] ^= last;
    round(&mut v);
    round(&mut v);
    v[0] ^= last;
    v[2] ^= 0xff;
    for _ in 0..4 {
        round(&mut v);
    }
    v[0] ^ v[1] ^ v[2] ^ v[3]
}

/// Bob Jenkins' lookup3 `hashlittle2` with both seeds 0, combined as systemd does: `(c << 32) | b`.
pub fn jenkins_hash64(data: &[u8]) -> u64 {
    fn mix(a: &mut u32, b: &mut u32, c: &mut u32) {
        *a = a.wrapping_sub(*c);
        *a ^= c.rotate_left(4);
        *c = c.wrapping_add(*b);
        *b = b.wrapping_sub(*a);
        *b ^= a.rotate_left(6);
        *a = a.wrapping_add(*c);
        *c = c.wrapping_sub(*b);
        *c ^= b.rotate_left(8);
        *b = b.wrapping_add(*a);
        *a = a.wrapping_sub(*c);
        *a ^= c.rotate_left(16);
        *c = c.wrapping_add(*b);
        *b = b.wrapping_sub(*a);
        *b ^= a.rotate_left(19);
        *a = a.wrapping_add(*c);
        *c = c.wrapping_sub(*b);
        *c ^= b.rotate_left(4);
        *b = b.wrapping_add(*a);
    }
    fn finish(a: &mut u32, b: &mut u32, c: &mut u32) {
        *c ^= *b;
        *c = c.wrapping_sub(b.rotate_left(14));
        *a ^= *c;
        *a = a.wrapping_sub(c.rotate_left(11));
        *b ^= *a;
        *b = b.wrapping_sub(a.rotate_left(25));
        *c ^= *b;
        *c = c.wrapping_sub(b.rotate_left(16));
        *a ^= *c;
        *a = a.wrapping_sub(c.rotate_left(4));
        *b ^= *a;
        *b = b.wrapping_sub(a.rotate_left(14));
        *c ^= *b;
        *c = c.wrapping_sub(b.rotate_left(24));
    }
    let word = |bytes: &[u8]| {
        let mut padded = [0u8; 4];
        padded[..bytes.len()].copy_from_slice(bytes);
        u32::from_le_bytes(padded)
    };
    let init = 0xdead_beef_u32.wrapping_add(data.len() as u32);
    let (mut a, mut b, mut c) = (init, init, init);
    let mut rest = data;
    while rest.len() > 12 {
        a = a.wrapping_add(word(&rest[..4]));
        b = b.wrapping_add(word(&rest[4..8]));
        c = c.wrapping_add(word(&rest[8..12]));
        mix(&mut a, &mut b, &mut c);
        rest = &rest[12..];
    }
    if rest.is_empty() {
        return (u64::from(c) << 32) | u64::from(b);
    }
    let mut tail = [0u8; 12];
    tail[..rest.len()].copy_from_slice(rest);
    a = a.wrapping_add(word(&tail[..4]));
    b = b.wrapping_add(word(&tail[4..8]));
    c = c.wrapping_add(word(&tail[8..]));
    finish(&mut a, &mut b, &mut c);
    (u64::from(c) << 32) | u64::from(b)
}

fn u64_at(buf: &[u8], at: usize) -> u64 {
    u64::from_le_bytes(buf[at..at + 8].try_into().expect("8 bytes"))
}
fn u32_at(buf: &[u8], at: usize) -> u32 {
    u32::from_le_bytes(buf[at..at + 4].try_into().expect("4 bytes"))
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct Header {
    pub incompatible: u32,
    pub state: u8,
    pub file_id: [u8; 16],
    pub header_size: u64,
    pub arena_size: u64,
    pub data_hash_table_offset: u64,
    pub data_hash_table_size: u64,
    pub n_entries: u64,
    pub tail_entry_realtime: u64,
}
impl Header {
    pub fn parse(buf: &[u8]) -> R<Self> {
        if buf.len() < HEADER_MIN as usize || &buf[..8] != SIGNATURE {
            return Err(Bad("not a journal file"));
        }
        let header = Header {
            incompatible: u32_at(buf, 12),
            state: buf[16],
            file_id: buf[24..40].try_into().expect("16 bytes"),
            header_size: u64_at(buf, 88),
            arena_size: u64_at(buf, 96),
            data_hash_table_offset: u64_at(buf, 104),
            data_hash_table_size: u64_at(buf, 112),
            n_entries: u64_at(buf, 152),
            tail_entry_realtime: u64_at(buf, 192),
        };
        if header.incompatible & !INCOMPATIBLE_KNOWN != 0 {
            return Err(Bad("unknown incompatible flags"));
        }
        if header.header_size < HEADER_MIN
            || header.header_size.checked_add(header.arena_size).is_none()
        {
            return Err(Bad("header sizes"));
        }
        Ok(header)
    }
    pub fn compact(&self) -> bool {
        self.incompatible & INCOMPATIBLE_COMPACT != 0
    }
    pub fn keyed(&self) -> bool {
        self.incompatible & INCOMPATIBLE_KEYED_HASH != 0
    }
    fn arena_end(&self) -> u64 {
        self.header_size + self.arena_size
    }
}

/// The position after the last entry taken from one data object's entry list.
#[derive(Debug, Clone, Copy, Default, PartialEq, Eq)]
struct Cursor {
    /// Entries taken; the index of the next one. Entry 0 is stored in the data object itself.
    consumed: u64,
    /// The entry array that holds index `consumed`, or 0 for the start of the chain.
    array: u64,
    /// The index of the first item of `array`.
    base: u64,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum Wanted {
    Message,
    Pid,
    Identifier,
    Comm,
}
impl Wanted {
    fn of(name: &[u8]) -> Option<Self> {
        match name {
            b"MESSAGE" => Some(Self::Message),
            b"_PID" => Some(Self::Pid),
            b"SYSLOG_IDENTIFIER" => Some(Self::Identifier),
            b"_COMM" => Some(Self::Comm),
            _ => None,
        }
    }
    fn limit(self) -> usize {
        if self == Self::Message {
            MAX_MESSAGE_BYTES
        } else {
            MAX_SMALL_FIELD
        }
    }
}

type Field = (Wanted, Vec<u8>);

#[derive(Debug, Clone)]
enum CachedField {
    Unwanted,
    Small(Wanted, Vec<u8>),
}

#[derive(Debug, Default, Clone, PartialEq, Eq)]
pub struct Entry {
    pub seqnum: u64,
    pub realtime: u64,
    pub message: Option<Vec<u8>>,
    pub pid: Option<Vec<u8>>,
    pub identifier: Option<Vec<u8>>,
    pub comm: Option<Vec<u8>>,
    pub unreadable: bool,
}

pub struct JournalFile {
    file: File,
    pub header: Header,
    len: u64,
    cache: HashMap<u64, CachedField>,
}

impl JournalFile {
    /// Opens a journal file without following a link and reads its header.
    pub fn open(path: &Path) -> R<Self> {
        let fd = rfs::open(
            path,
            OFlags::RDONLY | OFlags::NOFOLLOW | OFlags::NONBLOCK | OFlags::CLOEXEC,
            Mode::empty(),
        )
        .map_err(|_| Bad("open"))?;
        let file = File::from(fd);
        let (header, len) = read_header(&file)?;
        Ok(JournalFile {
            file,
            header,
            len,
            cache: HashMap::new(),
        })
    }

    /// Reads the header and size again; both change while journald writes.
    pub fn refresh(&mut self) -> R<()> {
        (self.header, self.len) = read_header(&self.file)?;
        Ok(())
    }

    fn end(&self) -> u64 {
        self.header.arena_end().min(self.len)
    }

    fn read(&self, offset: u64, len: u64) -> R<Vec<u8>> {
        let end = offset.checked_add(len).ok_or(Bad("offset overflow"))?;
        if offset < self.header.header_size || end > self.end() {
            return Err(Bad("offset outside the arena"));
        }
        let mut buf = vec![0; len as usize];
        if read_full(&self.file, &mut buf, offset)? != buf.len() {
            return Err(Bad("short read"));
        }
        Ok(buf)
    }

    /// Returns the object's flags and size after checking its type, alignment, and bounds.
    fn object(&self, offset: u64, kind: u8, min_size: u64) -> R<(u8, u64)> {
        if offset == 0 || offset & 7 != 0 {
            return Err(Bad("unaligned object"));
        }
        let head = self.read(offset, 16)?;
        let size = u64_at(&head, 8);
        if head[0] != kind || size < min_size.max(16) {
            return Err(Bad("object type or size"));
        }
        if offset.checked_add(size).is_none_or(|end| end > self.end()) {
            return Err(Bad("object outside the arena"));
        }
        Ok((head[1], size))
    }

    fn data_payload_offset(&self) -> u64 {
        if self.header.compact() {
            72
        } else {
            64
        }
    }

    /// The payload of a data object, decompressed, cut to `limit` bytes. `Ok(None)` for xz.
    fn payload(&self, offset: u64, limit: usize) -> R<Option<Vec<u8>>> {
        let start = self.data_payload_offset();
        let (flags, size) = self.object(offset, OBJECT_DATA, start)?;
        let stored = size - start;
        if flags & OBJECT_COMPRESSED_XZ != 0 {
            return Ok(None);
        }
        if flags & (OBJECT_COMPRESSED_LZ4 | OBJECT_COMPRESSED_ZSTD) == 0 {
            return self
                .read(offset + start, stored.min(limit as u64))
                .map(Some);
        }
        if stored > MAX_COMPRESSED_READ {
            return Ok(None);
        }
        let compressed = self.read(offset + start, stored)?;
        Ok(decompress(flags, &compressed, limit))
    }

    fn hash(&self, payload: &[u8]) -> u64 {
        if self.header.keyed() {
            siphash24(payload, &self.header.file_id)
        } else {
            jenkins_hash64(payload)
        }
    }

    /// Finds the data object for `payload` through the data hash table.
    pub fn find_data(&self, payload: &[u8]) -> R<Option<u64>> {
        let buckets = self.header.data_hash_table_size / 16;
        if buckets == 0 {
            return Ok(None);
        }
        let hash = self.hash(payload);
        let item = self
            .header
            .data_hash_table_offset
            .checked_add((hash % buckets) * 16)
            .ok_or(Bad("hash table"))?;
        let mut next = u64_at(&self.read(item, 8)?, 0);
        let mut steps = 0;
        while next != 0 {
            steps += 1;
            if steps > MAX_CHAIN {
                return Err(Bad("hash chain too long"));
            }
            self.object(next, OBJECT_DATA, self.data_payload_offset())?;
            let fields = self.read(next + 16, 16)?;
            if u64_at(&fields, 0) == hash
                && self.payload(next, payload.len() + 1)?.as_deref() == Some(payload)
            {
                return Ok(Some(next));
            }
            let following = u64_at(&fields, 8);
            if following != 0 && following <= next {
                return Err(Bad("hash chain goes backwards"));
            }
            next = following;
        }
        Ok(None)
    }

    fn item_size(&self) -> u64 {
        if self.header.compact() {
            4
        } else {
            8
        }
    }

    /// Adds the entries of a data object after `cursor` to `out`, skipping indexes below `from`. Only
    /// the newest `cap` new entries are read; the count of older ones skipped is returned. The cursor
    /// moves only when the whole walk succeeds.
    fn walk(
        &self,
        data: u64,
        cursor: &mut Cursor,
        from: u64,
        cap: u64,
        out: &mut Vec<u64>,
    ) -> R<u64> {
        self.object(data, OBJECT_DATA, self.data_payload_offset())?;
        let fields = self.read(data + 40, 24)?;
        let (inline, chain, n) = (u64_at(&fields, 0), u64_at(&fields, 8), u64_at(&fields, 16));
        let mut c = *cursor;
        if n <= c.consumed {
            return Ok(0);
        }
        let first_wanted = from.max(n.saturating_sub(cap));
        let dropped = first_wanted.saturating_sub(c.consumed.max(from));
        let mut found = Vec::new();
        if c.consumed == 0 {
            if inline == 0 {
                return Err(Bad("data object without its first entry"));
            }
            if first_wanted == 0 {
                found.push(inline);
            }
            c = Cursor {
                consumed: 1,
                array: 0,
                base: 1,
            };
        }
        let item = self.item_size();
        let mut steps = 0;
        while c.consumed < n {
            steps += 1;
            if steps > MAX_CHAIN {
                return Err(Bad("entry array chain too long"));
            }
            if c.array == 0 {
                if chain == 0 {
                    break;
                }
                c.array = chain;
                c.base = 1;
            }
            let (_, size) = self.object(c.array, OBJECT_ENTRY_ARRAY, 24 + item)?;
            let count = (size - 24) / item;
            let end = c.base.saturating_add(count);
            if c.consumed >= end {
                let next = u64_at(&self.read(c.array + 16, 8)?, 0);
                if next == 0 {
                    break;
                }
                if next <= c.array {
                    return Err(Bad("entry array chain goes backwards"));
                }
                c.array = next;
                c.base = end;
                continue;
            }
            let high = end.min(n);
            let low = c.consumed.max(first_wanted).min(high);
            if low < high {
                let bytes = self.read(c.array + 24 + (low - c.base) * item, (high - low) * item)?;
                for (index, raw) in bytes.chunks_exact(item as usize).enumerate() {
                    let offset = if item == 4 {
                        u64::from(u32_at(raw, 0))
                    } else {
                        u64_at(raw, 0)
                    };
                    if offset == 0 {
                        // Not written yet: stop here and continue at the next poll.
                        c.consumed = low + index as u64;
                        out.extend(found);
                        *cursor = c;
                        return Ok(dropped);
                    }
                    found.push(offset);
                }
            }
            c.consumed = high;
        }
        out.extend(found);
        *cursor = c;
        Ok(dropped)
    }

    /// The field when it is one the reader wants, and whether it could not be read.
    fn field(&mut self, offset: u64) -> R<(Option<Field>, bool)> {
        if let Some(cached) = self.cache.get(&offset) {
            return Ok(match cached {
                CachedField::Unwanted => (None, false),
                CachedField::Small(kind, value) => (Some((*kind, value.clone())), false),
            });
        }
        let Some(head) = self.payload(offset, 32)? else {
            return Ok((None, true));
        };
        let Some(equals) = head.iter().position(|b| *b == b'=') else {
            self.remember(offset, CachedField::Unwanted);
            return Ok((None, false));
        };
        let Some(kind) = Wanted::of(&head[..equals]) else {
            self.remember(offset, CachedField::Unwanted);
            return Ok((None, false));
        };
        let Some(full) = self.payload(offset, equals + 1 + kind.limit())? else {
            return Ok((None, true));
        };
        let value = full[equals + 1..].to_vec();
        if kind != Wanted::Message {
            self.remember(offset, CachedField::Small(kind, value.clone()));
        }
        Ok((Some((kind, value)), false))
    }

    fn remember(&mut self, offset: u64, field: CachedField) {
        if self.cache.len() >= 8_192 {
            self.cache.clear();
        }
        self.cache.insert(offset, field);
    }

    pub fn entry(&mut self, offset: u64) -> R<Entry> {
        let item = if self.header.compact() { 4 } else { 16 };
        let (_, size) = self.object(offset, OBJECT_ENTRY, 64)?;
        let items = ((size - 64) / item).min(MAX_ENTRY_ITEMS);
        let body = self.read(offset, 64 + items * item)?;
        let mut entry = Entry {
            seqnum: u64_at(&body, 16),
            realtime: u64_at(&body, 24),
            ..Entry::default()
        };
        for raw in body[64..].chunks_exact(item as usize) {
            let data = if item == 4 {
                u64::from(u32_at(raw, 0))
            } else {
                u64_at(raw, 0)
            };
            let (field, unreadable) = self.field(data)?;
            entry.unreadable |= unreadable;
            if let Some((kind, value)) = field {
                let slot = match kind {
                    Wanted::Message => &mut entry.message,
                    Wanted::Pid => &mut entry.pid,
                    Wanted::Identifier => &mut entry.identifier,
                    Wanted::Comm => &mut entry.comm,
                };
                slot.get_or_insert(value);
            }
        }
        Ok(entry)
    }
}

fn read_header(file: &File) -> R<(Header, u64)> {
    let metadata = file.metadata().map_err(|_| Bad("fstat"))?;
    if !metadata.file_type().is_file() {
        return Err(Bad("not a regular file"));
    }
    let mut buf = [0u8; HEADER_READ];
    let n = read_full(file, &mut buf, 0)?;
    Ok((Header::parse(&buf[..n])?, metadata.len()))
}

fn read_full(file: &File, buf: &mut [u8], offset: u64) -> R<usize> {
    let mut filled = 0;
    while filled < buf.len() {
        match file.read_at(&mut buf[filled..], offset + filled as u64) {
            Ok(0) => break,
            Ok(n) => filled += n,
            Err(error) if error.kind() == std::io::ErrorKind::Interrupted => {}
            Err(_) => return Err(Bad("read")),
        }
    }
    Ok(filled)
}

/// Decompresses an lz4 (8-byte little-endian size, then one block) or zstd payload, cut to `limit`.
fn decompress(flags: u8, data: &[u8], limit: usize) -> Option<Vec<u8>> {
    if flags & OBJECT_COMPRESSED_LZ4 != 0 {
        let size = usize::try_from(u64::from_le_bytes(data.get(..8)?.try_into().ok()?)).ok()?;
        if size > MAX_DECOMPRESSED {
            return None;
        }
        let mut out = lz4_flex::block::decompress(&data[8..], size).ok()?;
        out.truncate(limit);
        return Some(out);
    }
    let decoder = ruzstd::decoding::StreamingDecoder::new(data).ok()?;
    let mut out = Vec::new();
    decoder.take(limit as u64).read_to_end(&mut out).ok()?;
    Some(out)
}

/// `2026-09-25T10:15:02+00:00` from microseconds since the epoch.
pub fn format_realtime(realtime: u64) -> String {
    let seconds = i64::try_from(realtime / 1_000_000).unwrap_or(0);
    let format =
        time::macros::format_description!("[year]-[month]-[day]T[hour]:[minute]:[second]+00:00");
    time::OffsetDateTime::from_unix_timestamp(seconds)
        .ok()
        .and_then(|at| at.format(&format).ok())
        .unwrap_or_else(|| "1970-01-01T00:00:00+00:00".into())
}

/// `time identifier[pid]: message`, one line for each line of the message. Entries without a message
/// produce no line, unless a field could not be read.
pub fn format_entry(entry: &Entry, unit: &str) -> Vec<String> {
    let message = match (&entry.message, entry.unreadable) {
        (Some(message), _) => String::from_utf8_lossy(message).into_owned(),
        (None, true) => UNREADABLE.to_owned(),
        (None, false) => return Vec::new(),
    };
    let identifier = entry
        .identifier
        .as_ref()
        .or(entry.comm.as_ref())
        .map(|value| String::from_utf8_lossy(value).into_owned())
        .unwrap_or_else(|| unit.to_owned());
    let pid = entry
        .pid
        .as_ref()
        .map(|pid| format!("[{}]", String::from_utf8_lossy(pid)))
        .unwrap_or_default();
    let message = message.strip_suffix('\n').unwrap_or(&message);
    let mut lines = message.split('\n');
    let first = format!(
        "{} {identifier}{pid}: {}",
        format_realtime(entry.realtime),
        lines.next().unwrap_or_default()
    );
    std::iter::once(first)
        .chain(lines.map(str::to_owned))
        .collect()
}

#[derive(Debug, Default, Clone, Copy)]
struct Track {
    data: Option<u64>,
    cursor: Cursor,
}

struct Tracked {
    file: JournalFile,
    /// `_SYSTEMD_UNIT=<unit>`
    unit: Track,
    /// `UNIT=<unit>`, kept only when `_PID` is 1.
    manager: Track,
    /// Polls in a row that found an entry it could not read. After a few, the entry is skipped.
    failures: u32,
}
impl Tracked {
    fn new(file: JournalFile) -> Self {
        Self {
            file,
            unit: Track::default(),
            manager: Track::default(),
            failures: 0,
        }
    }
}
const MAX_FAILURES: u32 = 4;

struct Record {
    realtime: u64,
    seqnum: u64,
    lines: Vec<String>,
}

#[derive(Debug, Default, PartialEq, Eq)]
pub struct Poll {
    pub lines: Vec<String>,
    pub dropped: u64,
}

/// Follows one unit across the journal files of a machine.
pub struct JournalTail {
    dirs: Vec<PathBuf>,
    unit: String,
    unit_field: Vec<u8>,
    manager_field: Vec<u8>,
    files: BTreeMap<[u8; 16], Tracked>,
    done: HashSet<[u8; 16]>,
    inodes: HashMap<(u64, u64), [u8; 16]>,
}

/// `/var/log/journal/<machine-id>` and `/run/log/journal/<machine-id>`.
pub fn system_dirs() -> Result<Vec<PathBuf>, Unavailable> {
    let mut id = String::new();
    File::open("/etc/machine-id")
        .and_then(|file| file.take(64).read_to_string(&mut id))
        .map_err(|_| Unavailable("machine-id"))?;
    let id = id.trim();
    if id.len() != 32 || !id.bytes().all(|b| b.is_ascii_hexdigit()) {
        return Err(Unavailable("machine-id"));
    }
    Ok(vec![
        Path::new("/var/log/journal").join(id),
        Path::new("/run/log/journal").join(id),
    ])
}

impl JournalTail {
    pub fn new(dirs: Vec<PathBuf>, unit: &str) -> Self {
        Self {
            dirs,
            unit: unit.into(),
            unit_field: format!("_SYSTEMD_UNIT={unit}").into_bytes(),
            manager_field: format!("UNIT={unit}").into_bytes(),
            files: BTreeMap::new(),
            done: HashSet::new(),
            inodes: HashMap::new(),
        }
    }

    /// Regular `*.journal` files directly in the directories whose file ID is not known yet.
    fn scan(&mut self) -> Vec<JournalFile> {
        let mut seen = HashSet::new();
        let mut found = Vec::new();
        for dir in &self.dirs {
            let Ok(entries) = std::fs::read_dir(dir) else {
                continue;
            };
            for entry in entries.flatten() {
                let name = entry.file_name();
                if !name.as_encoded_bytes().ends_with(b".journal") {
                    continue;
                }
                let Ok(metadata) = std::fs::symlink_metadata(entry.path()) else {
                    continue;
                };
                if !metadata.file_type().is_file() {
                    continue;
                }
                let inode = (metadata.dev(), metadata.ino());
                seen.insert(inode);
                if self.inodes.contains_key(&inode) {
                    continue;
                }
                let Ok(file) = JournalFile::open(&entry.path()) else {
                    continue;
                };
                let id = file.header.file_id;
                self.inodes.insert(inode, id);
                if self.done.contains(&id)
                    || self.files.contains_key(&id)
                    || found.iter().any(|f: &JournalFile| f.header.file_id == id)
                {
                    continue;
                }
                found.push(file);
            }
        }
        self.inodes.retain(|inode, _| seen.contains(inode));
        let present: HashSet<_> = self.inodes.values().copied().collect();
        self.done.retain(|id| present.contains(id));
        found
    }

    /// Opens the journal and returns the last `lines` lines of the unit.
    pub fn start(&mut self, lines: usize) -> Vec<String> {
        let mut files = self.scan();
        files.sort_by_key(|f| std::cmp::Reverse(f.header.tail_entry_realtime));
        let mut records: Vec<Record> = Vec::new();
        for file in files {
            let mut tracked = Tracked::new(file);
            let older = records.len() >= lines
                && records
                    .iter()
                    .all(|r| r.realtime > tracked.file.header.tail_entry_realtime);
            let want = if older { 0 } else { lines as u64 };
            if let Ok((mut found, _)) = self.collect(&mut tracked, want, u64::MAX, usize::MAX) {
                records.append(&mut found);
                records.sort_by_key(|r| (r.realtime, r.seqnum));
                let excess = records.len().saturating_sub(lines);
                records.drain(..excess);
            }
            self.keep(tracked);
        }
        let all: Vec<String> = records.into_iter().flat_map(|r| r.lines).collect();
        let start = all.len().saturating_sub(lines);
        all[start..].to_vec()
    }

    /// Returns lines of entries added since the last poll. `rescan` also picks up new files.
    pub fn poll(&mut self, rescan: bool) -> Poll {
        let mut poll = Poll::default();
        if rescan {
            for file in self.scan() {
                self.files.insert(file.header.file_id, Tracked::new(file));
            }
        }
        let mut ids: Vec<_> = self.files.keys().copied().collect();
        ids.sort_by_key(|id| std::cmp::Reverse(self.files[id].file.header.tail_entry_realtime));
        let mut records = Vec::new();
        let mut budget = MAX_POLL_BYTES;
        for id in ids {
            let Some(mut tracked) = self.files.remove(&id) else {
                continue;
            };
            if tracked.file.refresh().is_ok() {
                if let Ok((mut found, dropped)) =
                    self.collect(&mut tracked, u64::MAX, MAX_NEW_ENTRIES, budget)
                {
                    poll.dropped += dropped;
                    let used: usize = found.iter().flat_map(|r| &r.lines).map(String::len).sum();
                    budget = budget.saturating_sub(used);
                    records.append(&mut found);
                }
            }
            self.keep(tracked);
        }
        records.sort_by_key(|r| (r.realtime, r.seqnum));
        poll.lines = records.into_iter().flat_map(|r| r.lines).collect();
        poll
    }

    /// Archived files never change again, so they are read once and closed.
    fn keep(&mut self, tracked: Tracked) {
        let id = tracked.file.header.file_id;
        if tracked.file.header.state == STATE_ARCHIVED {
            self.done.insert(id);
        } else {
            self.files.insert(id, tracked);
        }
    }

    /// Reads the unit's new entries of one file, newest first within `budget` bytes. `last` limits
    /// the walk to the newest `last` entries of each list (for the first lines).
    fn collect(
        &self,
        tracked: &mut Tracked,
        last: u64,
        cap: u64,
        budget: usize,
    ) -> R<(Vec<Record>, u64)> {
        let file = &mut tracked.file;
        let mut offsets: BTreeMap<u64, bool> = BTreeMap::new();
        let mut dropped = 0;
        let mut unit = tracked.unit;
        let mut manager = tracked.manager;
        for (track, field, from_unit) in [
            (&mut unit, &self.unit_field, true),
            (&mut manager, &self.manager_field, false),
        ] {
            if track.data.is_none() {
                track.data = file.find_data(field)?;
            }
            let Some(data) = track.data else {
                continue;
            };
            let mut found = Vec::new();
            let from = if last == u64::MAX {
                0
            } else {
                let fields = file.read(data + 56, 8)?;
                let wanted = if from_unit {
                    last
                } else {
                    last.saturating_mul(2)
                };
                u64_at(&fields, 0).saturating_sub(wanted)
            };
            dropped += file.walk(data, &mut track.cursor, from, cap, &mut found)?;
            for offset in found {
                *offsets.entry(offset).or_insert(false) |= from_unit;
            }
        }
        let mut records = Vec::new();
        let mut used = 0;
        let mut kept = 0;
        for (&offset, &from_unit) in offsets.iter().rev() {
            if used >= budget {
                dropped += 1;
                continue;
            }
            let entry = match file.entry(offset) {
                Ok(entry) => entry,
                // Maybe still being written: try the whole file again at the next poll.
                Err(error) if tracked.failures < MAX_FAILURES => {
                    tracked.failures += 1;
                    return Err(error);
                }
                Err(_) => {
                    dropped += 1;
                    continue;
                }
            };
            if !from_unit && entry.pid.as_deref() != Some(b"1") {
                continue;
            }
            let lines = format_entry(&entry, &self.unit);
            if lines.is_empty() {
                continue;
            }
            used += lines.iter().map(String::len).sum::<usize>();
            kept += 1;
            records.push(Record {
                realtime: entry.realtime,
                seqnum: entry.seqnum,
                lines,
            });
            if last != u64::MAX && kept >= last {
                break;
            }
        }
        tracked.unit = unit;
        tracked.manager = manager;
        tracked.failures = 0;
        Ok((records, dropped))
    }
}

/// Runs a `journal` source until it cannot be read.
pub async fn run(unit: String, sink: &mut super::limits::LineSink) -> Result<(), Unavailable> {
    let dirs = system_dirs()?;
    run_in(dirs, unit, sink).await
}

pub async fn run_in(
    dirs: Vec<PathBuf>,
    unit: String,
    sink: &mut super::limits::LineSink,
) -> Result<(), Unavailable> {
    let lines = sink.lines();
    let mut tail = JournalTail::new(dirs, &unit);
    let (returned, first) = super::laravel::blocking(move || {
        let first = tail.start(lines);
        (tail, first)
    })
    .await?;
    tail = returned;
    sink.first(first);
    let mut tick = tokio::time::interval(POLL_INTERVAL);
    tick.set_missed_tick_behavior(tokio::time::MissedTickBehavior::Delay);
    tick.tick().await;
    let mut count = 0u32;
    loop {
        tick.tick().await;
        count = count.wrapping_add(1);
        let rescan = count.checked_rem(RESCAN_EVERY) == Some(0);
        let (returned, poll) = super::laravel::blocking(move || {
            let poll = tail.poll(rescan);
            (tail, poll)
        })
        .await?;
        tail = returned;
        sink.dropped(poll.dropped);
        for line in poll.lines {
            sink.line(&line);
        }
    }
}

#[cfg(test)]
mod tests {
    use super::super::journal_writer::{Compression, Options, Writer};
    use super::*;

    #[test]
    fn siphash_reference_vectors() {
        let key: [u8; 16] = std::array::from_fn(|i| i as u8);
        assert_eq!(siphash24(b"", &key), 0x726f_db47_dd0e_0e31);
        let message: Vec<u8> = (0..15).collect();
        assert_eq!(siphash24(&message, &key), 0xa129_ca61_49be_45e5);
        #[allow(deprecated)]
        for len in 0..64 {
            use std::hash::Hasher;
            let data: Vec<u8> = (0..len).map(|i| (i * 7 + 3) as u8).collect();
            let key: [u8; 16] = std::array::from_fn(|i| (i * 13) as u8);
            let mut std_hasher = std::hash::SipHasher::new_with_keys(
                u64::from_le_bytes(key[..8].try_into().unwrap()),
                u64::from_le_bytes(key[8..].try_into().unwrap()),
            );
            std_hasher.write(&data);
            assert_eq!(siphash24(&data, &key), std_hasher.finish(), "len {len}");
        }
    }

    #[test]
    fn jenkins_lookup3_reference_vectors() {
        assert_eq!(jenkins_hash64(b""), 0xdead_beef_dead_beef);
        let hash = jenkins_hash64(b"Four score and seven years ago");
        assert_eq!(hash >> 32, 0x1777_0551);
        assert_eq!(hash & 0xffff_ffff, 0xce72_26e6);
    }

    #[test]
    fn realtime_formats_as_rfc3339_utc_seconds() {
        assert_eq!(
            format_realtime(1_790_331_302_123_456),
            "2026-09-25T10:15:02+00:00"
        );
    }

    #[test]
    fn entry_lines_use_identifier_pid_and_split_messages() {
        let entry = Entry {
            realtime: 1_790_331_302_000_000,
            message: Some(b"first\nsecond\n".to_vec()),
            pid: Some(b"42".to_vec()),
            comm: Some(b"php".to_vec()),
            ..Entry::default()
        };
        assert_eq!(
            format_entry(&entry, "u.service"),
            ["2026-09-25T10:15:02+00:00 php[42]: first", "second"]
        );
        let bare = Entry {
            realtime: 1_790_331_302_000_000,
            message: Some(b"\xffok".to_vec()),
            ..Entry::default()
        };
        assert_eq!(
            format_entry(&bare, "u.service"),
            ["2026-09-25T10:15:02+00:00 u.service: \u{fffd}ok"]
        );
        let unreadable = Entry {
            unreadable: true,
            identifier: Some(b"app".to_vec()),
            ..Entry::default()
        };
        assert_eq!(
            format_entry(&unreadable, "u.service"),
            ["1970-01-01T00:00:00+00:00 app: [orbit] entry not readable"]
        );
        assert!(format_entry(&Entry::default(), "u").is_empty());
    }

    struct TempDir(PathBuf);
    impl TempDir {
        fn new() -> Self {
            let path = std::env::temp_dir().join(format!(
                "orbit-agent-journal-{}-{}",
                std::process::id(),
                rand::random::<u64>()
            ));
            std::fs::create_dir_all(&path).unwrap();
            Self(path)
        }
    }
    impl Drop for TempDir {
        fn drop(&mut self) {
            let _ = std::fs::remove_dir_all(&self.0);
        }
    }

    const UNIT: &str = "orbit-process-41-queue.service";
    const T0: u64 = 1_790_331_302_000_000;

    fn service_entry(w: &mut Writer, n: u64, message: &str) {
        w.append(
            T0 + n * 1_000_000,
            &[
                ("_SYSTEMD_UNIT", UNIT.as_bytes()),
                ("_PID", b"4242"),
                ("_COMM", b"php8.4"),
                ("SYSLOG_IDENTIFIER", b"queue"),
                ("MESSAGE", message.as_bytes()),
                ("PRIORITY", b"6"),
            ],
        );
    }
    fn other_entry(w: &mut Writer, n: u64) {
        w.append(
            T0 + n * 1_000_000,
            &[
                ("_SYSTEMD_UNIT", b"ssh.service"),
                ("_PID", b"99"),
                ("MESSAGE", format!("ssh {n}").as_bytes()),
            ],
        );
    }
    fn manager_entry(w: &mut Writer, n: u64, pid: &[u8], message: &str) {
        w.append(
            T0 + n * 1_000_000,
            &[
                ("UNIT", UNIT.as_bytes()),
                ("_PID", pid),
                ("_COMM", b"systemd"),
                ("SYSLOG_IDENTIFIER", b"systemd"),
                ("MESSAGE", message.as_bytes()),
            ],
        );
    }
    fn stamp(n: u64) -> String {
        format_realtime(T0 + n * 1_000_000)
    }

    fn every_variant() -> Vec<Options> {
        let mut all = Vec::new();
        for compact in [false, true] {
            for keyed in [false, true] {
                for compression in [Compression::None, Compression::Zstd, Compression::Lz4] {
                    all.push(Options {
                        compact,
                        keyed,
                        compression,
                        buckets: 7,
                        first_array: 2,
                    });
                }
            }
        }
        all
    }

    #[test]
    fn reads_the_last_entries_of_the_unit_in_every_format() {
        for options in every_variant() {
            let dir = TempDir::new();
            let path = dir.0.join("system.journal");
            let mut w = Writer::new(options);
            let long = format!("long {}", "m".repeat(700));
            for n in 0..40 {
                if n % 3 == 0 {
                    other_entry(&mut w, n);
                }
                let message = if n == 38 {
                    long.clone()
                } else {
                    format!("line {n}")
                };
                service_entry(&mut w, n, &message);
            }
            manager_entry(&mut w, 40, b"1", "Started queue.");
            manager_entry(&mut w, 41, b"777", "not from pid 1");
            w.write(&path);
            let mut tail = JournalTail::new(vec![dir.0.clone()], UNIT);
            let lines = tail.start(4);
            assert_eq!(
                lines,
                [
                    format!("{} queue[4242]: line 37", stamp(37)),
                    format!("{} queue[4242]: {long}", stamp(38)),
                    format!("{} queue[4242]: line 39", stamp(39)),
                    format!("{} systemd[1]: Started queue.", stamp(40)),
                ],
                "{options:?}"
            );
            assert_eq!(tail.poll(false), Poll::default());
            service_entry(&mut w, 42, "after start");
            other_entry(&mut w, 43);
            manager_entry(&mut w, 44, b"1", "Stopping queue...");
            w.write(&path);
            assert_eq!(
                tail.poll(false).lines,
                [
                    format!("{} queue[4242]: after start", stamp(42)),
                    format!("{} systemd[1]: Stopping queue...", stamp(44)),
                ],
                "{options:?}"
            );
            assert_eq!(tail.poll(true), Poll::default());
        }
    }

    #[test]
    fn follows_entries_across_many_entry_arrays() {
        let dir = TempDir::new();
        let path = dir.0.join("system.journal");
        let mut w = Writer::new(Options {
            compact: true,
            keyed: true,
            compression: Compression::None,
            buckets: 3,
            first_array: 1,
        });
        service_entry(&mut w, 0, "zero");
        w.write(&path);
        let mut tail = JournalTail::new(vec![dir.0.clone()], UNIT);
        assert_eq!(tail.start(1000).len(), 1);
        let mut expected = Vec::new();
        for round in 0..5u64 {
            for i in 0..(round * 7 + 1) {
                let n = 1 + round * 100 + i;
                service_entry(&mut w, n, &format!("m{n}"));
                expected.push(format!("{} queue[4242]: m{n}", stamp(n)));
            }
            w.write(&path);
            let got = tail.poll(false).lines;
            assert_eq!(got, expected);
            expected.clear();
        }
        assert!(w.arrays_for(&format!("_SYSTEMD_UNIT={UNIT}")) >= 5);
        let mut fresh = JournalTail::new(vec![dir.0.clone()], UNIT);
        let all = fresh.start(1000);
        assert_eq!(all.len(), 1 + 1 + 8 + 15 + 22 + 29);
        let last = fresh.start(3);
        assert!(last.is_empty(), "a started tail does not read files twice");
    }

    #[test]
    fn first_lines_skip_older_arrays_without_reading_their_items() {
        let dir = TempDir::new();
        let mut w = Writer::new(Options {
            compact: false,
            keyed: false,
            compression: Compression::None,
            buckets: 64,
            first_array: 4,
        });
        for n in 0..500 {
            service_entry(&mut w, n, &format!("n{n}"));
        }
        w.write(&dir.0.join("system.journal"));
        let mut tail = JournalTail::new(vec![dir.0.clone()], UNIT);
        let lines = tail.start(3);
        assert_eq!(lines.len(), 3);
        assert!(lines[2].ends_with("n499"));
        assert!(lines[0].ends_with("n497"));
    }

    #[test]
    fn merges_files_by_time_and_follows_rotation_without_duplicates() {
        let dir = TempDir::new();
        let options = Options {
            compact: true,
            keyed: true,
            compression: Compression::Zstd,
            buckets: 11,
            first_array: 2,
        };
        let mut system = Writer::new(options);
        let mut user = Writer::new(options);
        service_entry(&mut system, 1, "system one");
        service_entry(&mut user, 2, "user two");
        service_entry(&mut system, 3, "system three");
        system.write(&dir.0.join("system.journal"));
        user.write(&dir.0.join("user-1000.journal"));
        let mut tail = JournalTail::new(vec![dir.0.clone()], UNIT);
        let first = tail.start(10);
        assert_eq!(
            first,
            [
                format!("{} queue[4242]: system one", stamp(1)),
                format!("{} queue[4242]: user two", stamp(2)),
                format!("{} queue[4242]: system three", stamp(3)),
            ]
        );
        // journald archives and renames the file, then starts a new one.
        service_entry(&mut system, 4, "last before rotation");
        system.set_archived();
        system.write(&dir.0.join("system.journal"));
        std::fs::rename(
            dir.0.join("system.journal"),
            dir.0.join("system@0000-0001.journal"),
        )
        .unwrap();
        let mut next = Writer::new(options);
        service_entry(&mut next, 5, "first after rotation");
        next.write(&dir.0.join("system.journal"));
        let got = tail.poll(true).lines;
        assert_eq!(
            got,
            [
                format!("{} queue[4242]: last before rotation", stamp(4)),
                format!("{} queue[4242]: first after rotation", stamp(5)),
            ]
        );
        assert!(tail.poll(true).lines.is_empty());
        service_entry(&mut next, 6, "six");
        next.write(&dir.0.join("system.journal"));
        assert_eq!(tail.poll(false).lines.len(), 1);
    }

    #[test]
    fn links_and_other_names_are_not_read() {
        let dir = TempDir::new();
        let other = TempDir::new();
        let mut w = Writer::new(Options::default());
        service_entry(&mut w, 1, "hidden");
        w.write(&other.0.join("system.journal"));
        std::os::unix::fs::symlink(other.0.join("system.journal"), dir.0.join("link.journal"))
            .unwrap();
        w.write(&dir.0.join("system.journal~"));
        std::fs::create_dir(dir.0.join("sub")).unwrap();
        w.write(&dir.0.join("sub/system.journal"));
        let mut tail = JournalTail::new(vec![dir.0.clone()], UNIT);
        assert!(tail.start(10).is_empty());
    }

    #[test]
    fn xz_messages_are_not_readable_and_unknown_flags_skip_the_file() {
        let dir = TempDir::new();
        let mut w = Writer::new(Options {
            compression: Compression::FakeXz,
            ..Options::default()
        });
        service_entry(&mut w, 1, &"x".repeat(600));
        w.write(&dir.0.join("system.journal"));
        let mut tail = JournalTail::new(vec![dir.0.clone()], UNIT);
        assert_eq!(
            tail.start(10),
            [format!("{} queue[4242]: {UNREADABLE}", stamp(1))]
        );
        let dir = TempDir::new();
        let mut w = Writer::new(Options::default());
        service_entry(&mut w, 1, "future");
        w.set_incompatible_bit(1 << 7);
        w.write(&dir.0.join("system.journal"));
        assert!(JournalTail::new(vec![dir.0.clone()], UNIT)
            .start(10)
            .is_empty());
    }

    #[test]
    fn torn_and_corrupt_files_never_panic_and_recover() {
        for options in every_variant() {
            let dir = TempDir::new();
            let path = dir.0.join("system.journal");
            let mut w = Writer::new(options);
            for n in 0..30 {
                service_entry(
                    &mut w,
                    n,
                    &format!("entry {n} {}", "p".repeat(n as usize * 40)),
                );
            }
            let full = w.bytes().to_vec();
            // Every prefix of the file, as a reader could see it while journald writes.
            for cut in (0..full.len()).step_by(97).chain([full.len() - 1]) {
                std::fs::write(&path, &full[..cut]).unwrap();
                let mut tail = JournalTail::new(vec![dir.0.clone()], UNIT);
                let _ = tail.start(5);
                let _ = tail.poll(true);
            }
            // Random byte damage.
            for seed in 0..40u64 {
                let mut damaged = full.clone();
                for k in 0..8 {
                    let at = ((seed * 7919 + k * 104_729) as usize) % damaged.len();
                    damaged[at] ^= (seed as u8).wrapping_mul(31).wrapping_add(k as u8) | 1;
                }
                std::fs::write(&path, &damaged).unwrap();
                let mut tail = JournalTail::new(vec![dir.0.clone()], UNIT);
                let _ = tail.start(5);
                let _ = tail.poll(true);
            }
            // A tail that saw a torn file picks up the entries once they are complete.
            let mut half = Writer::new(options);
            for n in 0..5 {
                service_entry(&mut half, n, &format!("h{n}"));
            }
            half.write(&path);
            let mut tail = JournalTail::new(vec![dir.0.clone()], UNIT);
            assert_eq!(tail.start(100).len(), 5);
            let before = half.bytes().len();
            service_entry(&mut half, 5, "h5");
            let bytes = half.bytes().to_vec();
            // The new objects exist, but the header does not count them yet.
            let mut torn = bytes.clone();
            torn[96..104].copy_from_slice(&((before - 272) as u64).to_le_bytes());
            std::fs::write(&path, &torn).unwrap();
            let _ = tail.poll(false);
            std::fs::write(&path, &bytes).unwrap();
            let got = tail.poll(false).lines;
            assert_eq!(
                got,
                [format!("{} queue[4242]: h5", stamp(5))],
                "{options:?}"
            );
        }
    }

    #[test]
    fn a_flood_is_capped_per_poll_and_counted() {
        let dir = TempDir::new();
        let path = dir.0.join("system.journal");
        let mut w = Writer::new(Options {
            buckets: 101,
            first_array: 64,
            ..Options::default()
        });
        service_entry(&mut w, 0, "start");
        w.write(&path);
        let mut tail = JournalTail::new(vec![dir.0.clone()], UNIT);
        tail.start(1);
        for n in 1..=12_000 {
            service_entry(&mut w, n, &format!("flood {n} {}", "f".repeat(60)));
        }
        w.write(&path);
        let poll = tail.poll(false);
        assert_eq!(poll.dropped, 2_000 + (10_000 - poll.lines.len() as u64));
        let bytes: usize = poll.lines.iter().map(String::len).sum();
        assert!(bytes <= MAX_POLL_BYTES + 200);
        assert!(poll.lines.last().unwrap().contains("flood 12000 "));
        assert!(tail.poll(false).lines.is_empty());
    }

    /// Reads a real unit from this machine's journal. Run on a Linux host with
    /// `ORBIT_JOURNAL_UNIT=ssh.service cargo test --release real_journal -- --ignored --nocapture`.
    #[test]
    #[ignore]
    fn real_journal() {
        let unit = std::env::var("ORBIT_JOURNAL_UNIT").unwrap_or_else(|_| "ssh.service".into());
        let lines: usize = std::env::var("ORBIT_JOURNAL_LINES")
            .ok()
            .and_then(|n| n.parse().ok())
            .unwrap_or(20);
        let dirs = system_dirs().unwrap();
        let started = std::time::Instant::now();
        let mut tail = JournalTail::new(dirs, &unit);
        for line in tail.start(lines) {
            println!("{line}");
        }
        eprintln!("first lines in {:?}", started.elapsed());
        let started = std::time::Instant::now();
        let poll = tail.poll(true);
        eprintln!(
            "poll in {:?}: {} lines, {} dropped",
            started.elapsed(),
            poll.lines.len(),
            poll.dropped
        );
        // Optionally follow for a while, printing new lines after a `+ ` marker.
        let seconds: u64 = std::env::var("ORBIT_JOURNAL_FOLLOW_SECONDS")
            .ok()
            .and_then(|n| n.parse().ok())
            .unwrap_or(0);
        for round in 0..seconds * 2 {
            std::thread::sleep(POLL_INTERVAL);
            let poll = tail.poll(round % u64::from(RESCAN_EVERY) == 0);
            for line in poll.lines {
                println!("+ {line}");
            }
            if poll.dropped > 0 {
                eprintln!("dropped {}", poll.dropped);
            }
        }
    }
}
