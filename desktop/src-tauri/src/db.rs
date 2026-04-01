use crate::keychain;
use crate::models::{SavedSource, SidebarPreference, SidebarTableReference, SourceInput};
use rusqlite::{params, Connection, OptionalExtension, Result};
use std::fs;
use std::path::PathBuf;
use tauri::{AppHandle, Manager};
use uuid::Uuid;

pub fn open_connection(app_handle: &AppHandle) -> Result<Connection> {
    let path = database_path(app_handle)?;
    let connection = Connection::open(path)?;
    migrate(&connection)?;
    migrate_legacy_passwords(&connection)
        .map_err(|error| rusqlite::Error::ToSqlConversionFailure(Box::new(std::io::Error::other(error))))?;
    seed_default_source(&connection)?;
    Ok(connection)
}

pub fn list_sources(connection: &Connection) -> Result<Vec<SavedSource>> {
    let mut statement = connection.prepare(
        "
        select
            id, name, kind, host_label, ssh_host, ssh_port, ssh_username,
            private_key_path, database_host, database_port, database_name,
            database_username, has_database_password, database_password
        from saved_sources
        order by
            case when kind = 'local' then 0 else 1 end,
            name asc
        ",
    )?;

    let rows = statement.query_map([], hydrate_source)?;

    rows.collect::<Result<Vec<_>, _>>()
}

pub fn find_source(connection: &Connection, id: &str) -> Result<SavedSource> {
    connection.query_row(
        "
        select
            id, name, kind, host_label, ssh_host, ssh_port, ssh_username,
            private_key_path, database_host, database_port, database_name,
            database_username, has_database_password, database_password
        from saved_sources
        where id = ?1
        ",
        params![id],
        hydrate_source,
    )
}

pub fn upsert_source(connection: &Connection, input: SourceInput) -> Result<SavedSource> {
    let id = Uuid::new_v4().to_string();
    let host_label = match input.kind.as_str() {
        "ssh" => format!(
            "{}@{}",
            input.ssh_username.clone().unwrap_or_else(|| "forge".to_string()),
            input.ssh_host.clone().unwrap_or_else(|| "unknown".to_string())
        ),
        _ => format!("{}:{}", input.database_host, input.database_port),
    };

    connection.execute(
        "
        insert into saved_sources (
            id, name, kind, host_label, ssh_host, ssh_port, ssh_username,
            private_key_path, database_host, database_port, database_name,
            database_username, has_database_password, database_password
        ) values (?1, ?2, ?3, ?4, ?5, ?6, ?7, ?8, ?9, ?10, ?11, ?12, ?13, null)
        ",
        params![
            id,
            input.name,
            input.kind,
            host_label,
            input.ssh_host,
            input.ssh_port.map(i64::from),
            input.ssh_username,
            input.private_key_path,
            input.database_host,
            i64::from(input.database_port),
            input.database_name,
            input.database_username,
            !input.database_password.clone().unwrap_or_default().is_empty(),
        ],
    )?;

    if let Some(password) = input.database_password.filter(|value| !value.is_empty()) {
        keychain::save_database_password(&id, &password)
            .map_err(|error| rusqlite::Error::ToSqlConversionFailure(Box::new(std::io::Error::other(error))))?;
    }

    find_source(connection, &id)
}

pub fn get_sidebar_preference(connection: &Connection) -> Result<SidebarPreference> {
    connection.query_row(
        "select recent_expanded, pinned_tables, recent_tables from sidebar_preferences where id = 1",
        [],
        |row| {
            let pinned_tables_json: String = row.get(1)?;
            let recent_tables_json: String = row.get(2)?;

            Ok(SidebarPreference {
                recent_expanded: row.get::<_, i64>(0)? == 1,
                pinned_tables: serde_json::from_str(&pinned_tables_json).unwrap_or_default(),
                recent_tables: serde_json::from_str(&recent_tables_json).unwrap_or_default(),
            })
        },
    )
}

pub fn set_sidebar_preference(connection: &Connection, preference: &SidebarPreference) -> Result<()> {
    connection.execute(
        "
        update sidebar_preferences
        set recent_expanded = ?1, pinned_tables = ?2, recent_tables = ?3
        where id = 1
        ",
        params![
            if preference.recent_expanded { 1 } else { 0 },
            serialize_sidebar_tables(&preference.pinned_tables),
            serialize_sidebar_tables(&preference.recent_tables),
        ],
    )?;

    Ok(())
}

