//! A port of the Gateway's `CommandActivityInputSanitizer::redactText` (ADR 0153).
//!
//! PHP runs these patterns without `/u`, so `\b`, `\s`, `\S`, and `/i` use ASCII byte semantics. The
//! patterns below use `(?-u)` on bytes for the same result. `tests/redaction_cases.json` holds the
//! expected results, computed by the PHP implementation; the Gateway runs the same file.
use regex::bytes::{Captures, Regex};
use std::sync::OnceLock;

pub const REDACTED: &str = "[REDACTED]";
/// A PEM block that spans lines is redacted for at most this many lines, counting its `BEGIN` line.
pub const PEM_MAX_LINES: usize = 200;

const PREFIX: &str = r"(?:[A-Za-z][A-Za-z0-9]*[_-])";
const SUFFIX: &str = r"(?:[_-](?:HASH|BASE))?";

struct Patterns {
    pem: Regex,
    url: Regex,
    authorization: Regex,
    bearer: Regex,
    env: Regex,
    json: Regex,
    colon: Regex,
    begin: Regex,
    end: Regex,
}

fn patterns() -> &'static Patterns {
    static PATTERNS: OnceLock<Patterns> = OnceLock::new();
    PATTERNS.get_or_init(|| {
        let keys = format!(
            "(?:{PREFIX}*(?:TOKENS?|SECRETS?|PASSWORDS?|PASSWD|PASSPHRASE|CREDENTIALS?|BEARER|APIKEY|APPKEY|AUTHTOKEN|ACCESSTOKEN|PRIVATEKEY)|{PREFIX}+KEYS?){SUFFIX}"
        );
        let compile = |pattern: &str| Regex::new(pattern).expect("redaction pattern compiles");
        Patterns {
            pem: compile(r"(?-u)-----BEGIN [A-Z0-9 ]+-----[\s\S]*?-----END [A-Z0-9 ]+-----"),
            url: compile(r"(?i-u)\b([a-z][a-z0-9+.-]*://)[^/@\s]+@"),
            // PHP adds `(?!\[REDACTED\])` after the second `\s*`; `replace_authorization` emulates it.
            authorization: compile(
                r#"(?i-u)\b((?:Proxy-)?Authorization)\s*:\s*([^\s'"]+(?:\s+[^\s'"]+)?)"#,
            ),
            bearer: compile(
                r#"(?i-u)\bBearer\s+(?:"[^"]*"|'[^']*'|[A-Za-z0-9][A-Za-z0-9._\-+/=]{7,})"#,
            ),
            env: compile(&format!(
                r#"(?i-u)\b({keys})\s*=\s*(?:"[^"]*"|'[^']*'|[^\s&#]+)"#
            )),
            json: compile(&format!(
                r#"(?i-u)("(?:{keys})"\s*:\s*)(?:"(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*'|[^,}}\s]+)"#
            )),
            colon: compile(&format!(
                r#"(?i-u)\b({keys})\s*:\s*(?:"[^"]*"|'[^']*'|\S+)"#
            )),
            begin: compile(r"(?-u)-----BEGIN [A-Z0-9 ]+-----"),
            end: compile(r"(?-u)-----END [A-Z0-9 ]+-----"),
        }
    })
}

/// Redacts one text exactly as the Gateway's `redactText` does.
pub fn redact_text(text: &str) -> String {
    let p = patterns();
    let pem = p.pem.replace_all(text.as_bytes(), REDACTED.as_bytes());
    into_string(redact_after_pem(&pem))
}

fn redact_after_pem(text: &[u8]) -> Vec<u8> {
    let p = patterns();
    let text = p.url.replace_all(text, b"${1}[REDACTED]@".as_slice());
    let text = replace_authorization(&p.authorization, &text);
    let text = p.bearer.replace_all(&text, b"Bearer [REDACTED]".as_slice());
    let text = p.env.replace_all(&text, b"${1}=[REDACTED]".as_slice());
    let text = p.json.replace_all(&text, b"${1}\"[REDACTED]\"".as_slice());
    p.colon
        .replace_all(&text, b"${1}: [REDACTED]".as_slice())
        .into_owned()
}

/// PCRE rejects a start position whose value begins with `[REDACTED]` (ASCII case-insensitive) and
/// then tries the next position, so this searches again from one byte after a rejected start.
fn replace_authorization(pattern: &Regex, text: &[u8]) -> Vec<u8> {
    let mut out = Vec::with_capacity(text.len());
    let mut copied = 0;
    let mut at = 0;
    while at <= text.len() {
        let Some(captures) = pattern.captures_at(text, at) else {
            break;
        };
        let (whole, name, value) = parts(&captures);
        if value.len() >= REDACTED.len()
            && value[..REDACTED.len()].eq_ignore_ascii_case(REDACTED.as_bytes())
        {
            at = whole.0 + 1;
            continue;
        }
        out.extend_from_slice(&text[copied..whole.0]);
        out.extend_from_slice(name);
        out.extend_from_slice(b": [REDACTED]");
        copied = whole.1;
        at = whole.1;
    }
    out.extend_from_slice(&text[copied..]);
    out
}

fn parts<'a>(captures: &Captures<'a>) -> ((usize, usize), &'a [u8], &'a [u8]) {
    let whole = captures.get(0).expect("group 0 always matches");
    let name = captures.get(1).map_or(&[][..], |m| m.as_bytes());
    let value = captures.get(2).map_or(&[][..], |m| m.as_bytes());
    ((whole.start(), whole.end()), name, value)
}

fn into_string(bytes: Vec<u8>) -> String {
    String::from_utf8(bytes)
        .unwrap_or_else(|error| String::from_utf8_lossy(error.as_bytes()).into())
}

