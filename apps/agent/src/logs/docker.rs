//! Follows the output of an Orbit Docker Process through the Docker Engine API (ADR 0153).
use super::{
    laravel::Unavailable,
    limits::{cut_continued_line, line_text, LineSink, MAX_LINE_BYTES},
};
use bollard::{
    container::{LogOutput, LogsOptions},
    errors::Error as DockerError,
    models::ContainerInspectResponse,
    Docker,
};
use futures_util::StreamExt;
use std::time::Duration;
use time::{format_description::well_known::Rfc3339, OffsetDateTime};

pub const RETRY: Duration = Duration::from_secs(2);

/// The container must have exactly this name and the Orbit labels of its Process.
pub fn verify(
    inspect: &ContainerInspectResponse,
    container: &str,
    process_id: u64,
) -> Result<(), Unavailable> {
    let name_ok = inspect.name.as_deref() == Some(&format!("/{container}"));
    let labels = inspect.config.as_ref().and_then(|c| c.labels.as_ref());
    let label = |key: &str| labels.and_then(|l| l.get(key)).map(String::as_str);
    let labels_ok = label("orbit.managed") == Some("true")
        && label("orbit.process.id") == Some(process_id.to_string().as_str());
    if name_ok && labels_ok {
        Ok(())
    } else {
        Err(Unavailable("container name or labels"))
    }
}

/// Joins Docker's output chunks into lines, one buffer for standard output and one for standard error.
#[derive(Debug, Default)]
pub struct LineSplitter {
    partial: [Vec<u8>; 2],
    discarding: [bool; 2],
}
impl LineSplitter {
    pub fn push(&mut self, stream: usize, mut bytes: &[u8], out: &mut Vec<String>) {
        while !bytes.is_empty() {
            let newline = bytes.iter().position(|b| *b == b'\n');
            let (piece, rest, complete) = match newline {
                Some(index) => (&bytes[..index], &bytes[index + 1..], true),
                None => (bytes, &[][..], false),
            };
            bytes = rest;
            if self.discarding[stream] {
                self.discarding[stream] = !complete;
                continue;
            }
            let partial = &mut self.partial[stream];
            // Keep room for a timestamp prefix, which is removed before the line length counts.
            let room = MAX_LINE_BYTES + 64 - partial.len().min(MAX_LINE_BYTES + 64);
            partial.extend_from_slice(&piece[..piece.len().min(room)]);
            if partial.len() >= MAX_LINE_BYTES + 64 {
                out.push(cut_continued_line(&line_text(partial)));
                partial.clear();
                self.discarding[stream] = !complete;
            } else if complete {
                out.push(line_text(partial));
                partial.clear();
            }
        }
    }
}

/// Splits `2026-09-25T10:15:02.123456789Z message` into its time and message.
pub fn split_timestamp(line: &str) -> (Option<OffsetDateTime>, &str) {
    match line.split_once(' ') {
        Some((stamp, message)) => match OffsetDateTime::parse(stamp, &Rfc3339) {
            Ok(at) => (Some(at), message),
            Err(_) => (None, line),
        },
        None => match OffsetDateTime::parse(line, &Rfc3339) {
            Ok(at) => (Some(at), ""),
            Err(_) => (None, line),
        },
    }
}

/// Reads one logs request to its end. `after` skips lines at or before the last line already sent.
/// Returns the time of the last line, or an error when Docker refuses the logs of this container.
async fn follow(
    docker: &Docker,
    id: &str,
    options: LogsOptions<String>,
    after: Option<OffsetDateTime>,
    mut deliver: impl FnMut(String),
) -> Result<Option<OffsetDateTime>, Option<Unavailable>> {
    let mut stream = docker.logs(id, Some(options));
    let mut splitter = LineSplitter::default();
    let mut last = after;
    let mut lines = Vec::new();
    while let Some(item) = stream.next().await {
        let (index, message) = match item {
            Ok(LogOutput::StdOut { message }) | Ok(LogOutput::Console { message }) => (0, message),
            Ok(LogOutput::StdErr { message }) => (1, message),
            Ok(LogOutput::StdIn { .. }) => continue,
            Err(DockerError::DockerResponseServerError { status_code, .. })
                if status_code != 404 && status_code < 500 || status_code == 501 =>
            {
                // For example a logging driver that cannot be read.
                return Err(Some(Unavailable("docker logs")));
            }
            Err(_) => return Err(None),
        };
        splitter.push(index, &message, &mut lines);
        for line in lines.drain(..) {
            let (at, text) = split_timestamp(&line);
            if let (Some(at), Some(previous)) = (at, after) {
                if at <= previous {
                    continue;
                }
            }
            if at.is_some() {
                last = at;
            }
            deliver(text.to_owned());
        }
    }
    Ok(last)
}