fn migrate(connection: &Connection) -> Result<()> {
    connection.execute_batch(
        "
        create table if not exists saved_sources (
            id text primary key,
            name text not null,
            kind text not null,
            host_label text not null,
            ssh_host text null,
            ssh_port integer null,
            ssh_username text null,
            private_key_path text null,
            database_host text not null,
            database_port integer not null,
            database_name text null,
            database_username text not null,
            has_database_password integer not null default 0,
            database_password text null
        );

        create table if not exists sidebar_preferences (
            id integer primary key check (id = 1),
            recent_expanded integer not null default 0,
            pinned_tables text not null default '[]',
            recent_tables text not null default '[]'
        );
        ",
    )?;

    add_column_if_missing(
        connection,
        "saved_sources",
        "has_database_password",
        "integer not null default 0",
    )?;
    add_column_if_missing(
        connection,
        "sidebar_preferences",
        "pinned_tables",
        "text not null default '[]'",
    )?;
    add_column_if_missing(
        connection,
        "sidebar_preferences",
        "recent_tables",
        "text not null default '[]'",
    )?;

    connection.execute(
        "
        insert or ignore into sidebar_preferences (id, recent_expanded, pinned_tables, recent_tables)
        values (1, 0, '[]', '[]')
        ",
        params![],
    )?;

    Ok(())
}

fn seed_default_source(connection: &Connection) -> Result<()> {
    let existing_local: Option<String> = connection
        .query_row(
            "select id from saved_sources where kind = 'local' limit 1",
            [],
            |row| row.get(0),
        )
        .optional()?;

    if existing_local.is_none() {
        connection.execute(
            "
            insert into saved_sources (
                id, name, kind, host_label, database_host, database_port,
                database_name, database_username, has_database_password, database_password
            ) values (?1, ?2, 'local', ?3, ?4, ?5, null, ?6, 0, null)
            ",
            params![
                "local-herd",
                "Local Herd",
                "127.0.0.1 · Herd MySQL",
                "127.0.0.1",
                3306_i64,
                "root",
            ],
        )?;
    }

    Ok(())
}

fn hydrate_source(row: &rusqlite::Row<'_>) -> Result<SavedSource> {
    Ok(SavedSource {
        id: row.get(0)?,
        name: row.get(1)?,
        kind: row.get(2)?,
        host_label: row.get(3)?,
        ssh_host: row.get(4)?,
        ssh_port: row.get::<_, Option<i64>>(5)?.map(|value| value as u16),
        ssh_username: row.get(6)?,
        private_key_path: row.get(7)?,
        database_host: row.get(8)?,
        database_port: row.get::<_, i64>(9)? as u16,
        database_name: row.get(10)?,
        database_username: row.get(11)?,
        has_database_password: row.get::<_, i64>(12)? == 1,
        database_password: row.get(13)?,
    })
}

fn database_path(app_handle: &AppHandle) -> Result<PathBuf> {
    let mut path = app_handle
        .path()
        .app_data_dir()
        .map_err(|error| rusqlite::Error::ToSqlConversionFailure(Box::new(error)))?;

    fs::create_dir_all(&path)
        .map_err(|error| rusqlite::Error::ToSqlConversionFailure(Box::new(error)))?;
    path.push("desktop-state.sqlite");

    Ok(path)
}

fn add_column_if_missing(
    connection: &Connection,
    table: &str,
    column: &str,
    definition: &str,
) -> Result<()> {
    let mut statement = connection.prepare(&format!("pragma table_info({table})"))?;
    let columns = statement.query_map([], |row| row.get::<_, String>(1))?;
    let exists = columns
        .collect::<Result<Vec<_>, _>>()?
        .into_iter()
        .any(|existing| existing == column);

    if !exists {
        connection.execute(
            &format!("alter table {table} add column {column} {definition}"),
            [],
        )?;
    }

    Ok(())
}

fn serialize_sidebar_tables(items: &[SidebarTableReference]) -> String {
    serde_json::to_string(items).unwrap_or_else(|_| "[]".to_string())
}

fn migrate_legacy_passwords(connection: &Connection) -> std::result::Result<(), String> {
    let mut statement = connection
        .prepare(
            "
            select id, database_password
            from saved_sources
            where coalesce(database_password, '') <> ''
            ",
        )
        .map_err(|error| error.to_string())?;
    let legacy_rows = statement
        .query_map([], |row| Ok((row.get::<_, String>(0)?, row.get::<_, String>(1)?)))
        .map_err(|error| error.to_string())?
        .collect::<Result<Vec<_>, _>>()
        .map_err(|error| error.to_string())?;

    for (source_id, password) in legacy_rows {
        keychain::save_database_password(&source_id, &password)?;
        connection
            .execute(
                "
                update saved_sources
                set has_database_password = 1,
                    database_password = null
                where id = ?1
                ",
                params![source_id],
            )
            .map_err(|error| error.to_string())?;
    }

    Ok(())
}
