use bollard::{container::ListContainersOptions, system::EventsOptions, Docker};
use futures_util::{SinkExt, StreamExt};
use orbit_agent::{
    docker_container_name, docker_status, frame, gateway_client, iso_now, process_unit_name,
    retry_delay, snapshot_frames, tls_config, ChangeBatch, Config, Envelope, HeartbeatData,
    Sequencer, Unit, CHANGE_MERGE_WINDOW,
};
use serde::Deserialize;
use serde_json::{json, Value};
use std::{collections::HashMap, error::Error, sync::Arc, time::Duration};
use tokio::{
    net::TcpStream,
    sync::{mpsc, watch},
    time::{Instant, Interval},
};
use tokio_tungstenite::{
    client_async_tls_with_config,
    tungstenite::{client::IntoClientRequest, Message},
    Connector, MaybeTlsStream, WebSocketStream,
};
use zbus::{
    message::Type as MessageType, zvariant::OwnedObjectPath, Connection, MatchRule, MessageStream,
    Proxy,
};

const SYSTEMD_DEST: &str = "org.freedesktop.systemd1";
const SYSTEMD_PATH: &str = "/org/freedesktop/systemd1";
const VERSION: &str = env!("CARGO_PKG_VERSION");
type Socket = WebSocketStream<MaybeTlsStream<tokio::net::TcpStream>>;

#[derive(Deserialize)]
struct DiscoveryEnvelope {
    data: Discovery,
}
#[derive(Deserialize)]
struct Discovery {
    url: Option<String>,
    address: Option<String>,
    key: Option<String>,
    channel: String,
    member: String,
}
#[derive(Deserialize)]
struct Auth {
    auth: String,
    channel_data: String,
}
#[derive(Debug)]
enum DockerUpdate {
    Connected(Vec<Unit>),
    Changed(Unit),
    Absent,
}

#[tokio::main]
async fn main() {
    if let Err(error) = run().await {
        eprintln!("orbit-agent: {error}");
        std::process::exit(1);
    }
}

async fn run() -> Result<(), Box<dyn Error + Send + Sync>> {
    let config = Config::load()?;
    let client = gateway_client(config.gateway_address)?;
    let tls = tls_config()?;
    let connection = Connection::system().await?;
    let manager = Proxy::new(
        &connection,
        SYSTEMD_DEST,
        SYSTEMD_PATH,
        "org.freedesktop.systemd1.Manager",
    )
    .await?;
    // Subscribe is required by systemd before it emits manager/unit change signals.
    manager.call::<_, _, ()>("Subscribe", &()).await?;
    let (systemd_tx, mut systemd_rx) = mpsc::unbounded_channel::<()>();
    let signal_connection = connection.clone();
    tokio::spawn(async move {
        if let Err(error) = watch_systemd_events(signal_connection, systemd_tx.clone()).await {
            eprintln!("orbit-agent: systemd D-Bus signal watcher failed: {error}");
            std::process::exit(1);
        }
    });
    let (docker_tx, mut docker_rx) = mpsc::unbounded_channel();
    tokio::spawn(docker_watcher(docker_tx));
    let (shutdown_tx, mut shutdown_rx) = watch::channel(false);
    tokio::spawn(async move {
        wait_for_os_shutdown().await;
        let _ = shutdown_tx.send(true);
    });
    let mut sequence = Sequencer::default();
    let mut attempt = 0u32;
    let mut docker_units = HashMap::new();
    let mut docker_available = false;
    let mut systemd = systemd_snapshot(&manager).await?;
    let mut heartbeat = tokio::time::interval(Duration::from_secs(5));
    let mut systemd_poll = tokio::time::interval(Duration::from_secs(30));
    let mut realtime_poll = tokio::time::interval(Duration::from_secs(60));
    loop {
        if *shutdown_rx.borrow() {
            return Ok(());
        }
        let discovery = match discover(&client, &config.gateway_url).await {
            Ok(d) => d,
            Err(error) => {
                eprintln!("orbit-agent: realtime discovery failed: {error}");
                tokio::select! { _=shutdown_rx.changed()=>return Ok(()), _=tokio::time::sleep(retry_delay(attempt))=>{} }
                attempt = attempt.saturating_add(1);
                continue;
            }
        };
        if !discovery
            .member
            .strip_prefix("agent.")
            .is_some_and(|id| discovery.channel == format!("presence-node.{id}"))
        {
            return Err("Gateway returned an invalid agent channel identity".into());
        }
        let (Some(url), Some(address), Some(key)) =
            (discovery.url, discovery.address, discovery.key)
        else {
            tokio::select! {_=shutdown_rx.changed()=>return Ok(()),_=realtime_poll.tick()=>{}}
            continue;
        };
        match connected_session(
            &client,
            &config.gateway_url,
            &url,
            &address,
            &key,
            &discovery.channel,
            tls.clone(),
            &manager,
            &mut sequence,
            &mut systemd,
            &mut docker_units,
            &mut docker_available,
            &mut docker_rx,
            &mut systemd_rx,
            &mut heartbeat,
            &mut systemd_poll,
            &mut shutdown_rx,
        )
        .await
        {
            Ok(()) => return Ok(()),
            Err(error) => {
                eprintln!("orbit-agent: realtime connection ended: {error}");
                attempt = attempt.saturating_add(1);
                tokio::select! {_=shutdown_rx.changed()=>return Ok(()),_=tokio::time::sleep(retry_delay(attempt))=>{}}
            }
        }
    }
}

