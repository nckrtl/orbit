// The shell is intentionally thin: it renders the Orbit web app and will host the
// native bridge (CA trust, local DNS) as Tauri commands once those flows settle
// in the browser.
#![cfg_attr(not(debug_assertions), windows_subsystem = "windows")]

fn main() {
    tauri::Builder::default()
        .run(tauri::generate_context!())
        .expect("error while running the Orbit desktop app");
}
