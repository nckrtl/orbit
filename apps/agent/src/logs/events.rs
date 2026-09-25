//! `client-log` and `client-log-end` events on `presence-node-logs.{id}` (ADR 0153).
use super::limits::{floor_char_boundary, TRUNCATED};
use crate::{frame, ClientFrame, PUSHER_FRAME_LIMIT};
use serde_json::json;

pub const LOG_EVENT: &str = "client-log";
pub const LOG_END_EVENT: &str = "client-log-end";

/// The bytes one character takes inside a JSON string, as serde_json writes it.
fn escaped_char_len(c: char) -> usize {
    match c {
        '"' | '\\' | '\n' | '\r' | '\t' | '\u{08}' | '\u{0c}' => 2,
        c if (c as u32) < 0x20 => 6,
        c => c.len_utf8(),
    }
}
/// The bytes a line takes as a JSON string, with its quotes.
pub fn json_string_len(line: &str) -> usize {
    2 + line.chars().map(escaped_char_len).sum::<usize>()
}

/// Cuts a line so that it takes at most `budget` bytes as a JSON string, ending with `[truncated]`.
fn cut_to_json_budget(line: &str, budget: usize) -> String {
    let room = budget.saturating_sub(2 + TRUNCATED.len());
    let mut used = 0;
    let mut end = 0;
    for (index, c) in line.char_indices() {
        let size = escaped_char_len(c);
        if used + size > room {
            break;
        }
        used += size;
        end = index + c.len_utf8();
    }
    format!("{}{TRUNCATED}", &line[..floor_char_boundary(line, end)])
}

/// The frame size of an event with no lines and the largest counters, so real frames never exceed it.
fn frame_overhead(channel: &str, stream: &str) -> Result<usize, serde_json::Error> {
    let probe = frame(
        channel,
        LOG_EVENT,
        json!({"stream": stream, "sequence": u64::MAX, "lines": [], "dropped": u64::MAX, "skipped": u64::MAX}),
    );
    Ok(serde_json::to_vec(&probe)?.len())
}

/// Splits one batch into `client-log` events whose frames stay within the Pusher limit. `dropped` and
/// `skipped` go in the first event. A line that cannot fit even alone is cut further.
pub fn log_frames(
    channel: &str,
    stream: &str,
    sequence: &mut u64,
    lines: Vec<String>,
    dropped: u64,
    skipped: u64,
) -> Result<Vec<ClientFrame>, serde_json::Error> {
    let overhead = frame_overhead(channel, stream)?;
    let budget = PUSHER_FRAME_LIMIT.saturating_sub(overhead);
    let mut parts: Vec<Vec<String>> = Vec::new();
    let mut current: Vec<String> = Vec::new();
    let mut size = 0;
    for line in lines {
        let mut line = line;
        let mut len = json_string_len(&line);
        if len > budget {
            line = cut_to_json_budget(&line, budget);
            len = json_string_len(&line);
        }
        let comma = usize::from(!current.is_empty());
        if !current.is_empty() && size + comma + len > budget {
            parts.push(std::mem::take(&mut current));
            size = 0;
        }
        size += usize::from(!current.is_empty()) + len;
        current.push(line);
    }
    if !current.is_empty() || parts.is_empty() {
        parts.push(current);
    }
    let mut frames = Vec::with_capacity(parts.len());
    for (index, lines) in parts.into_iter().enumerate() {
        *sequence += 1;
        let (dropped, skipped) = if index == 0 {
            (dropped, skipped)
        } else {
            (0, 0)
        };
        let event = frame(
            channel,
            LOG_EVENT,
            json!({"stream": stream, "sequence": *sequence, "lines": lines, "dropped": dropped, "skipped": skipped}),
        );
        debug_assert!(serde_json::to_vec(&event)?.len() <= PUSHER_FRAME_LIMIT);
        frames.push(event);
    }
    Ok(frames)
}

pub fn log_end_frame(channel: &str, stream: &str, reason: &str) -> ClientFrame {
    frame(
        channel,
        LOG_END_EVENT,
        json!({"stream": stream, "reason": reason}),
    )
}

#[cfg(test)]
mod tests {
    use super::*;

    const CHANNEL: &str = "presence-node-logs.18446744073709551615";

    fn stream() -> String {
        "0123456789abcdef0123456789abcdef".into()
    }

    #[test]
    fn json_length_matches_serde() {
        for line in ["plain", "quote\" back\\ tab\t", "\u{1}\u{1f}\u{7f}é🙂", ""] {
            assert_eq!(
                json_string_len(line),
                serde_json::to_string(line).unwrap().len()
            );
        }
    }

    #[test]
    fn one_event_with_every_field() {
        let mut sequence = 0;
        let frames = log_frames(
            CHANNEL,
            &stream(),
            &mut sequence,
            vec!["a".into(), "b".into()],
            3,
            4,
        )
        .unwrap();
        assert_eq!(frames.len(), 1);
        assert_eq!(frames[0].event, "client-log");
        assert_eq!(frames[0].channel, CHANNEL);
        assert_eq!(
            frames[0].data,
            json!({"stream": stream(), "sequence": 1, "lines": ["a", "b"], "dropped": 3, "skipped": 4})
        );
        assert_eq!(sequence, 1);
    }

    #[test]
    fn large_batches_split_into_frames_within_the_limit() {
        let mut sequence = 7;
        let lines: Vec<String> = (0..600)
            .map(|i| format!("{i} {}", "\"\u{1}é".repeat(i % 50)))
            .chain(std::iter::once("x".repeat(8192)))
            .chain(std::iter::once("\u{1}".repeat(8000)))
            .collect();
        let frames = log_frames(CHANNEL, &stream(), &mut sequence, lines.clone(), 9, 10).unwrap();
        assert!(frames.len() > 5);
        let mut seen = Vec::new();
        for (index, frame) in frames.iter().enumerate() {
            assert!(serde_json::to_vec(frame).unwrap().len() <= PUSHER_FRAME_LIMIT);
            assert_eq!(frame.data["sequence"], 8 + index as u64);
            let expected = if index == 0 { (9, 10) } else { (0, 0) };
            assert_eq!(frame.data["dropped"], expected.0);
            assert_eq!(frame.data["skipped"], expected.1);
            for line in frame.data["lines"].as_array().unwrap() {
                seen.push(line.as_str().unwrap().to_owned());
            }
        }
        assert_eq!(seen.len(), lines.len());
        assert_eq!(&seen[..601], &lines[..601]);
        assert!(seen[601].ends_with("[truncated]"));
        assert!(seen[601].len() < 8000);
    }

    #[test]
    fn end_event_names_the_stream_and_reason() {
        let end = log_end_frame(CHANNEL, &stream(), "source_unavailable");
        assert_eq!(end.event, "client-log-end");
        assert_eq!(
            end.data,
            json!({"stream": stream(), "reason": "source_unavailable"})
        );
    }
}
