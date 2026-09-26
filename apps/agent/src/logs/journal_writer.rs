//! A small journal file writer for tests. It lays out objects the way journald appends them: a data hash
//! table with chains, data objects shared between entries, and entry array chains that double in size.
use super::journal::{
    jenkins_hash64, siphash24, INCOMPATIBLE_COMPACT, INCOMPATIBLE_COMPRESSED_LZ4,
    INCOMPATIBLE_COMPRESSED_XZ, INCOMPATIBLE_COMPRESSED_ZSTD, INCOMPATIBLE_KEYED_HASH,
    OBJECT_COMPRESSED_LZ4, OBJECT_COMPRESSED_XZ, OBJECT_COMPRESSED_ZSTD, OBJECT_DATA, OBJECT_ENTRY,
    OBJECT_ENTRY_ARRAY, SIGNATURE, STATE_ARCHIVED,
};
use std::{collections::HashMap, os::unix::fs::FileExt, path::Path};

const HEADER_SIZE: u64 = 272;
const OBJECT_DATA_HASH_TABLE: u8 = 4;
const OBJECT_FIELD_HASH_TABLE: u8 = 5;
/// journald's default threshold for compressing a field.
const COMPRESS_THRESHOLD: usize = 512;

#[derive(Debug, Clone, Copy, PartialEq, Eq, Default)]
pub enum Compression {
    #[default]
    None,
    Zstd,
    Lz4,
    /// Marks large fields as xz without compressing them; the reader cannot read xz anyway.
    FakeXz,
}

#[derive(Debug, Clone, Copy)]
pub struct Options {
    pub compact: bool,
    pub keyed: bool,
    pub compression: Compression,
    pub buckets: u64,
    pub first_array: u64,
}
impl Default for Options {
    fn default() -> Self {
        Self {
            compact: true,
            keyed: true,
            compression: Compression::None,
            buckets: 13,
            first_array: 4,
        }
    }
}

pub struct Writer {
    buf: Vec<u8>,
    options: Options,
    file_id: [u8; 16],
    table: u64,
    data: HashMap<Vec<u8>, u64>,
    arrays: HashMap<u64, Vec<(u64, u64)>>,
    seqnum: u64,
}

impl Writer {
    pub fn new(options: Options) -> Self {
        let mut w = Writer {
            buf: vec![0; HEADER_SIZE as usize],
            options,
            file_id: rand::random(),
            table: 0,
            data: HashMap::new(),
            arrays: HashMap::new(),
            seqnum: 0,
        };
        w.buf[..8].copy_from_slice(SIGNATURE);
        let mut incompatible = 0;
        if options.compact {
            incompatible |= INCOMPATIBLE_COMPACT;
        }
        if options.keyed {
            incompatible |= INCOMPATIBLE_KEYED_HASH;
        }
        incompatible |= match options.compression {
            Compression::None => 0,
            Compression::Zstd => INCOMPATIBLE_COMPRESSED_ZSTD,
            Compression::Lz4 => INCOMPATIBLE_COMPRESSED_LZ4,
            Compression::FakeXz => INCOMPATIBLE_COMPRESSED_XZ,
        };
        w.put32(12, incompatible);
        w.buf[16] = 1;
        let id = w.file_id;
        w.buf[24..40].copy_from_slice(&id);
        w.put64(88, HEADER_SIZE);
        let table = w.alloc(OBJECT_DATA_HASH_TABLE, 0, 16 + options.buckets * 16);
        w.table = table + 16;
        w.put64(104, table + 16);
        w.put64(112, options.buckets * 16);
        let fields = w.alloc(OBJECT_FIELD_HASH_TABLE, 0, 16 + 4 * 16);
        w.put64(120, fields + 16);
        w.put64(128, 4 * 16);
        w
    }

    fn put64(&mut self, at: u64, value: u64) {
        self.buf[at as usize..at as usize + 8].copy_from_slice(&value.to_le_bytes());
    }
    fn put32(&mut self, at: u64, value: u32) {
        self.buf[at as usize..at as usize + 4].copy_from_slice(&value.to_le_bytes());
    }
    fn get64(&self, at: u64) -> u64 {
        u64::from_le_bytes(self.buf[at as usize..at as usize + 8].try_into().unwrap())
    }

    fn alloc(&mut self, kind: u8, flags: u8, size: u64) -> u64 {
        let offset = self.buf.len() as u64;
        let end = (offset + size).div_ceil(8) * 8;
        self.buf.resize(end as usize, 0);
        self.buf[offset as usize] = kind;
        self.buf[offset as usize + 1] = flags;
        self.put64(offset + 8, size);
        self.put64(96, end - HEADER_SIZE);
        self.put64(136, offset);
        let objects = self.get64(144);
        self.put64(144, objects + 1);
        offset
    }

    fn hash(&self, payload: &[u8]) -> u64 {
        if self.options.keyed {
            siphash24(payload, &self.file_id)
        } else {
            jenkins_hash64(payload)
        }
    }