async fn wait_for_os_shutdown() {
    #[cfg(unix)]
    {
        use tokio::signal::unix::{signal, SignalKind};
        let Ok(mut terminate) = signal(SignalKind::terminate()) else {
            let _ = tokio::signal::ctrl_c().await;
            return;
        };
        tokio::select! {_=tokio::signal::ctrl_c()=>{},_=terminate.recv()=>{}}
    }
    #[cfg(not(unix))]
    {
        let _ = tokio::signal::ctrl_c().await;
    }
}
async fn discover(
    client: &reqwest::Client,
    gateway: &str,
) -> Result<Discovery, Box<dyn Error + Send + Sync>> {
    Ok(client
        .get(format!(
            "{}/api/v1/agent/realtime",
            gateway.trim_end_matches('/')
        ))
        .send()
        .await?
        .error_for_status()?
        .json::<DiscoveryEnvelope>()
        .await?
        .data)
}

#[allow(clippy::too_many_arguments)]
async fn connected_session(
    client: &reqwest::Client,
    gateway: &str,
    ws_url: &str,
    ws_address: &str,
    key: &str,
    channel: &str,
    tls: Arc<rustls::ClientConfig>,
    manager: &Proxy<'_>,
    sequence: &mut Sequencer,
    systemd: &mut HashMap<String, Unit>,
    docker_units: &mut HashMap<String, Unit>,
    docker_available: &mut bool,
    docker_rx: &mut mpsc::UnboundedReceiver<DockerUpdate>,
    systemd_rx: &mut mpsc::UnboundedReceiver<()>,
    heartbeat: &mut Interval,
    systemd_poll: &mut Interval,
    shutdown: &mut watch::Receiver<bool>,
) -> Result<(), Box<dyn Error + Send + Sync>> {
    let mut parsed = reqwest::Url::parse(ws_url)?;
    if parsed.scheme() != "wss"
        || parsed.host_str() != Some("reverb.orbit")
        || parsed.port_or_known_default() != Some(443)
    {
        return Err("realtime discovery must use wss://reverb.orbit".into());
    }
    parsed.set_path(&format!("/app/{key}"));
    parsed.set_query(Some(&format!(
        "protocol=7&client=orbit-agent&version={VERSION}&flash=false"
    )));
    let stream = connect_by_address(ws_address.parse::<std::net::IpAddr>()?, 443).await?;
    let request = parsed.as_str().into_client_request()?;
    let (socket, _) =
        client_async_tls_with_config(request, stream, None, Some(Connector::Rustls(tls))).await?;
    let auth_url = format!(
        "{}/api/v1/agent/broadcasting/auth",
        gateway.trim_end_matches('/')
    );
    let mut socket = protocol_handshake(socket, client, &auth_url, channel, VERSION).await?;
    // Re-read both runtimes for every successful join.
    *systemd = match systemd_snapshot(manager).await {
        Ok(units) => units,
        Err(error) => {
            eprintln!("orbit-agent: systemd D-Bus watcher failed: {error}");
            std::process::exit(1);
        }
    };
    let units = combined_units(systemd, docker_units);
    send_snapshots(
        &mut socket,
        channel,
        sequence,
        &units,
        if *docker_available {
            "available"
        } else {
            "absent"
        },
    )
    .await?;
    let mut pending = ChangeBatch::default();
    let debounce = tokio::time::sleep(Duration::from_secs(86400));
    tokio::pin!(debounce);
    loop {
        tokio::select! {
            _=shutdown.changed()=>{socket.close(None).await?;return Ok(())},
            _=heartbeat.tick()=>{let payload=Envelope{sequence:sequence.advance(),at:iso_now(),data:HeartbeatData{}};send_client(&mut socket,channel,"client-heartbeat",serde_json::to_value(payload)?).await?;},
            signal=systemd_rx.recv()=>match signal {
                Some(())=>{
                    let fresh=match systemd_snapshot(manager).await{Ok(units)=>units,Err(error)=>{eprintln!("orbit-agent: systemd D-Bus watcher failed: {error}");std::process::exit(1)}};
                    if let Some(deadline)=queue_changes(&mut pending,diff_units(systemd,&fresh)){debounce.as_mut().reset(deadline);} *systemd=fresh;
                },
                None=>{eprintln!("orbit-agent: systemd D-Bus signal watcher stopped");std::process::exit(1)},
            },
            _=systemd_poll.tick()=>{
                let fresh=match systemd_snapshot(manager).await{Ok(units)=>units,Err(error)=>{eprintln!("orbit-agent: systemd D-Bus safety poll failed: {error}");std::process::exit(1)}};
                if let Some(deadline)=queue_changes(&mut pending,diff_units(systemd,&fresh)){debounce.as_mut().reset(deadline);} *systemd=fresh;
            },
            update=docker_rx.recv()=>match update {
                Some(DockerUpdate::Connected(units))=>{docker_units.clear();docker_units.extend(units.into_iter().map(|unit|(unit.name.clone(),unit)));*docker_available=true;let all=combined_units(systemd,docker_units);send_snapshots(&mut socket,channel,sequence,&all,"available").await?;},
                Some(DockerUpdate::Absent)=>{if *docker_available{*docker_available=false;docker_units.clear();let all=combined_units(systemd,docker_units);send_snapshots(&mut socket,channel,sequence,&all,"absent").await?;}},
                Some(DockerUpdate::Changed(unit)) if docker_units.get(&unit.name)!=Some(&unit)=>{docker_units.insert(unit.name.clone(),unit.clone());if let Some(deadline)=queue_changes(&mut pending,[unit]){debounce.as_mut().reset(deadline);}},
                Some(DockerUpdate::Changed(_))=>{},
                None=>{},
            },
            _=&mut debounce,if !pending.is_empty()=>{if pending.due(Instant::now()){for unit in pending.take(){let event=orbit_agent::process_frame(channel,sequence.advance(),unit)?;send_raw_frame(&mut socket,event).await?;}}},
            message=socket.next()=>match message{
                Some(Ok(Message::Text(text)))=>{let value:Value=serde_json::from_str(&text)?;match value["event"].as_str().unwrap_or(""){
                    "pusher:ping"=>send_control(&mut socket,"pusher:pong",json!({})).await?,
                    "pusher:error"|"pusher_internal:subscription_error"=>return Err(format!("Pusher rejected subscription: {}",value["data"]).into()),
                    "pusher_internal:member_added"=>{let all=combined_units(systemd,docker_units);send_snapshots(&mut socket,channel,sequence,&all,if *docker_available{"available"}else{"absent"}).await?;},
                    _=>{}
                }},
                Some(Ok(Message::Ping(data)))=>socket.send(Message::Pong(data)).await?,
                Some(Ok(Message::Close(_)))|None=>return Err("Pusher closed connection".into()),
                Some(Err(error))=>return Err(error.into()),_=>{}
            }
        }
    }
}

