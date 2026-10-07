//! One-shot task sandbox control, invoked by the Gateway over pinned SSH.
//! This does not load the live agent identity or join its realtime channel.
use serde::{Deserialize, Serialize};
use std::{
    collections::BTreeMap,
    io::{Read, Write},
    process::{Command, Stdio},
};

const MAX_REQUEST: u64 = 1024 * 1024;
const CONTROLLER: &str = include_str!("../resources/incus-sandbox.py");

#[derive(Deserialize, Serialize, PartialEq)]
#[serde(rename_all = "snake_case")]
enum Operation {
    Provision,
    Observe,
    Capacity,
    Park,
    Resume,
    Destroy,
}

#[derive(Deserialize, Serialize, PartialEq, Eq, PartialOrd, Ord)]
#[serde(rename_all = "kebab-case")]
enum Role {
    Operator,
    Gateway,
    AppDev,
    AppProd,
    AppProd2,
}

#[derive(Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
struct Spec {
    images: BTreeMap<Role, String>,
    pool: String,
    subnet: String,
    blocked_networks: Vec<String>,
}

#[derive(Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
struct Request {
    operation: Operation,
    project: String,
    sandbox_id: String,
    budget: u8,
    #[serde(skip_serializing_if = "Option::is_none")]
    spec: Option<Spec>,
}

fn request(input: impl Read) -> Result<Vec<u8>, &'static str> {
    let mut bytes = Vec::new();
    input
        .take(MAX_REQUEST + 1)
        .read_to_end(&mut bytes)
        .map_err(|_| "sandbox request could not be read")?;
    if bytes.len() > MAX_REQUEST as usize {
        return Err("sandbox request is too large");
    }
    let request: Request =
        serde_json::from_slice(&bytes).map_err(|_| "sandbox request is invalid")?;
    if (request.operation == Operation::Provision) != request.spec.is_some()
        || !(1..=64).contains(&request.budget)
    {
        return Err("sandbox request is invalid");
    }
    serde_json::to_vec(&request).map_err(|_| "sandbox request is invalid")
}

/// Run only the embedded, versioned controller. No caller-supplied host command is accepted.
pub fn run() -> Result<(), &'static str> {
    let input = request(std::io::stdin().lock())?;
    let mut child = Command::new("/usr/bin/python3")
        .args(["-I", "-c", CONTROLLER])
        .env_clear()
        .env("PATH", "/usr/sbin:/usr/bin:/sbin:/bin")
        .stdin(Stdio::piped())
        .spawn()
        .map_err(|_| "sandbox controller could not start")?;
    let written = child
        .stdin
        .take()
        .ok_or("sandbox request could not be sent")?
        .write_all(&input);
    let status = child
        .wait()
        .map_err(|_| "sandbox controller status could not be read")?;
    if written.is_err() || !status.success() {
        return Err("sandbox operation failed");
    }
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    fn base() -> serde_json::Value {
        json!({"operation":"observe", "project":"orbit-task-sandboxes",
               "sandbox_id":"ca656ccf-240d-476c-90f1-cf70f9dd7a12", "budget":4})
    }

    #[test]
    fn accepts_lifecycle_operations_without_arbitrary_commands() {
        for operation in ["observe", "capacity", "park", "resume", "destroy"] {
            let mut value = base();
            value["operation"] = operation.into();
            assert!(request(value.to_string().as_bytes()).is_ok());
        }
        for operation in ["exec", "shell", "delete-host"] {
            let mut value = base();
            value["operation"] = operation.into();
            assert!(request(value.to_string().as_bytes()).is_err());
        }
        let mut value = base();
        value["command"] = "secret-never-echoed".into();
        assert_eq!(
            request(value.to_string().as_bytes()).unwrap_err(),
            "sandbox request is invalid"
        );
    }

    #[test]
    fn requires_a_closed_provision_spec_and_budget() {
        let mut value = base();
        value["operation"] = "provision".into();
        assert!(request(value.to_string().as_bytes()).is_err());
        value["spec"] = json!({"images":{"operator":"a".repeat(64)}, "pool":"proof",
                              "subnet":"10.233.201.0/24", "blocked_networks":["192.168.0.0/16"]});
        assert!(request(value.to_string().as_bytes()).is_ok());
        value["spec"]["command"] = "unexpected".into();
        assert!(request(value.to_string().as_bytes()).is_err());
        let mut value = base();
        value["budget"] = 0.into();
        assert!(request(value.to_string().as_bytes()).is_err());
    }

    #[test]
    fn bounds_input_before_starting_a_process() {
        assert_eq!(
            request(vec![b' '; MAX_REQUEST as usize + 1].as_slice()).unwrap_err(),
            "sandbox request is too large"
        );
    }
}