    fn stored(&self, payload: &[u8]) -> (u8, Vec<u8>) {
        if payload.len() < COMPRESS_THRESHOLD || !payload.starts_with(b"MESSAGE=") {
            return (0, payload.to_vec());
        }
        match self.options.compression {
            Compression::None => (0, payload.to_vec()),
            Compression::Zstd => (
                OBJECT_COMPRESSED_ZSTD,
                ruzstd::encoding::compress_to_vec(
                    payload,
                    ruzstd::encoding::CompressionLevel::Fastest,
                ),
            ),
            Compression::Lz4 => {
                let mut out = (payload.len() as u64).to_le_bytes().to_vec();
                out.extend(lz4_flex::block::compress(payload));
                (OBJECT_COMPRESSED_LZ4, out)
            }
            Compression::FakeXz => (OBJECT_COMPRESSED_XZ, payload.to_vec()),
        }
    }

    fn data_object(&mut self, payload: &[u8]) -> u64 {
        if let Some(offset) = self.data.get(payload) {
            return *offset;
        }
        let hash = self.hash(payload);
        let (flags, stored) = self.stored(payload);
        let start = if self.options.compact { 72 } else { 64 };
        let offset = self.alloc(OBJECT_DATA, flags, start + stored.len() as u64);
        self.put64(offset + 16, hash);
        let at = (offset + start) as usize;
        self.buf[at..at + stored.len()].copy_from_slice(&stored);
        let item = self.table + (hash % self.options.buckets) * 16;
        if self.get64(item) == 0 {
            self.put64(item, offset);
        } else {
            let tail = self.get64(item + 8);
            self.put64(tail + 24, offset);
        }
        self.put64(item + 8, offset);
        self.data.insert(payload.to_vec(), offset);
        offset
    }

    pub fn append(&mut self, realtime: u64, fields: &[(&str, &[u8])]) {
        self.seqnum += 1;
        let mut objects = Vec::new();
        for (name, value) in fields {
            let mut payload = format!("{name}=").into_bytes();
            payload.extend_from_slice(value);
            objects.push(self.data_object(&payload));
        }
        objects.sort_unstable();
        objects.dedup();
        let item = if self.options.compact { 4 } else { 16 };
        let entry = self.alloc(OBJECT_ENTRY, 0, 64 + objects.len() as u64 * item);
        self.put64(entry + 16, self.seqnum);
        self.put64(entry + 24, realtime);
        self.put64(entry + 32, self.seqnum * 1000);
        for (index, data) in objects.iter().enumerate() {
            let at = entry + 64 + index as u64 * item;
            if self.options.compact {
                self.put32(at, *data as u32);
            } else {
                let hash = self.get64(data + 16);
                self.put64(at, *data);
                self.put64(at + 8, hash);
            }
        }
        for data in objects {
            self.link(data, entry);
        }
        let entries = self.get64(152);
        self.put64(152, entries + 1);
        if entries == 0 {
            self.put64(168, self.seqnum);
            self.put64(184, realtime);
        }
        self.put64(160, self.seqnum);
        self.put64(192, realtime);
    }

    fn link(&mut self, data: u64, entry: u64) {
        let n = self.get64(data + 56);
        self.put64(data + 56, n + 1);
        if n == 0 {
            self.put64(data + 40, entry);
            return;
        }
        let item = if self.options.compact { 4 } else { 8 };
        let mut index = n - 1;
        let arrays = self.arrays.get(&data).cloned().unwrap_or_default();
        for (array, capacity) in &arrays {
            if index < *capacity {
                self.put_item(array + 24 + index * item, entry);
                return;
            }
            index -= capacity;
        }
        let capacity = arrays
            .last()
            .map_or(self.options.first_array, |(_, c)| c * 2);
        let array = self.alloc(OBJECT_ENTRY_ARRAY, 0, 24 + capacity * item);
        match arrays.last() {
            Some((last, _)) => self.put64(last + 16, array),
            None => self.put64(data + 48, array),
        }
        if self.options.compact {
            self.put32(data + 64, array as u32);
        }
        self.put_item(array + 24, entry);
        self.arrays.entry(data).or_default().push((array, capacity));
    }

    fn put_item(&mut self, at: u64, entry: u64) {
        if self.options.compact {
            self.put32(at, entry as u32);
        } else {
            self.put64(at, entry);
        }
    }

    pub fn arrays_for(&self, payload: &str) -> usize {
        self.data
            .get(payload.as_bytes())
            .and_then(|data| self.arrays.get(data))
            .map_or(0, Vec::len)
    }
    pub fn set_archived(&mut self) {
        self.buf[16] = STATE_ARCHIVED;
    }
    pub fn set_incompatible_bit(&mut self, bit: u32) {
        let flags = u32::from_le_bytes(self.buf[12..16].try_into().unwrap());
        self.put32(12, flags | bit);
    }
    pub fn bytes(&self) -> &[u8] {
        &self.buf
    }
    /// Writes in place, so an open reader keeps the same file.
    pub fn write(&self, path: &Path) {
        let file = std::fs::OpenOptions::new()
            .create(true)
            .truncate(false)
            .write(true)
            .open(path)
            .unwrap();
        file.write_all_at(&self.buf, 0).unwrap();
        file.set_len(self.buf.len() as u64).unwrap();
    }
}