async fn connect_by_address(
    address: std::net::IpAddr,
    port: u16,
) -> Result<TcpStream, std::io::Error> {
    TcpStream::connect(std::net::SocketAddr::new(address, port)).await
}

async fn protocol_handshake(
    mut socket: Socket,
    client: &reqwest::Client,
    auth_url: &str,
    channel: &str,
    version: &str,
) -> Result<Socket, Box<dyn Error + Send + Sync>> {
    let established = loop {
        let message = socket
            .next()
            .await
            .ok_or("WebSocket closed before connection_established")??;
        if let Message::Text(text) = message {
            let value: Value = serde_json::from_str(&text)?;
            if value["event"] == "pusher:error" {
                return Err("Pusher connection error".into());
            }
            if value["event"] == "pusher:connection_established" {
                break value;
            }
        }
    };
    let data: Value = serde_json::from_str(
        established["data"]
            .as_str()
            .ok_or("missing connection data")?,
    )?;
    let socket_id = data["socket_id"].as_str().ok_or("missing socket id")?;
    let auth: Auth = client
        .post(auth_url)
        .form(&[
            ("socket_id", socket_id),
            ("channel_name", channel),
            ("version", version),
        ])
        .send()
        .await?
        .error_for_status()?
        .json()
        .await?;
    send_control(
        &mut socket,
        "pusher:subscribe",
        json!({"channel":channel,"auth":auth.auth,"channel_data":auth.channel_data}),
    )
    .await?;
    loop {
        let message = socket
            .next()
            .await
            .ok_or("WebSocket closed before subscription_succeeded")??;
        match message {
            Message::Text(text) => {
                let value: Value = serde_json::from_str(&text)?;
                match value["event"].as_str().unwrap_or("") {
                    "pusher_internal:subscription_succeeded" => return Ok(socket),
                    "pusher:error" | "pusher_internal:subscription_error" => {
                        return Err(
                            format!("Pusher rejected subscription: {}", value["data"]).into()
                        )
                    }
                    "pusher:ping" => send_control(&mut socket, "pusher:pong", json!({})).await?,
                    _ => {}
                }
            }
            Message::Ping(data) => socket.send(Message::Pong(data)).await?,
            Message::Close(_) => return Err("Pusher closed before subscription_succeeded".into()),
            _ => {}
        }
    }
}

