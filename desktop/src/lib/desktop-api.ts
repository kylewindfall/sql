import { invoke } from "@tauri-apps/api/core";
import { open, save } from "@tauri-apps/plugin-dialog";
import {
  mockDatabases,
  mockSources,
  mockTables,
  mockView,
} from "./mock-data";
import type {
  DatabaseItem,
  AddColumnInput,
  RowRecord,
  SidebarState,
  Source,
  SourceInput,
  TableItem,
  TableQuery,
  TableView,
} from "./types";

const isTauriRuntime =
  typeof window !== "undefined" && "__TAURI_INTERNALS__" in window;

export interface DesktopApi {
  listSources(): Promise<Source[]>;
  saveSource(input: SourceInput): Promise<Source>;
  loadSidebarState(): Promise<SidebarState>;
  saveSidebarState(state: SidebarState): Promise<void>;
  listDatabases(sourceId: string): Promise<DatabaseItem[]>;
  listTables(sourceId: string, database: string): Promise<TableItem[]>;
  openTable(
    sourceId: string,
    database: string,
    table: string,
    query?: TableQuery,
  ): Promise<TableView>;
  updateCell(input: {
    sourceId: string;
    database: string;
    table: string;
    primaryKey: string;
    primaryValue: RowRecord[keyof RowRecord];
    column: string;
    value: RowRecord[keyof RowRecord];
  }): Promise<void>;
  insertRow(input: {
    sourceId: string;
    database: string;
    table: string;
    values: Record<string, unknown>;
  }): Promise<void>;
  deleteRow(input: {
    sourceId: string;
    database: string;
    table: string;
    primaryKey: string;
    primaryValue: RowRecord[keyof RowRecord];
  }): Promise<void>;
  exportTableCsv(input: {
    sourceId: string;
    database: string;
    table: string;
    path: string;
  }): Promise<void>;
  importTableCsv(input: {
    sourceId: string;
    database: string;
    table: string;
    path: string;
  }): Promise<number>;
  addColumn(input: AddColumnInput): Promise<void>;
  pickOpenPath(filters?: { name: string; extensions: string[] }[]): Promise<string | null>;
  pickSavePath(defaultPath: string): Promise<string | null>;
}

class BrowserDesktopApi implements DesktopApi {
  async listSources(): Promise<Source[]> {
    return mockSources;
  }

  async saveSource(input: SourceInput): Promise<Source> {
    return {
      id: crypto.randomUUID(),
      name: input.name,
      kind: input.kind,
      hostLabel:
        input.kind === "ssh"
          ? `${input.sshUsername ?? "forge"}@${input.sshHost ?? "unknown"}`
          : `${input.databaseHost}:${input.databasePort}`,
      sshHost: input.sshHost,
      sshPort: input.sshPort,
      sshUsername: input.sshUsername,
      privateKeyPath: input.privateKeyPath,
      databaseHost: input.databaseHost,
      databasePort: input.databasePort,
      databaseName: input.databaseName,
      databaseUsername: input.databaseUsername,
      hasDatabasePassword: Boolean(input.databasePassword),
    };
  }

  async loadSidebarState(): Promise<SidebarState> {
    return {
      recentExpanded: false,
      pinnedTables: [],
      recentTables: [],
    };
  }

  async saveSidebarState(_state: SidebarState): Promise<void> {}

  async listDatabases(_sourceId: string): Promise<DatabaseItem[]> {
    return mockDatabases;
  }

  async listTables(_sourceId: string, database: string): Promise<TableItem[]> {
    return mockTables.filter((table) => table.database === database);
  }

