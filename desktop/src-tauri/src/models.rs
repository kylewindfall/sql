use serde::{Deserialize, Serialize};
use std::collections::HashMap;

#[derive(Debug, Serialize, Deserialize, Clone)]
#[serde(rename_all = "camelCase")]
pub struct SavedSource {
    pub id: String,
    pub name: String,
    pub kind: String,
    pub host_label: String,
    pub ssh_host: Option<String>,
    pub ssh_port: Option<u16>,
    pub ssh_username: Option<String>,
    pub private_key_path: Option<String>,
    pub database_host: String,
    pub database_port: u16,
    pub database_name: Option<String>,
    pub database_username: String,
    pub has_database_password: bool,
    #[serde(skip_serializing, default)]
    pub database_password: Option<String>,
}

#[derive(Debug, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct SidebarTableReference {
    pub key: String,
    pub source_id: String,
    pub database: String,
    pub table: String,
}

#[derive(Debug, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct SidebarPreference {
    pub recent_expanded: bool,
    pub pinned_tables: Vec<SidebarTableReference>,
    pub recent_tables: Vec<SidebarTableReference>,
}

#[derive(Debug, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct DatabaseItem {
    pub name: String,
    pub tables: u64,
}

#[derive(Debug, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct TableItem {
    pub database: String,
    pub name: String,
    pub rows: u64,
}

#[derive(Debug, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct TableColumn {
    pub name: String,
    pub r#type: String,
    pub nullable: bool,
    pub primary: bool,
    pub width: Option<u32>,
    pub referenced_table: Option<String>,
    pub referenced_column: Option<String>,
    pub inferred_relation: bool,
}

#[derive(Debug, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct TableView {
    pub database: String,
    pub table: String,
    pub columns: Vec<TableColumn>,
    pub rows: Vec<HashMap<String, serde_json::Value>>,
    pub total_rows: usize,
    pub page: u32,
    pub per_page: u32,
    pub total_pages: u32,
    pub related_previews: HashMap<String, RelatedRecordPreview>,
}

#[derive(Debug, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct SourceInput {
    pub name: String,
    pub kind: String,
    pub ssh_host: Option<String>,
    pub ssh_port: Option<u16>,
    pub ssh_username: Option<String>,
    pub private_key_path: Option<String>,
    pub database_host: String,
    pub database_port: u16,
    pub database_name: Option<String>,
    pub database_username: String,
    pub database_password: Option<String>,
}

#[derive(Debug, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct OpenTableInput {
    pub source_id: String,
    pub database: String,
    pub table: String,
    pub search: Option<String>,
    pub sort_column: Option<String>,
    pub sort_direction: Option<String>,
    pub page: Option<u32>,
    pub per_page: Option<u32>,
}

#[derive(Debug, Serialize, Deserialize, Clone)]
#[serde(rename_all = "camelCase")]
pub struct RelatedPreviewField {
    pub label: String,
    pub value: String,
}

#[derive(Debug, Serialize, Deserialize, Clone)]
#[serde(rename_all = "camelCase")]
pub struct RelatedRecordPreview {
    pub summary: String,
    pub fields: Vec<RelatedPreviewField>,
}

#[derive(Debug, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct UpdateCellInput {
    pub source_id: String,
    pub database: String,
    pub table: String,
    pub primary_key: String,
    pub primary_value: serde_json::Value,
    pub column: String,
    pub value: serde_json::Value,
}

#[derive(Debug, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct InsertRowInput {
    pub source_id: String,
    pub database: String,
    pub table: String,
    pub values: HashMap<String, serde_json::Value>,
}

#[derive(Debug, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct DeleteRowInput {
    pub source_id: String,
    pub database: String,
    pub table: String,
    pub primary_key: String,
    pub primary_value: serde_json::Value,
}

#[derive(Debug, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct CsvTransferInput {
    pub source_id: String,
    pub database: String,
    pub table: String,
    pub path: String,
}

#[derive(Debug, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct AddColumnInput {
    pub source_id: String,
    pub database: String,
    pub table: String,
    pub name: String,
    pub r#type: String,
    pub nullable: bool,
}