/// Runs a `docker` source until it cannot be read. A missing or stopped container is inspected again
/// every 2 seconds; the Gateway's lease bounds how long. Following resumes after the last line sent.
pub async fn run(
    container: String,
    process_id: u64,
    sink: &mut LineSink,
) -> Result<(), Unavailable> {
    let mut again = false;
    let mut last: Option<OffsetDateTime> = None;
    loop {
        if again {
            tokio::time::sleep(RETRY).await;
        }
        again = true;
        let Ok(docker) = Docker::connect_with_unix_defaults() else {
            continue;
        };
        let Ok(inspect) = docker.inspect_container(&container, None).await else {
            continue;
        };
        verify(&inspect, &container, process_id)?;
        let Some(id) = inspect.id.clone() else {
            continue;
        };
        if last.is_none() {
            // The first lines, as one batch.
            let options = LogsOptions {
                follow: false,
                stdout: true,
                stderr: true,
                timestamps: true,
                tail: sink.lines().to_string(),
                ..Default::default()
            };
            let started = OffsetDateTime::now_utc() - time::Duration::seconds(1);
            let mut batch = Vec::new();
            match follow(&docker, &id, options, None, |line| batch.push(line)).await {
                Ok(at) => {
                    sink.first(batch);
                    last = Some(at.unwrap_or(started));
                }
                Err(Some(unavailable)) => return Err(unavailable),
                Err(None) => continue,
            }
        }
        let running = inspect
            .state
            .as_ref()
            .and_then(|state| state.running)
            .unwrap_or(false);
        if !running {
            continue;
        }
        let options = LogsOptions {
            follow: true,
            stdout: true,
            stderr: true,
            timestamps: true,
            since: last.map_or(0, OffsetDateTime::unix_timestamp),
            tail: "all".to_string(),
            ..Default::default()
        };
        match follow(&docker, &id, options, last, |line| sink.line(&line)).await {
            Ok(at) => last = at.or(last),
            Err(Some(unavailable)) => return Err(unavailable),
            Err(None) => {}
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use bollard::models::ContainerConfig;
    use std::collections::HashMap;

    fn inspect(name: &str, labels: &[(&str, &str)]) -> ContainerInspectResponse {
        ContainerInspectResponse {
            name: Some(name.into()),
            config: Some(ContainerConfig {
                labels: Some(
                    labels
                        .iter()
                        .map(|(k, v)| (k.to_string(), v.to_string()))
                        .collect::<HashMap<_, _>>(),
                ),
                ..Default::default()
            }),
            ..Default::default()
        }
    }

    #[test]
    fn container_name_and_labels_must_match() {
        let good = [("orbit.managed", "true"), ("orbit.process.id", "42")];
        assert!(verify(
            &inspect("/orbit-process-42-web", &good),
            "orbit-process-42-web",
            42
        )
        .is_ok());
        assert!(verify(
            &inspect("/orbit-process-42-web", &good),
            "orbit-process-42-web",
            43
        )
        .is_err());
        assert!(verify(&inspect("/other", &good), "orbit-process-42-web", 42).is_err());
        assert!(verify(
            &inspect("orbit-process-42-web", &good),
            "orbit-process-42-web",
            42
        )
        .is_err());
        assert!(verify(
            &inspect("/orbit-process-42-web", &[("orbit.process.id", "42")]),
            "orbit-process-42-web",
            42
        )
        .is_err());
        assert!(verify(
            &inspect(
                "/orbit-process-42-web",
                &[("orbit.managed", "yes"), ("orbit.process.id", "42")]
            ),
            "orbit-process-42-web",
            42
        )
        .is_err());
        assert!(verify(
            &ContainerInspectResponse::default(),
            "orbit-process-42-web",
            42
        )
        .is_err());
    }

    #[test]
    fn chunks_become_lines_per_stream() {
        let mut splitter = LineSplitter::default();
        let mut out = Vec::new();
        splitter.push(0, b"out one\nout ", &mut out);
        splitter.push(1, b"err one\r\n", &mut out);
        splitter.push(0, b"two\n", &mut out);
        assert_eq!(out, ["out one", "err one", "out two"]);
        out.clear();
        splitter.push(0, &vec![b'x'; 20_000], &mut out);
        splitter.push(0, b"xx\nafter\n", &mut out);
        assert_eq!(out.len(), 2);
        assert!(out[0].ends_with("[truncated]"));
        assert_eq!(out[1], "after");
    }

    #[test]
    fn timestamps_are_split_from_the_message() {
        let (at, text) = split_timestamp("2026-09-25T10:15:02.123456789Z hello world");
        assert_eq!(text, "hello world");
        assert_eq!(at.unwrap().unix_timestamp(), 1_790_331_302);
        assert_eq!(split_timestamp("no timestamp").1, "no timestamp");
        assert_eq!(split_timestamp("2026-09-25T10:15:02Z").1, "");
    }
}
