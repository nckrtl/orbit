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
    GuestCommand,
    ProjectIdentity,
    ProjectFleetIdentity,
}

#[derive(Deserialize, Serialize, PartialEq, Eq, PartialOrd, Ord)]
#[serde(rename_all = "kebab-case")]
enum Role {
    Operator,
    Gateway,
    AppDev,
    AppProd,
    #[serde(rename = "app-prod-2")]
    AppProd2,
}

#[derive(Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
struct SourceTemplate {
    id: String,
    repository: String,
    base: String,
    commit: String,
}

#[derive(Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
struct ProjectBootstrap {
    ssh_host: String,
    ssh_port: u16,
    gateway_address: String,
    wireguard_address: String,
    wireguard_port: u16,
}

#[derive(Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
struct Spec {
    images: BTreeMap<Role, String>,
    #[serde(skip_serializing_if = "Option::is_none")]
    source_template: Option<SourceTemplate>,
    #[serde(skip_serializing_if = "Option::is_none")]
    project_slug: Option<String>,
    #[serde(skip_serializing_if = "Option::is_none")]
    project_bootstrap: Option<ProjectBootstrap>,
    pool: String,
    subnet: String,
    blocked_networks: Vec<String>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pi_host: Option<String>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pi_port: Option<u16>,
    #[serde(skip_serializing_if = "Option::is_none")]
    gateway_address: Option<String>,
    #[serde(skip_serializing_if = "Option::is_none")]
    model_proxy_origin: Option<String>,
}