  async openTable(
    _sourceId: string,
    database: string,
    table: string,
    query?: TableQuery,
  ): Promise<TableView> {
    let rows = mockView.rows;

    if (query?.search) {
      const needle = query.search.toLowerCase();
      rows = rows.filter((row) =>
        Object.values(row).some((value) =>
          String(value ?? "")
            .toLowerCase()
            .includes(needle),
        ),
      );
    }

    if (query?.sortColumn) {
      rows = [...rows].sort((left, right) => {
        const leftValue = String(left[query.sortColumn!] ?? "");
        const rightValue = String(right[query.sortColumn!] ?? "");
        return query.sortDirection === "desc"
          ? rightValue.localeCompare(leftValue)
          : leftValue.localeCompare(rightValue);
      });
    }

    return {
      ...mockView,
      database,
      table,
      rows,
      totalRows: rows.length,
      page: query?.page ?? 1,
      perPage: query?.perPage ?? 50,
      totalPages: 1,
    };
  }

  async updateCell(): Promise<void> {}

  async insertRow(): Promise<void> {}

  async deleteRow(): Promise<void> {}

  async exportTableCsv(): Promise<void> {}

  async importTableCsv(): Promise<number> {
    return 0;
  }

  async addColumn(): Promise<void> {}

  async pickOpenPath(): Promise<string | null> {
    return window.prompt("CSV path") ?? null;
  }

  async pickSavePath(defaultPath: string): Promise<string | null> {
    return window.prompt("Export path", defaultPath) ?? null;
  }
}

class TauriDesktopApi implements DesktopApi {
  async listSources(): Promise<Source[]> {
    return invoke("list_saved_sources");
  }

  async saveSource(input: SourceInput): Promise<Source> {
    return invoke("save_source", { input });
  }

  async loadSidebarState(): Promise<SidebarState> {
    const preference = await invoke<SidebarState>(
      "get_sidebar_preference",
    );

    return preference;
  }

  async saveSidebarState(state: SidebarState): Promise<void> {
    await invoke("set_sidebar_preference", { preference: state });
  }

  async listDatabases(sourceId: string): Promise<DatabaseItem[]> {
    return invoke("list_databases", { sourceId });
  }

  async listTables(sourceId: string, database: string): Promise<TableItem[]> {
    return invoke("list_tables", { sourceId, database });
  }

  async openTable(
    sourceId: string,
    database: string,
    table: string,
    query?: TableQuery,
  ): Promise<TableView> {
    return invoke("open_table", {
      input: {
        sourceId,
        database,
        table,
        search: query?.search,
        sortColumn: query?.sortColumn,
        sortDirection: query?.sortDirection,
        page: query?.page ?? 1,
        perPage: query?.perPage ?? 100,
      },
    });
  }

  async updateCell(input: {
    sourceId: string;
    database: string;
    table: string;
    primaryKey: string;
    primaryValue: RowRecord[keyof RowRecord];
    column: string;
    value: RowRecord[keyof RowRecord];
  }): Promise<void> {
    await invoke("update_cell", { input });
  }

  async insertRow(input: {
    sourceId: string;
    database: string;
    table: string;
    values: Record<string, unknown>;
  }): Promise<void> {
    await invoke("insert_row", { input });
  }

  async deleteRow(input: {
    sourceId: string;
    database: string;
    table: string;
    primaryKey: string;
    primaryValue: RowRecord[keyof RowRecord];
  }): Promise<void> {
    await invoke("delete_row", { input });
  }

  async exportTableCsv(input: {
    sourceId: string;
    database: string;
    table: string;
    path: string;
  }): Promise<void> {
    await invoke("export_table_csv", { input });
  }

  async importTableCsv(input: {
    sourceId: string;
    database: string;
    table: string;
    path: string;
  }): Promise<number> {
    return invoke("import_table_csv", { input });
  }

  async addColumn(input: AddColumnInput): Promise<void> {
    await invoke("add_column", { input });
  }

  async pickOpenPath(
    filters?: { name: string; extensions: string[] }[],
  ): Promise<string | null> {
    const path = await open({
      multiple: false,
      directory: false,
      filters,
    });

    return typeof path === "string" ? path : null;
  }

  async pickSavePath(defaultPath: string): Promise<string | null> {
    return save({
      defaultPath,
    });
  }
}

export const desktopApi: DesktopApi = isTauriRuntime
  ? new TauriDesktopApi()
  : new BrowserDesktopApi();