/// The line that replaces the last hidden line of a PEM block that has no `END` within the limit.
pub fn unclosed_marker(hidden: usize) -> String {
    format!("[orbit] {hidden} lines redacted after a PEM BEGIN line without END")
}

/// Redacts a stream line by line. It carries the state of a PEM block that spans lines.
#[derive(Debug, Default)]
pub struct Redactor {
    /// Lines of the open PEM block so far, counting its `BEGIN` line.
    pem_lines: Option<usize>,
}

impl Redactor {
    /// Returns the redacted line, or `None` when the line is part of a PEM block.
    pub fn line(&mut self, line: &str) -> Option<String> {
        self.process(line, true)
    }

    /// Updates the PEM state for a line that is dropped without being sent.
    pub fn observe(&mut self, line: &str) {
        if self.pem_lines.is_some() || line.contains("-----") {
            self.process(line, false);
        }
    }

    fn process(&mut self, line: &str, render: bool) -> Option<String> {
        let p = patterns();
        if let Some(count) = self.pem_lines {
            if let Some(end) = p.end.find(line.as_bytes()) {
                self.pem_lines = None;
                let rest = &line[end.end()..];
                return if rest.is_empty() {
                    None
                } else {
                    self.process(rest, render)
                };
            }
            let count = count + 1;
            if count < PEM_MAX_LINES {
                self.pem_lines = Some(count);
                return None;
            }
            // No END within the limit: the block ends here, and the reader learns what was hidden.
            self.pem_lines = None;
            return render.then(|| unclosed_marker(count - 1));
        }
        if !render && !line.contains("-----") {
            return None;
        }
        let pem = p.pem.replace_all(line.as_bytes(), REDACTED.as_bytes());
        // Complete blocks are gone, so a remaining BEGIN opens a block that continues on later lines.
        if let Some(begin) = p.begin.find(&pem) {
            self.pem_lines = Some(1);
            if !render {
                return None;
            }
            let mut out = redact_after_pem(&pem[..begin.start()]);
            out.extend_from_slice(REDACTED.as_bytes());
            return Some(into_string(out));
        }
        render.then(|| into_string(redact_after_pem(&pem)))
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde::Deserialize;

    #[derive(Deserialize)]
    struct Case {
        name: String,
        input: String,
        expected: String,
    }

    #[test]
    fn shared_cases_match_the_gateway_implementation() {
        let cases: Vec<Case> =
            serde_json::from_str(include_str!("../../tests/redaction_cases.json")).unwrap();
        assert!(cases.len() >= 50);
        for case in cases {
            assert_eq!(
                redact_text(&case.input),
                case.expected,
                "case {}",
                case.name
            );
            assert!(
                !case.input.contains('\n'),
                "case {} must be one line",
                case.name
            );
            let mut redactor = Redactor::default();
            if !case.input.contains("-----BEGIN") {
                assert_eq!(
                    redactor.line(&case.input).as_deref(),
                    Some(case.expected.as_str()),
                    "case {} through the line redactor",
                    case.name
                );
            }
        }
    }

    #[test]
    fn authorization_lookahead_skips_every_start_of_an_already_redacted_value() {
        assert_eq!(
            redact_text("Proxy-Authorization: [REDACTED] then Authorization: abc"),
            "Proxy-Authorization: [REDACTED] then Authorization: [REDACTED]"
        );
        assert_eq!(
            redact_text("Authorization: [REDACTEDX] y"),
            "Authorization: [REDACTED]"
        );
    }

    #[test]
    fn pem_block_across_lines_is_one_redacted_line() {
        let mut r = Redactor::default();
        let lines = [
            "key follows: -----BEGIN OPENSSH PRIVATE KEY-----",
            "b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQ",
            "AAAAC3NzaC1lZDI1NTE5AAAAIGQ",
            "-----END OPENSSH PRIVATE KEY----- password=hunter2",
            "next line",
        ];
        let out: Vec<_> = lines.iter().filter_map(|l| r.line(l)).collect();
        assert_eq!(
            out,
            [
                "key follows: [REDACTED]",
                " password=[REDACTED]",
                "next line"
            ]
        );
    }

    #[test]
    fn pem_block_without_end_resumes_after_200_lines() {
        let mut r = Redactor::default();
        assert_eq!(
            r.line("-----BEGIN CERTIFICATE-----").as_deref(),
            Some("[REDACTED]")
        );
        for _ in 2..PEM_MAX_LINES {
            assert_eq!(r.line("MIIBszCCAVmgAwIBAgIU"), None);
        }
        // The 200th line of the block ends it with a marker that counts the hidden lines.
        assert_eq!(
            r.line("MIIBszCCAVmgAwIBAgIU").as_deref(),
            Some("[orbit] 199 lines redacted after a PEM BEGIN line without END")
        );
        assert_eq!(r.line("after").as_deref(), Some("after"));
    }

    #[test]
    fn observed_lines_keep_the_pem_state() {
        let mut r = Redactor::default();
        r.observe("-----BEGIN RSA PRIVATE KEY-----");
        assert_eq!(r.line("MIIEpAIBAAKCAQEA"), None);
        r.observe("-----END RSA PRIVATE KEY-----");
        assert_eq!(r.line("plain").as_deref(), Some("plain"));
        r.observe("no marker here");
        assert_eq!(r.line("plain").as_deref(), Some("plain"));
    }

    #[test]
    fn single_line_block_then_open_block_on_the_same_line() {
        let mut r = Redactor::default();
        assert_eq!(
            r.line("a -----BEGIN X-----y-----END X----- b -----BEGIN Y----- z")
                .as_deref(),
            Some("a [REDACTED] b [REDACTED]")
        );
        assert_eq!(r.line("-----END Y-----"), None);
        assert_eq!(r.line("c").as_deref(), Some("c"));
    }
}
