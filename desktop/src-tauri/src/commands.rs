use crate::db;
use crate::keychain;
use crate::models::{
    AddColumnInput, CsvTransferInput, DatabaseItem, DeleteRowInput, OpenTableInput,
    RelatedPreviewField, RelatedRecordPreview, SavedSource, SidebarPreference, SourceInput,
    TableColumn, TableItem, TableView, UpdateCellInput,
};
use mysql::prelude::Queryable;
use mysql::{OptsBuilder, Pool, PooledConn, Row, Value as MySqlValue};
use serde_json::Value;
use std::collections::HashMap;
use std::net::{TcpListener, TcpStream};
use std::path::Path;
use std::process::{Child, Command, Stdio};
use std::sync::Mutex;
use std::time::Duration;
use tauri::{AppHandle, State};
use thiserror::Error;

pub struct AppState {
    pub tunnels: Mutex<HashMap<String, TunnelHandle>>,
}

pub struct TunnelHandle {
    pub port: u16,
    pub child: Child,
}

#[derive(Debug, Error)]
enum BackendError {
    #[error("{0}")]
    Message(String),
    #[error(transparent)]
    Sqlite(#[from] rusqlite::Error),
    #[error(transparent)]
    MySql(#[from] mysql::Error),
    #[error(transparent)]
    Io(#[from] std::io::Error),
}

type BackendResult<T> = Result<T, BackendError>;

#[tauri::command]
pub fn list_saved_sources(app_handle: AppHandle) -> Result<Vec<SavedSource>, String> {
    let connection = db::open_connection(&app_handle).map_err(stringify_error)?;
    db::list_sources(&connection).map_err(stringify_error)
}

#[tauri::command]
pub fn save_source(app_handle: AppHandle, input: SourceInput) -> Result<SavedSource, String> {
    let connection = db::open_connection(&app_handle).map_err(stringify_error)?;
    db::upsert_source(&connection, input).map_err(stringify_error)
}

#[tauri::command]
pub fn get_sidebar_preference(app_handle: AppHandle) -> Result<SidebarPreference, String> {
    let connection = db::open_connection(&app_handle).map_err(stringify_error)?;
    db::get_sidebar_preference(&connection).map_err(stringify_error)
}

#[tauri::command]
pub fn set_sidebar_preference(
    app_handle: AppHandle,
    preference: SidebarPreference,
) -> Result<(), String> {
    let connection = db::open_connection(&app_handle).map_err(stringify_error)?;
    db::set_sidebar_preference(&connection, &preference).map_err(stringify_error)
}

#[tauri::command]
pub fn list_databases(
    app_handle: AppHandle,
    source_id: String,
    state: State<'_, AppState>,
) -> Result<Vec<DatabaseItem>, String> {
    let connection = db::open_connection(&app_handle).map_err(stringify_error)?;
    let source = resolve_source(&connection, &source_id).map_err(stringify_error)?;
    let mut mysql = mysql_connection(&source, &state).map_err(stringify_error)?;
    let rows: Vec<(String, u64)> = mysql
        .query(
        "
        select table_schema, count(*) as table_count
        from information_schema.tables
        where table_type = 'BASE TABLE'
          and table_schema not in ('information_schema', 'mysql', 'performance_schema', 'sys')
        group by table_schema
        order by table_schema asc
        ",
    )
        .map_err(BackendError::from)
        .map_err(stringify_error)?;

    Ok(rows
        .into_iter()
        .map(|(name, tables)| DatabaseItem { name, tables })
        .collect())
}

#[tauri::command]
pub fn list_tables(
    app_handle: AppHandle,
    source_id: String,
    database: String,
    state: State<'_, AppState>,
) -> Result<Vec<TableItem>, String> {
    let connection = db::open_connection(&app_handle).map_err(stringify_error)?;
    let source = resolve_source(&connection, &source_id).map_err(stringify_error)?;
    let mut mysql = mysql_connection(&source, &state).map_err(stringify_error)?;
    let rows: Vec<(String, u64)> = mysql
        .exec(
        "
        select table_name, coalesce(table_rows, 0)
        from information_schema.tables
        where table_schema = ?
          and table_type = 'BASE TABLE'
        order by table_name asc
        ",
        (database.clone(),),
    )
        .map_err(BackendError::from)
        .map_err(stringify_error)?;

    Ok(rows
        .into_iter()
        .map(|(name, rows)| TableItem {
            database: database.clone(),
            name,
            rows,
        })
        .collect())
}

#[tauri::command]
pub fn open_table(
    app_handle: AppHandle,
    input: OpenTableInput,
    state: State<'_, AppState>,
) -> Result<TableView, String> {
    let connection = db::open_connection(&app_handle).map_err(stringify_error)?;
    let source = resolve_source(&connection, &input.source_id).map_err(stringify_error)?;
    let mut mysql = mysql_connection(&source, &state).map_err(stringify_error)?;
    let columns = load_columns(&mut mysql, &input.database, &input.table).map_err(stringify_error)?;
    let page = input.page.unwrap_or(1).max(1);
    let per_page = input.per_page.unwrap_or(100).clamp(1, 500);
    let total_rows = count_rows(
        &mut mysql,
        &input.database,
        &input.table,
        input.search.as_deref(),
    )
    .map_err(stringify_error)?;
    let rows = load_rows(
        &mut mysql,
        &input.database,
        &input.table,
        input.search.as_deref(),
        input.sort_column.as_deref(),
        input.sort_direction.as_deref(),
        page,
        per_page,
    )
    .map_err(stringify_error)?;
    let related_previews = load_related_previews(&mut mysql, &input.database, &columns, &rows)
        .map_err(stringify_error)?;
    let total_pages = if total_rows == 0 {
        1
    } else {
        ((total_rows as f64) / (per_page as f64)).ceil() as u32
    };

    Ok(TableView {
        database: input.database,
        table: input.table,
        columns,
        total_rows,
        page,
        per_page,
        total_pages,
        related_previews,
        rows,
    })
}

#[tauri::command]
pub fn update_cell(
    app_handle: AppHandle,
    input: UpdateCellInput,
    state: State<'_, AppState>,
) -> Result<(), String> {
    let connection = db::open_connection(&app_handle).map_err(stringify_error)?;
    let source = resolve_source(&connection, &input.source_id).map_err(stringify_error)?;
    let mut mysql = mysql_connection(&source, &state).map_err(stringify_error)?;
    let query = format!(
        "update `{}`.`{}` set `{}` = ? where `{}` = ? limit 1",
        input.database, input.table, input.column, input.primary_key
    );

    mysql.exec_drop(
        query,
        (
            json_to_mysql_value(input.value),
            json_to_mysql_value(input.primary_value),
        ),
    )
    .map_err(stringify_error)
}

#[tauri::command]
pub fn insert_row(
    app_handle: AppHandle,
    input: crate::models::InsertRowInput,
    state: State<'_, AppState>,
) -> Result<(), String> {
    let connection = db::open_connection(&app_handle).map_err(stringify_error)?;
    let source = resolve_source(&connection, &input.source_id).map_err(stringify_error)?;
    let mut mysql = mysql_connection(&source, &state).map_err(stringify_error)?;
    let mut columns = input.values.keys().cloned().collect::<Vec<_>>();
    columns.sort();
    let placeholders = vec!["?"; columns.len()].join(", ");
    let query = format!(
        "insert into `{}`.`{}` ({}) values ({})",
        input.database,
        input.table,
        columns
            .iter()
            .map(|column| format!("`{}`", column))
            .collect::<Vec<_>>()
            .join(", "),
        placeholders
    );
    let params = columns
        .iter()
        .map(|column| json_to_mysql_value(input.values.get(column).cloned().unwrap_or(Value::Null)))
        .collect::<Vec<_>>();

    mysql.exec_drop(query, params).map_err(stringify_error)
}

#[tauri::command]
pub fn delete_row(
    app_handle: AppHandle,
    input: DeleteRowInput,
    state: State<'_, AppState>,
) -> Result<(), String> {
    let connection = db::open_connection(&app_handle).map_err(stringify_error)?;
    let source = resolve_source(&connection, &input.source_id).map_err(stringify_error)?;
    let mut mysql = mysql_connection(&source, &state).map_err(stringify_error)?;
    let query = format!(
        "delete from `{}`.`{}` where `{}` = ? limit 1",
        input.database, input.table, input.primary_key
    );

    mysql.exec_drop(query, (json_to_mysql_value(input.primary_value),))
        .map_err(stringify_error)
}

#[tauri::command]
pub fn export_table_csv(
    app_handle: AppHandle,
    input: CsvTransferInput,
    state: State<'_, AppState>,
) -> Result<(), String> {
    let connection = db::open_connection(&app_handle).map_err(stringify_error)?;
    let source = resolve_source(&connection, &input.source_id).map_err(stringify_error)?;
    let mut mysql = mysql_connection(&source, &state).map_err(stringify_error)?;
    let columns = load_columns(&mut mysql, &input.database, &input.table).map_err(stringify_error)?;
    let rows = load_rows(&mut mysql, &input.database, &input.table, None, None, None, 1, 5000)
        .map_err(stringify_error)?;
    let mut writer = csv::Writer::from_path(&input.path).map_err(stringify_error)?;

    writer
        .write_record(columns.iter().map(|column| column.name.as_str()))
        .map_err(stringify_error)?;

    for row in rows {
        writer
            .write_record(columns.iter().map(|column| {
                json_value_to_string(row.get(&column.name).cloned().unwrap_or(Value::Null))
            }))
            .map_err(stringify_error)?;
    }

    writer.flush().map_err(stringify_error)?;

    Ok(())
}

#[tauri::command]
pub fn import_table_csv(
    app_handle: AppHandle,
    input: CsvTransferInput,
    state: State<'_, AppState>,
) -> Result<u64, String> {
    let connection = db::open_connection(&app_handle).map_err(stringify_error)?;
    let source = resolve_source(&connection, &input.source_id).map_err(stringify_error)?;
    let mut mysql = mysql_connection(&source, &state).map_err(stringify_error)?;
    let mut reader = csv::Reader::from_path(Path::new(&input.path)).map_err(stringify_error)?;
    let headers = reader.headers().map_err(stringify_error)?.clone();
    let columns = headers.iter().map(str::to_string).collect::<Vec<_>>();
    let mut imported = 0_u64;

    for record in reader.records() {
        let record = record.map_err(stringify_error)?;

        if record.is_empty() {
            continue;
        }

        let values = record
            .iter()
            .map(|value| MySqlValue::from(value.to_string()))
            .collect::<Vec<_>>();
        let placeholders = vec!["?"; columns.len()].join(", ");
        let query = format!(
            "insert into `{}`.`{}` ({}) values ({})",
            input.database,
            input.table,
            columns
                .iter()
                .map(|column| format!("`{}`", column))
                .collect::<Vec<_>>()
                .join(", "),
            placeholders
        );

        mysql.exec_drop(query, values).map_err(stringify_error)?;
        imported += 1;
    }

    Ok(imported)
}

#[tauri::command]
pub fn add_column(
    app_handle: AppHandle,
    input: AddColumnInput,
    state: State<'_, AppState>,
) -> Result<(), String> {
    let connection = db::open_connection(&app_handle).map_err(stringify_error)?;
    let source = resolve_source(&connection, &input.source_id).map_err(stringify_error)?;
    let mut mysql = mysql_connection(&source, &state).map_err(stringify_error)?;
    let column_type = normalized_column_type(&input.r#type)?;
    let nullable = if input.nullable { "null" } else { "not null" };
    let query = format!(
        "alter table `{}`.`{}` add column `{}` {} {}",
        input.database,
        input.table,
        input.name,
        column_type,
        nullable,
    );

    mysql.query_drop(query).map_err(stringify_error)
}

#[tauri::command]
pub fn healthcheck_mysql_backend(
    app_handle: AppHandle,
    state: State<'_, AppState>,
) -> Result<String, String> {
    let connection = db::open_connection(&app_handle).map_err(stringify_error)?;
    let source = resolve_source(&connection, "local-herd").map_err(stringify_error)?;
    let _ = mysql_connection(&source, &state).map_err(stringify_error)?;
    Ok("MySQL backend available.".to_string())
}

fn mysql_connection(source: &SavedSource, state: &State<'_, AppState>) -> BackendResult<PooledConn> {
    match mysql_connection_inner(source, state) {
        Ok(connection) => Ok(connection),
        Err(error) if source.kind == "ssh" => {
            reset_tunnel(&source.id, state)?;
            mysql_connection_inner(source, state).map_err(|retry_error| {
                BackendError::Message(format!(
                    "SSH reconnect failed after reset. Initial error: {error}. Retry error: {retry_error}"
                ))
            })
        }
        Err(error) => Err(error),
    }
}

fn mysql_connection_inner(source: &SavedSource, state: &State<'_, AppState>) -> BackendResult<PooledConn> {
    let mut builder = OptsBuilder::new()
        .ip_or_hostname(Some("127.0.0.1"))
        .user(Some(source.database_username.clone()))
        .pass(source.database_password.clone())
        .db_name(source.database_name.clone())
        .stmt_cache_size(Some(0));

    if source.kind == "ssh" {
        let tunnel_port = ensure_ssh_tunnel(source, state)?;
        builder = builder.tcp_port(tunnel_port);
    } else {
        builder = builder
            .ip_or_hostname(Some(source.database_host.clone()))
            .tcp_port(source.database_port);
    }

    let pool = Pool::new(builder)?;
    let connection = pool.get_conn()?;

    Ok(connection)
}

fn ensure_ssh_tunnel(source: &SavedSource, state: &State<'_, AppState>) -> BackendResult<u16> {
    if source.kind != "ssh" {
        return Ok(source.database_port);
    }

    let ssh_host = source
        .ssh_host
        .clone()
        .ok_or_else(|| BackendError::Message("SSH host is required.".to_string()))?;
    let ssh_username = source
        .ssh_username
        .clone()
        .ok_or_else(|| BackendError::Message("SSH username is required.".to_string()))?;
    let private_key_path = source
        .private_key_path
        .clone()
        .ok_or_else(|| BackendError::Message("SSH private key path is required.".to_string()))?;
    let ssh_port = source.ssh_port.unwrap_or(22);
    let mut tunnels = state
        .tunnels
        .lock()
        .map_err(|_| BackendError::Message("Failed to acquire tunnel state.".to_string()))?;

    tunnels.retain(|_, handle| match handle.child.try_wait() {
        Ok(None) => {
            if port_is_reachable(handle.port) {
                true
            } else {
                let _ = handle.child.kill();
                false
            }
        }
        Ok(Some(_)) => false,
        Err(_) => false,
    });

    if let Some(existing) = tunnels.get_mut(&source.id) {
        if existing.child.try_wait()?.is_none() && port_is_reachable(existing.port) {
            return Ok(existing.port);
        }

        let _ = existing.child.kill();
    }

    let local_port = available_port()?;
    let child = Command::new("ssh")
        .arg("-N")
        .arg("-o")
        .arg("ExitOnForwardFailure=yes")
        .arg("-o")
        .arg("ServerAliveInterval=30")
        .arg("-o")
        .arg("ServerAliveCountMax=3")
        .arg("-o")
        .arg("StrictHostKeyChecking=accept-new")
        .arg("-L")
        .arg(format!(
            "{}:{}:{}",
            local_port, source.database_host, source.database_port
        ))
        .arg("-i")
        .arg(private_key_path)
        .arg("-p")
        .arg(ssh_port.to_string())
        .arg(format!("{}@{}", ssh_username, ssh_host))
        .stdout(Stdio::null())
        .stderr(Stdio::null())
        .spawn()?;

    tunnels.insert(
        source.id.clone(),
        TunnelHandle {
            port: local_port,
            child,
        },
    );

    wait_for_tunnel_ready(local_port)?;

    Ok(local_port)
}

fn available_port() -> BackendResult<u16> {
    let listener = TcpListener::bind("127.0.0.1:0")?;
    let port = listener.local_addr()?.port();
    drop(listener);
    Ok(port)
}

fn load_columns(conn: &mut PooledConn, database: &str, table: &str) -> BackendResult<Vec<TableColumn>> {
    let rows: Vec<(String, String, String, String)> = conn.exec(
        "
        select column_name, data_type, is_nullable, column_key
        from information_schema.columns
        where table_schema = ? and table_name = ?
        order by ordinal_position asc
        ",
        (database, table),
    )?;
    let relationships = load_relationships(conn, database, table)?;

    Ok(rows
        .into_iter()
        .map(|(name, data_type, is_nullable, column_key)| TableColumn {
            referenced_table: relationships.get(&name).map(|value| value.0.clone()),
            referenced_column: relationships.get(&name).map(|value| value.1.clone()),
            inferred_relation: relationships.get(&name).map(|value| value.2).unwrap_or(false),
            name,
            r#type: data_type,
            nullable: is_nullable == "YES",
            primary: column_key == "PRI",
            width: None,
        })
        .collect())
}

fn load_rows(
    conn: &mut PooledConn,
    database: &str,
    table: &str,
    search: Option<&str>,
    sort_column: Option<&str>,
    sort_direction: Option<&str>,
    page: u32,
    per_page: u32,
) -> BackendResult<Vec<HashMap<String, Value>>> {
    let sortable_columns = load_columns(conn, database, table)?
        .into_iter()
        .map(|column| column.name)
        .collect::<Vec<_>>();
    let sort_column = sort_column
        .filter(|column| sortable_columns.iter().any(|candidate| candidate == column))
        .unwrap_or("id");
    let sort_direction = match sort_direction.unwrap_or("asc").to_ascii_lowercase().as_str() {
        "desc" => "desc",
        _ => "asc",
    };
    let offset = page.saturating_sub(1) * per_page;
    let query = if let Some(search_term) = search.filter(|value| !value.trim().is_empty()) {
        format!(
            "select * from `{}`.`{}` where concat_ws(' ', {}) like ? order by `{}` {} limit {} offset {}",
            database,
            table,
            searchable_columns(conn, database, table)?.join(", "),
            sort_column,
            sort_direction,
            per_page,
            offset
        )
        .to_string()
        .pipe(|query| (query, vec![MySqlValue::from(format!("%{}%", search_term))]))
    } else {
        (
            format!(
                "select * from `{}`.`{}` order by `{}` {} limit {} offset {}",
                database, table, sort_column, sort_direction, per_page, offset
            ),
            vec![],
        )
    };

    let rows: Vec<Row> = conn.exec(query.0, query.1)?;

    Ok(rows.into_iter().map(row_to_json_map).collect())
}

fn count_rows(
    conn: &mut PooledConn,
    database: &str,
    table: &str,
    search: Option<&str>,
) -> BackendResult<usize> {
    let query = if let Some(search_term) = search.filter(|value| !value.trim().is_empty()) {
        format!(
            "select count(*) from `{}`.`{}` where concat_ws(' ', {}) like ?",
            database,
            table,
            searchable_columns(conn, database, table)?.join(", "),
        )
        .to_string()
        .pipe(|query| (query, vec![MySqlValue::from(format!("%{}%", search_term))]))
    } else {
        (
            format!("select count(*) from `{}`.`{}`", database, table),
            vec![],
        )
    };

    let total_rows = conn
        .exec_first::<u64, _, _>(query.0, query.1)?
        .unwrap_or(0);

    Ok(total_rows as usize)
}

fn searchable_columns(conn: &mut PooledConn, database: &str, table: &str) -> BackendResult<Vec<String>> {
    let columns: Vec<String> = conn.exec(
        "
        select column_name
        from information_schema.columns
        where table_schema = ? and table_name = ?
        order by ordinal_position asc
        ",
        (database, table),
    )?;

    Ok(columns
        .into_iter()
        .map(|column| format!("cast(`{}` as char)", column))
        .collect())
}

fn load_relationships(
    conn: &mut PooledConn,
    database: &str,
    table: &str,
) -> BackendResult<HashMap<String, (String, String, bool)>> {
    let explicit_rows: Vec<(String, String, String)> = conn.exec(
        "
        select column_name, referenced_table_name, referenced_column_name
        from information_schema.key_column_usage
        where table_schema = ?
          and table_name = ?
          and referenced_table_name is not null
        ",
        (database, table),
    )?;
    let available_tables: Vec<String> = conn.exec(
        "
        select table_name
        from information_schema.tables
        where table_schema = ?
          and table_type = 'BASE TABLE'
        ",
        (database,),
    )?;

    let mut relationships = explicit_rows
        .into_iter()
        .map(|(column, referenced_table, referenced_column)| {
            (column, (referenced_table, referenced_column, false))
        })
        .collect::<HashMap<_, _>>();

    let columns: Vec<String> = conn.exec(
        "
        select column_name
        from information_schema.columns
        where table_schema = ?
          and table_name = ?
        ",
        (database, table),
    )?;

    for column in columns {
        if relationships.contains_key(&column) || !column.to_ascii_lowercase().ends_with("_id") {
            continue;
        }

        let base_name = column[..column.len() - 3].to_ascii_lowercase();
        let inferred_table = format!("{}s", base_name);

        if available_tables.iter().any(|table_name| table_name.eq_ignore_ascii_case(&inferred_table)) {
            relationships.insert(column.clone(), (inferred_table, "id".to_string(), true));
        }
    }

    Ok(relationships)
}

fn load_related_previews(
    conn: &mut PooledConn,
    database: &str,
    columns: &[TableColumn],
    rows: &[HashMap<String, Value>],
) -> BackendResult<HashMap<String, RelatedRecordPreview>> {
    let relationship_columns = columns
        .iter()
        .filter_map(|column| {
            column.referenced_table.as_ref().map(|referenced_table| {
                (
                    column.name.clone(),
                    referenced_table.clone(),
                    column
                        .referenced_column
                        .clone()
                        .unwrap_or_else(|| "id".to_string()),
                )
            })
        })
        .collect::<Vec<_>>();
    let mut resolved_previews = HashMap::new();
    let mut previews = HashMap::new();

    for (row_index, row) in rows.iter().enumerate() {
        for (column_name, referenced_table, referenced_column) in &relationship_columns {
            let Some(value) = row.get(column_name).cloned() else {
                continue;
            };

            if value == Value::Null || matches!(&value, Value::String(text) if text.is_empty()) {
                continue;
            }

            let preview_lookup_key = format!("{referenced_table}:{referenced_column}:{}", preview_value_key(&value));

            if !resolved_previews.contains_key(&preview_lookup_key) {
                let preview = get_related_record_preview(
                    conn,
                    database,
                    referenced_table,
                    referenced_column,
                    value.clone(),
                )?;

                resolved_previews.insert(preview_lookup_key.clone(), preview);
            }

            if let Some(Some(preview)) = resolved_previews.get(&preview_lookup_key).cloned() {
                previews.insert(format!("{row_index}:{column_name}"), preview);
            }
        }
    }

    Ok(previews)
}

fn get_related_record_preview(
    conn: &mut PooledConn,
    database: &str,
    table: &str,
    lookup_column: &str,
    lookup_value: Value,
) -> BackendResult<Option<RelatedRecordPreview>> {
    let query = format!(
        "select * from `{}`.`{}` where `{}` = ? limit 1",
        database, table, lookup_column
    );
    let row = conn.exec_first::<Row, _, _>(query, (json_to_mysql_value(lookup_value),))?;
    let Some(row) = row else {
        return Ok(None);
    };
    let record = row_to_json_map(row);
    let summary = ["name", "title", "label", "email", "slug"]
        .iter()
        .find_map(|column| {
            record
                .get(*column)
                .map(|value| stringify_preview_value(value.clone()))
                .filter(|value| !value.is_empty() && value != "NULL")
        })
        .unwrap_or_else(|| {
            let identifier = record
                .get(lookup_column)
                .or_else(|| record.get("id"))
                .map(|value| stringify_preview_value(value.clone()))
                .filter(|value| !value.is_empty() && value != "NULL")
                .unwrap_or_default();

            if identifier.is_empty() {
                table.trim_end_matches('s').to_string()
            } else {
                format!("{} #{}", table.trim_end_matches('s'), identifier)
            }
        });

    let mut fields = record
        .iter()
        .map(|(label, value)| RelatedPreviewField {
            label: label.clone(),
            value: stringify_preview_value(value.clone()),
        })
        .filter(|field| !field.value.is_empty())
        .collect::<Vec<_>>();
    fields.sort_by_key(|field| match field.label.as_str() {
        "id" => 0,
        "name" | "title" | "label" | "email" | "slug" => 1,
        _ => 2,
    });
    fields.truncate(4);

    Ok(Some(RelatedRecordPreview { summary, fields }))
}

fn row_to_json_map(row: Row) -> HashMap<String, Value> {
    row.columns_ref()
        .iter()
        .enumerate()
        .map(|(index, column)| {
            let name = column.name_str().to_string();
            let value = mysql_value_to_json(row.as_ref(index).cloned().unwrap_or(MySqlValue::NULL));
            (name, value)
        })
        .collect()
}

fn mysql_value_to_json(value: MySqlValue) -> Value {
    match value {
        MySqlValue::NULL => Value::Null,
        MySqlValue::Bytes(bytes) => Value::String(String::from_utf8_lossy(&bytes).to_string()),
        MySqlValue::Int(value) => Value::Number(value.into()),
        MySqlValue::UInt(value) => Value::Number(value.into()),
        MySqlValue::Float(value) => serde_json::Number::from_f64(value as f64)
            .map(Value::Number)
            .unwrap_or(Value::Null),
        MySqlValue::Double(value) => serde_json::Number::from_f64(value)
            .map(Value::Number)
            .unwrap_or(Value::Null),
        MySqlValue::Date(year, month, day, hour, minute, second, micros) => Value::String(format!(
            "{:04}-{:02}-{:02} {:02}:{:02}:{:02}.{:06}",
            year, month, day, hour, minute, second, micros
        )),
        MySqlValue::Time(_, days, hours, minutes, seconds, micros) => Value::String(format!(
            "{}d {:02}:{:02}:{:02}.{:06}",
            days, hours, minutes, seconds, micros
        )),
    }
}

fn json_to_mysql_value(value: Value) -> MySqlValue {
    match value {
        Value::Null => MySqlValue::NULL,
        Value::Bool(value) => MySqlValue::from(value),
        Value::Number(value) => {
            if let Some(integer) = value.as_i64() {
                MySqlValue::from(integer)
            } else if let Some(unsigned) = value.as_u64() {
                MySqlValue::from(unsigned)
            } else if let Some(float) = value.as_f64() {
                MySqlValue::from(float)
            } else {
                MySqlValue::NULL
            }
        }
        Value::String(value) => MySqlValue::from(value),
        Value::Array(_) | Value::Object(_) => MySqlValue::from(value.to_string()),
    }
}

fn json_value_to_string(value: Value) -> String {
    match value {
        Value::Null => String::new(),
        Value::String(value) => value,
        other => other.to_string(),
    }
}

fn stringify_preview_value(value: Value) -> String {
    match value {
        Value::Null => "NULL".to_string(),
        Value::Bool(value) => {
            if value {
                "true".to_string()
            } else {
                "false".to_string()
            }
        }
        Value::String(value) => truncate(&value, 72),
        other => truncate(&other.to_string(), 72),
    }
}

fn preview_value_key(value: &Value) -> String {
    match value {
        Value::Null => "null".to_string(),
        Value::String(value) => value.clone(),
        other => other.to_string(),
    }
}

fn normalized_column_type(input: &str) -> Result<&'static str, String> {
    match input.to_ascii_lowercase().as_str() {
        "varchar" => Ok("varchar(255)"),
        "text" => Ok("text"),
        "int" => Ok("int"),
        "bigint" => Ok("bigint"),
        "boolean" => Ok("boolean"),
        "datetime" => Ok("datetime"),
        "date" => Ok("date"),
        "json" => Ok("json"),
        "decimal" => Ok("decimal(10,2)"),
        other => Err(format!("Unsupported column type: {other}")),
    }
}

fn stringify_error<E: std::fmt::Display>(error: E) -> String {
    error.to_string()
}

fn resolve_source(connection: &rusqlite::Connection, source_id: &str) -> BackendResult<SavedSource> {
    let mut source = db::find_source(connection, source_id)?;

    if source.has_database_password && source.database_password.is_none() {
        source.database_password = keychain::load_database_password(&source.id)
            .map_err(BackendError::Message)?;
    }

    Ok(source)
}

fn reset_tunnel(source_id: &str, state: &State<'_, AppState>) -> BackendResult<()> {
    let mut tunnels = state
        .tunnels
        .lock()
        .map_err(|_| BackendError::Message("Failed to acquire tunnel state.".to_string()))?;

    if let Some(mut tunnel) = tunnels.remove(source_id) {
        let _ = tunnel.child.kill();
    }

    Ok(())
}

fn port_is_reachable(port: u16) -> bool {
    TcpStream::connect_timeout(
        &format!("127.0.0.1:{port}").parse().expect("valid socket addr"),
        Duration::from_millis(150),
    )
    .is_ok()
}

fn wait_for_tunnel_ready(port: u16) -> BackendResult<()> {
    for _ in 0..12 {
        if port_is_reachable(port) {
            return Ok(());
        }

        std::thread::sleep(Duration::from_millis(250));
    }

    Err(BackendError::Message(
        "SSH tunnel failed to become ready in time.".to_string(),
    ))
}

fn truncate(value: &str, max: usize) -> String {
    let mut truncated = value.chars().take(max).collect::<String>();

    if value.chars().count() > max {
        truncated.push_str("…");
    }

    truncated
}

trait Pipe: Sized {
    fn pipe<T>(self, callback: impl FnOnce(Self) -> T) -> T {
        callback(self)
    }
}

impl<T> Pipe for T {}
