export type SourceKind = "local" | "ssh";

export interface Source {
  id: string;
  name: string;
  kind: SourceKind;
  hostLabel: string;
  sshHost?: string | null;
  sshPort?: number | null;
  sshUsername?: string | null;
  privateKeyPath?: string | null;
  databaseHost?: string;
  databasePort?: number;
  databaseName?: string | null;
  databaseUsername?: string;
  hasDatabasePassword?: boolean;
  isPinned?: boolean;
}

export interface DatabaseItem {
  name: string;
  tables: number;
}

export interface TableItem {
  database: string;
  name: string;
  rows: number;
}

export interface TableColumn {
  name: string;
  type: string;
  nullable: boolean;
  primary?: boolean;
  width?: number;
  referencedTable?: string | null;
  referencedColumn?: string | null;
  inferredRelation?: boolean;
}

export interface RowRecord {
  id: string | number;
  [key: string]: string | number | boolean | null | Record<string, unknown> | unknown[];
}

export interface RelatedPreviewField {
  label: string;
  value: string;
}

export interface RelatedRecordPreview {
  summary: string;
  fields: RelatedPreviewField[];
}

export interface TableView {
  database: string;
  table: string;
  columns: TableColumn[];
  rows: RowRecord[];
  totalRows: number;
  page: number;
  perPage: number;
  totalPages: number;
  relatedPreviews: Record<string, RelatedRecordPreview>;
}

export interface SourceInput {
  name: string;
  kind: SourceKind;
  sshHost?: string;
  sshPort?: number;
  sshUsername?: string;
  privateKeyPath?: string;
  databaseHost: string;
  databasePort: number;
  databaseName?: string;
  databaseUsername: string;
  databasePassword?: string;
}

export interface TableQuery {
  search?: string;
  sortColumn?: string;
  sortDirection?: "asc" | "desc";
  page?: number;
  perPage?: number;
}

export interface AddColumnInput {
  sourceId: string;
  database: string;
  table: string;
  name: string;
  type: string;
  nullable: boolean;
}

export interface SidebarTableReference {
  key: string;
  sourceId: string;
  database: string;
  table: string;
}

export interface SidebarState {
  recentExpanded: boolean;
  pinnedTables: SidebarTableReference[];
  recentTables: SidebarTableReference[];
}