async fn send_snapshots(
    socket: &mut Socket,
    channel: &str,
    sequence: &mut Sequencer,
    units: &[Unit],
    docker: &'static str,
) -> Result<(), Box<dyn Error + Send + Sync>> {
    for event in snapshot_frames(channel, sequence, units, docker)? {
        send_raw_frame(socket, event).await?;
    }
    Ok(())
}
async fn send_client(
    socket: &mut Socket,
    channel: &str,
    event: &str,
    data: Value,
) -> Result<(), Box<dyn Error + Send + Sync>> {
    send_raw_frame(socket, frame(channel, event, data)).await
}
async fn send_control(
    socket: &mut Socket,
    event: &str,
    data: Value,
) -> Result<(), Box<dyn Error + Send + Sync>> {
    socket
        .send(Message::Text(
            json!({"event":event,"data":data}).to_string().into(),
        ))
        .await?;
    Ok(())
}
async fn send_raw_frame(
    socket: &mut Socket,
    event: orbit_agent::ClientFrame,
) -> Result<(), Box<dyn Error + Send + Sync>> {
    let text = serde_json::to_string(&event)?;
    if text.len() > orbit_agent::PUSHER_FRAME_LIMIT {
        return Err("Pusher client event exceeded the frame size limit".into());
    }
    socket.send(Message::Text(text.into())).await?;
    Ok(())
}
fn queue_changes(
    batch: &mut ChangeBatch,
    changes: impl IntoIterator<Item = Unit>,
) -> Option<Instant> {
    let now = Instant::now();
    let mut started = false;
    for unit in changes {
        started |= batch.push(unit, now);
    }
    started.then_some(now + CHANGE_MERGE_WINDOW)
}