#[derive(Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
struct GuestCommand {
    role: Role,
    argv: Vec<String>,
    stdin: String,
    timeout: u16,
    max_output: u32,
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
    #[serde(skip_serializing_if = "Option::is_none")]
    guest: Option<GuestCommand>,
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
        || (request.operation == Operation::GuestCommand) != request.guest.is_some()
        || !(1..=64).contains(&request.budget)
    {
        return Err("sandbox request is invalid");
    }
    if let Some(guest) = &request.guest {
        if guest.argv.is_empty()
            || guest.argv.len() > 128
            || guest.argv[0].is_empty()
            || guest
                .argv
                .iter()
                .any(|arg| arg.contains('\0') || arg.len() > 65536)
            || !(1..=900).contains(&guest.timeout)
            || !(1..=8 * 1024 * 1024).contains(&guest.max_output)
        {
            return Err("sandbox request is invalid");
        }
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
        for operation in [
            "observe",
            "capacity",
            "park",
            "resume",
            "destroy",
            "project_identity",
            "project_fleet_identity",
        ] {
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
    fn guest_commands_are_closed_bounded_and_distinct_from_host_operations() {
        let mut value = base();
        value["operation"] = "guest_command".into();
        assert!(request(value.to_string().as_bytes()).is_err());
        value["guest"] =
            json!({"role":"operator", "argv":["id"], "stdin":"", "timeout":30, "max_output":4096});
        assert!(request(value.to_string().as_bytes()).is_ok());
        for (field, invalid) in [
            ("timeout", json!(901)),
            ("max_output", json!(0)),
            ("role", json!("host")),
            ("argv", json!([])),
            ("argv", json!(["x\u{0000}y"])),
        ] {
            let mut bad = value.clone();
            bad["guest"][field] = invalid;
            assert!(request(bad.to_string().as_bytes()).is_err());
        }
        let mut bad = value.clone();
        bad["operation"] = "observe".into();
        assert!(request(bad.to_string().as_bytes()).is_err());
        value["guest"]["host_command"] = "never".into();
        assert!(request(value.to_string().as_bytes()).is_err());
    }

    #[test]
    fn preserves_declared_role_names_in_image_keys_and_guest_commands() {
        let mut value = base();
        value["operation"] = "provision".into();
        value["spec"] = json!({"images":{
            "operator":"a".repeat(64), "gateway":"b".repeat(64),
            "app-dev":"c".repeat(64), "app-prod":"d".repeat(64), "app-prod-2":"e".repeat(64)
        }, "pool":"proof", "subnet":"10.233.201.0/24", "blocked_networks":["192.168.0.0/16"]});

        let forwarded: serde_json::Value = serde_json::from_slice(
            &request(value.to_string().as_bytes())
                .expect("all declared image roles must be accepted"),
        )
        .unwrap();
        assert_eq!(forwarded["spec"]["images"], value["spec"]["images"]);

        for role in ["app-dev", "app-prod", "app-prod-2"] {
            let mut value = base();
            value["operation"] = "guest_command".into();
            value["guest"] =
                json!({"role":role, "argv":["true"], "stdin":"", "timeout":30, "max_output":4096});
            let forwarded: serde_json::Value = serde_json::from_slice(
                &request(value.to_string().as_bytes())
                    .expect("the declared guest role must be accepted"),
            )
            .unwrap();
            assert_eq!(forwarded["guest"]["role"], role);
        }
    }

    #[test]
    fn rejects_undocumented_numeric_workload_alias_in_both_request_paths() {
        let mut value = base();
        value["operation"] = "provision".into();
        value["spec"] = json!({"images":{"app-prod2":"a".repeat(64)}, "pool":"proof",
            "subnet":"10.233.201.0/24", "blocked_networks":["192.168.0.0/16"]});
        assert!(request(value.to_string().as_bytes()).is_err());

        let mut value = base();
        value["operation"] = "guest_command".into();
        value["guest"] = json!({"role":"app-prod2", "argv":["true"], "stdin":"", "timeout":30, "max_output":4096});
        assert!(request(value.to_string().as_bytes()).is_err());
    }

    #[test]
    fn accepts_only_typed_proxy_fields_in_provision_spec() {
        let mut value = base();
        value["operation"] = "provision".into();
        value["spec"] = json!({"images":{"operator":"a".repeat(64)}, "pool":"proof",
            "subnet":"10.233.1.0/24", "blocked_networks":["192.168.0.0/16"],
            "pi_host":"10.44.0.7", "pi_port":23001, "gateway_address":"10.44.0.1",
            "model_proxy_origin":"http://10.44.0.3:8317"});
        assert!(request(value.to_string().as_bytes()).is_ok());
        value["spec"]["pi_port"] = "23001".into();
        assert!(request(value.to_string().as_bytes()).is_err());
    }

    #[test]
    fn forwards_the_project_identity_without_accepting_untyped_values() {
        let mut value = base();
        value["operation"] = "provision".into();
        value["spec"] = json!({"images":{"operator":"a".repeat(64)}, "pool":"proof",
            "subnet":"10.233.1.0/24", "blocked_networks":["192.168.0.0/16"],
            "project_slug":"dlf"});
        let forwarded: serde_json::Value = serde_json::from_slice(
            &request(value.to_string().as_bytes()).expect("Project identity must reach the host"),
        )
        .unwrap();
        assert_eq!(forwarded["spec"]["project_slug"], "dlf");
        for invalid in [json!(true), json!(7), json!({"slug":"dlf"}), json!(["dlf"])] {
            value["spec"]["project_slug"] = invalid;
            assert!(request(value.to_string().as_bytes()).is_err());
        }
    }

    #[test]
    fn forwards_only_the_closed_project_bootstrap_descriptor() {
        let mut value = base();
        value["operation"] = "provision".into();
        value["spec"] = json!({"images":{"operator":"a".repeat(64)}, "pool":"proof",
            "subnet":"10.233.1.0/24", "blocked_networks":["192.168.0.0/16"], "project_slug":"dlf",
            "project_bootstrap":{"ssh_host":"10.44.0.20", "ssh_port":24001,
                "gateway_address":"10.44.0.2", "wireguard_address":"93.184.216.35", "wireguard_port":51820}});
        let forwarded: serde_json::Value = serde_json::from_slice(
            &request(value.to_string().as_bytes()).expect("bootstrap intent must reach the host"),
        )
        .unwrap();
        assert_eq!(
            forwarded["spec"]["project_bootstrap"],
            value["spec"]["project_bootstrap"]
        );
        for (field, invalid) in [
            ("ssh_port", json!(65536)),
            ("ssh_port", json!("24001")),
            ("wireguard_port", json!(-1)),
            ("wireguard_port", json!(true)),
            ("command", json!("whoami")),
        ] {
            let mut bad = value.clone();
            bad["spec"]["project_bootstrap"][field] = invalid;
            assert!(request(bad.to_string().as_bytes()).is_err());
        }
        value["spec"]["project_bootstrap"]
            .as_object_mut()
            .unwrap()
            .remove("ssh_host");
        assert!(request(value.to_string().as_bytes()).is_err());
    }

    #[test]
    fn accepts_only_a_closed_typed_source_template() {
        let mut value = base();
        value["operation"] = "provision".into();
        value["spec"] = json!({"images":{"operator":"a".repeat(64)}, "pool":"proof",
            "subnet":"10.233.1.0/24", "blocked_networks":["192.168.0.0/16"],
            "source_template":{"id":"9862e1aa-605c-4b49-a65b-6cf0b3a96dfe",
                "repository":"https://github.com/acme/orbit.git", "base":"main", "commit":"b".repeat(40)}});
        assert!(request(value.to_string().as_bytes()).is_ok());
        value["spec"]["source_template"]["path"] = "/foreign".into();
        assert!(request(value.to_string().as_bytes()).is_err());
        value["spec"]["source_template"] = "not a descriptor".into();
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
