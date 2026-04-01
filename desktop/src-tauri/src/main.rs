mod commands;
mod db;
mod keychain;
mod models;

use commands::AppState;
use std::collections::HashMap;
use std::sync::Mutex;

fn main() {
    tauri::Builder::default()
        .plugin(tauri_plugin_dialog::init())
        .manage(AppState {
            tunnels: Mutex::new(HashMap::new()),
        })
        .invoke_handler(tauri::generate_handler![
            commands::list_saved_sources,
            commands::save_source,
            commands::get_sidebar_preference,
            commands::set_sidebar_preference,
            commands::list_databases,
            commands::list_tables,
            commands::open_table,
            commands::update_cell,
            commands::insert_row,
            commands::delete_row,
            commands::export_table_csv,
            commands::import_table_csv,
            commands::add_column,
            commands::healthcheck_mysql_backend,
        ])
        .run(tauri::generate_context!())
        .expect("failed to run Herd Studio Desktop");
}