fn combined_units(systemd: &HashMap<String, Unit>, docker: &HashMap<String, Unit>) -> Vec<Unit> {
    let mut all = systemd
        .values()
        .chain(docker.values())
        .cloned()
        .collect::<Vec<_>>();
    all.sort();
    all
}
fn diff_units(old: &HashMap<String, Unit>, new: &HashMap<String, Unit>) -> Vec<Unit> {
    let mut changes = new
        .iter()
        .filter(|(name, unit)| old.get(*name) != Some(*unit))
        .map(|(_, unit)| unit.clone())
        .collect::<Vec<_>>();
    for (name, unit) in old {
        if !new.contains_key(name) {
            let mut inactive = unit.clone();
            inactive.runtime_status = if unit.runtime == "systemd" {
                "inactive"
            } else {
                "exited"
            }
            .into();
            changes.push(inactive);
        }
    }
    changes
}

async fn watch_systemd_events(
    connection: Connection,
    tx: mpsc::UnboundedSender<()>,
) -> Result<(), Box<dyn Error + Send + Sync>> {
    let properties_rule = MatchRule::builder()
        .msg_type(MessageType::Signal)
        .sender(SYSTEMD_DEST)?
        .interface("org.freedesktop.DBus.Properties")?
        .member("PropertiesChanged")?
        .path_namespace("/org/freedesktop/systemd1/unit")?
        .add_arg("org.freedesktop.systemd1.Unit")?
        .build();
    let unit_new_rule = MatchRule::builder()
        .msg_type(MessageType::Signal)
        .sender(SYSTEMD_DEST)?
        .interface("org.freedesktop.systemd1.Manager")?
        .member("UnitNew")?
        .path(SYSTEMD_PATH)?
        .build();
    let unit_removed_rule = MatchRule::builder()
        .msg_type(MessageType::Signal)
        .sender(SYSTEMD_DEST)?
        .interface("org.freedesktop.systemd1.Manager")?
        .member("UnitRemoved")?
        .path(SYSTEMD_PATH)?
        .build();
    let mut properties =
        MessageStream::for_match_rule(properties_rule, &connection, Some(64)).await?;
    let mut unit_new = MessageStream::for_match_rule(unit_new_rule, &connection, Some(16)).await?;
    let mut unit_removed =
        MessageStream::for_match_rule(unit_removed_rule, &connection, Some(16)).await?;
    loop {
        let message = tokio::select! {
            message = properties.next() => message,
            message = unit_new.next() => message,
            message = unit_removed.next() => message,
        };
        match message {
            Some(Ok(_)) => {
                if tx.send(()).is_err() {
                    return Ok(());
                }
            }
            Some(Err(error)) => return Err(error.into()),
            None => return Err("systemd D-Bus signal stream ended".into()),
        }
    }
}

async fn systemd_snapshot(
    manager: &Proxy<'_>,
) -> Result<HashMap<String, Unit>, Box<dyn Error + Send + Sync>> {
    type Row = (
        String,
        String,
        String,
        String,
        String,
        String,
        OwnedObjectPath,
        u32,
        String,
        OwnedObjectPath,
    );
    let rows: Vec<Row> = manager.call("ListUnits", &()).await?;
    Ok(rows
        .into_iter()
        .filter_map(|(unit_name, _, _, active, _, _, _, _, _, _)| {
            process_unit_name(&unit_name).map(|name| {
                let unit = Unit {
                    name: name.clone(),
                    runtime: "systemd".into(),
                    runtime_status: active,
                };
                (name, unit)
            })
        })
        .collect())
}
async fn docker_watcher(tx: mpsc::UnboundedSender<DockerUpdate>) {
    let mut available = false;
    loop {
        if !std::path::Path::new("/run/docker.sock").exists() {
            if available {
                let _ = tx.send(DockerUpdate::Absent);
                available = false;
            }
            tokio::time::sleep(Duration::from_secs(10)).await;
            continue;
        }
        let Ok(docker) = Docker::connect_with_unix_defaults() else {
            if available {
                let _ = tx.send(DockerUpdate::Absent);
                available = false;
            }
            tokio::time::sleep(Duration::from_secs(10)).await;
            continue;
        };
        let options = Some(ListContainersOptions::<String> {
            all: true,
            ..Default::default()
        });
        let Ok(containers) = docker.list_containers(options).await else {
            if available {
                let _ = tx.send(DockerUpdate::Absent);
                available = false;
            }
            tokio::time::sleep(Duration::from_secs(10)).await;
            continue;
        };
        let snapshot = containers
            .into_iter()
            .filter_map(|container| {
                let name = docker_container_name(container.names.as_deref().unwrap_or_default())?;
                Some(Unit {
                    name,
                    runtime: "docker".into(),
                    runtime_status: docker_status(container.state.as_deref().unwrap_or("exited"))
                        .into(),
                })
            })
            .collect();
        let _ = tx.send(DockerUpdate::Connected(snapshot));
        available = true;
        let filters = HashMap::from([("type".to_string(), vec!["container".to_string()])]);
        let mut stream = docker.events(Some(EventsOptions::<String> {
            filters,
            ..Default::default()
        }));
        while let Some(message) = stream.next().await {
            let Ok(event) = message else {
                break;
            };
            if event.typ != Some(bollard::models::EventMessageTypeEnum::CONTAINER) {
                continue;
            }
            let Some(actor) = event.actor else {
                continue;
            };
            let Some(attrs) = actor.attributes else {
                continue;
            };
            let Some(name) = attrs.get("name").and_then(|name| process_unit_name(name)) else {
                continue;
            };
            let id = actor.id.unwrap_or_else(|| name.clone());
            let status = match docker.inspect_container(&id, None).await {
                Ok(container) => container
                    .state
                    .and_then(|state| state.status)
                    .map(|state| docker_status(state.as_ref()).to_owned())
                    .unwrap_or_else(|| "exited".into()),
                Err(_) => "exited".into(),
            };
            let _ = tx.send(DockerUpdate::Changed(Unit {
                name,
                runtime: "docker".into(),
                runtime_status: status,
            }));
        }
        if available {
            let _ = tx.send(DockerUpdate::Absent);
            available = false;
        }
        tokio::time::sleep(Duration::from_secs(10)).await;
    }
}

#[cfg(test)]
mod protocol_tests {
    use super::*;
    use axum::{
        extract::{
            ws::{Message as WsMessage, WebSocket, WebSocketUpgrade},
            State,
        },
        response::IntoResponse,
        routing::{get, post},
        Json, Router,
    };
    use std::sync::{
        atomic::{AtomicUsize, Ordering},
        Arc,
    };
    use tokio_tungstenite::connect_async;
    #[derive(Clone)]
    struct FakeState {
        auths: Arc<AtomicUsize>,
        subscriptions: Arc<AtomicUsize>,
    }
    async fn auth(State(state): State<FakeState>) -> Json<Value> {
        state.auths.fetch_add(1, Ordering::SeqCst);
        Json(json!({"auth":"signed","channel_data":"{\"user_id\":\"agent.12\"}"}))
    }
    async fn ws_route(
        State(state): State<FakeState>,
        upgrade: WebSocketUpgrade,
    ) -> impl IntoResponse {
        upgrade.on_upgrade(move |socket| fake_pusher(socket, state))
    }
    async fn fake_pusher(mut socket: WebSocket, state: FakeState) {
        let established = json!({"event":"pusher:connection_established","data":json!({"socket_id":"1.2"}).to_string()}).to_string();
        let _ = socket.send(WsMessage::Text(established.into())).await;
        if let Some(Ok(WsMessage::Text(text))) = socket.recv().await {
            let sub: Value = serde_json::from_str(&text).unwrap();
            assert_eq!(sub["event"], "pusher:subscribe");
            let data: Value = match &sub["data"] {
                Value::String(value) => serde_json::from_str(value).unwrap(),
                value => value.clone(),
            };
            assert_eq!(data["channel"], "presence-node.12");
            assert_eq!(data["channel_data"], "{\"user_id\":\"agent.12\"}");
            state.subscriptions.fetch_add(1, Ordering::SeqCst);
        }
        let subscribed = json!({"event":"pusher_internal:subscription_succeeded","channel":"presence-node.12","data":{}}).to_string();
        let _ = socket.send(WsMessage::Text(subscribed.into())).await;
        if let Some(Ok(WsMessage::Text(text))) = socket.recv().await {
            let frame: Value = serde_json::from_str(&text).unwrap();
            assert_eq!(frame["event"], "client-snapshot");
            assert_eq!(frame["channel"], "presence-node.12");
            assert!(frame["data"].is_object());
            let _ = socket.send(WsMessage::Text(text)).await;
        }
        let _ = socket
            .send(WsMessage::Text(
                json!({"event":"pusher:ping","data":{}}).to_string().into(),
            ))
            .await;
        if let Some(Ok(WsMessage::Text(text))) = socket.recv().await {
            let value: Value = serde_json::from_str(&text).unwrap();
            if value["event"] == "pusher:pong" {
                let _ = socket.send(WsMessage::Close(None)).await;
            }
        }
    }
    async fn fake_server() -> (String, FakeState) {
        let state = FakeState {
            auths: Arc::new(AtomicUsize::new(0)),
            subscriptions: Arc::new(AtomicUsize::new(0)),
        };
        let app = Router::new()
            .route("/socket", get(ws_route))
            .route("/auth", post(auth))
            .with_state(state.clone());
        let listener = tokio::net::TcpListener::bind("127.0.0.1:0").await.unwrap();
        let addr = listener.local_addr().unwrap();
        tokio::spawn(async move {
            axum::serve(listener, app).await.unwrap();
        });
        (format!("http://{addr}"), state)
    }
    #[tokio::test]
    async fn opens_tcp_connections_to_literal_addresses_without_dns() {
        let listener = tokio::net::TcpListener::bind("127.0.0.1:0").await.unwrap();
        let address = listener.local_addr().unwrap();
        let accept = tokio::spawn(async move { listener.accept().await.unwrap().0 });

        let stream = connect_by_address(address.ip(), address.port())
            .await
            .unwrap();

        assert!(stream.peer_addr().unwrap().ip().is_loopback());
        drop(stream);
        drop(accept.await.unwrap());
    }

    #[tokio::test]
    async fn fake_pusher_auth_subscribe_events_and_reconnect() {
        let (base, state) = fake_server().await;
        let http = reqwest::Client::new();
        for _ in 0..2 {
            let (socket, _) =
                connect_async(format!("{}/socket", base.replacen("http://", "ws://", 1)))
                    .await
                    .unwrap();
            let mut socket = protocol_handshake(
                socket,
                &http,
                &format!("{base}/auth"),
                "presence-node.12",
                VERSION,
            )
            .await
            .unwrap();
            let frame = frame(
                "presence-node.12",
                "client-snapshot",
                json!({"sequence":1,"at":"now","docker":"absent","part":1,"parts":1,"units":[]}),
            );
            send_raw_frame(&mut socket, frame).await.unwrap();
            let event = socket.next().await.unwrap().unwrap();
            let Message::Text(text) = event else {
                panic!("expected frame")
            };
            let parsed: Value = serde_json::from_str(&text).unwrap();
            assert_eq!(parsed["channel"], "presence-node.12");
            assert!(parsed["data"].is_object());
            let ping = socket.next().await.unwrap().unwrap();
            assert!(matches!(ping, Message::Text(_)));
            let p: Value = serde_json::from_str(ping.into_text().unwrap().as_str()).unwrap();
            assert_eq!(p["event"], "pusher:ping");
            send_control(&mut socket, "pusher:pong", json!({}))
                .await
                .unwrap();
        }
        assert_eq!(state.auths.load(Ordering::SeqCst), 2);
        assert_eq!(state.subscriptions.load(Ordering::SeqCst), 2);
    }
}
