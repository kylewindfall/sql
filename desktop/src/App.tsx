import { useEffect, useMemo, useState } from "react";
import { DataGrid } from "./components/DataGrid";
import { Sidebar } from "./components/Sidebar";
import { desktopApi } from "./lib/desktop-api";
import type {
  AddColumnInput,
  DatabaseItem,
  SidebarState,
  SidebarTableReference,
  Source,
  SourceInput,
  TableItem,
  TableQuery,
  TableView,
} from "./lib/types";

const defaultSourceForm: SourceInput = {
  name: "",
  kind: "ssh",
  sshHost: "",
  sshPort: 22,
  sshUsername: "forge",
  privateKeyPath: "",
  databaseHost: "127.0.0.1",
  databasePort: 3306,
  databaseName: "",
  databaseUsername: "",
  databasePassword: "",
};

function buildSidebarTableReference(
  sourceId: string,
  database: string,
  table: string,
): SidebarTableReference {
  return {
    key: `${sourceId}:${database}.${table}`,
    sourceId,
    database,
    table,
  };
}

export default function App() {
  const [sources, setSources] = useState<Source[]>([]);
  const [databases, setDatabases] = useState<DatabaseItem[]>([]);
  const [tables, setTables] = useState<TableItem[]>([]);
  const [selectedSourceId, setSelectedSourceId] = useState("local-herd");
  const [selectedDatabase, setSelectedDatabase] = useState("goodneighbor");
  const [selectedTable, setSelectedTable] = useState("listings");
  const [view, setView] = useState<TableView | null>(null);
  const [activeTab, setActiveTab] = useState<"data" | "schema">("data");
  const [tableQuery, setTableQuery] = useState<TableQuery>({
    search: "",
    sortColumn: "updated_at",
    sortDirection: "desc",
    page: 1,
    perPage: 100,
  });
  const [sidebarState, setSidebarState] = useState<SidebarState>({
    recentExpanded: false,
    pinnedTables: [],
    recentTables: [],
  });
  const [connectionDraft, setConnectionDraft] = useState<SourceInput>(defaultSourceForm);
  const [status, setStatus] = useState<string>("Loading desktop workspace…");
  const [isSavingSource, setIsSavingSource] = useState(false);

  const activeSource = useMemo(
    () => sources.find((source) => source.id === selectedSourceId) ?? null,
    [selectedSourceId, sources],
  );

  useEffect(() => {
    void Promise.all([
      desktopApi.listSources(),
      desktopApi.loadSidebarState(),
    ]).then(([loadedSources, loadedSidebarState]) => {
      setSources(loadedSources);
      setSidebarState(loadedSidebarState);

      if (loadedSources[0] && !loadedSources.some((source) => source.id === selectedSourceId)) {
        setSelectedSourceId(loadedSources[0].id);
      }
    });
  }, [selectedSourceId]);

  useEffect(() => {
    void desktopApi.listDatabases(selectedSourceId).then((loadedDatabases) => {
      setDatabases(loadedDatabases);

      if (loadedDatabases[0] && !loadedDatabases.some((database) => database.name === selectedDatabase)) {
        setSelectedDatabase(loadedDatabases[0].name);
      }
    });
  }, [selectedDatabase, selectedSourceId]);

  useEffect(() => {
    if (!selectedDatabase) {
      return;
    }

    void desktopApi.listTables(selectedSourceId, selectedDatabase).then((loadedTables) => {
      setTables(loadedTables);

      if (loadedTables[0] && !loadedTables.some((table) => table.name === selectedTable)) {
        setSelectedTable(loadedTables[0].name);
      }
    });
  }, [selectedDatabase, selectedSourceId, selectedTable]);

  useEffect(() => {
    if (!selectedDatabase || !selectedTable) {
      return;
    }

    const currentTable = buildSidebarTableReference(
      selectedSourceId,
      selectedDatabase,
      selectedTable,
    );

    setSidebarState((state) => {
      const recentTables = [
        currentTable,
        ...state.recentTables.filter((item) => item.key !== currentTable.key),
      ].slice(0, 8);

      if (
        recentTables.length === state.recentTables.length &&
        recentTables.every((item, index) => item.key === state.recentTables[index]?.key)
      ) {
        return state;
      }

      return {
        ...state,
        recentTables,
      };
    });

    setStatus(`Loading ${selectedDatabase}.${selectedTable}…`);

    void desktopApi
      .openTable(selectedSourceId, selectedDatabase, selectedTable, tableQuery)
      .then((loadedView) => {
        setView(loadedView);
        const startRow = loadedView.totalRows === 0
          ? 0
          : (loadedView.page - 1) * loadedView.perPage + 1;
        const endRow = loadedView.totalRows === 0
          ? 0
          : startRow + loadedView.rows.length - 1;
        setStatus(`Loaded ${startRow}-${endRow} of ${loadedView.totalRows} rows from ${loadedView.table}.`);
      })
      .catch((error: unknown) => {
        setStatus(error instanceof Error ? error.message : "Failed to load table.");
      });
  }, [selectedDatabase, selectedSourceId, selectedTable, tableQuery]);

  useEffect(() => {
    void desktopApi.saveSidebarState(sidebarState);
  }, [sidebarState]);

  const reloadTable = async () => {
    if (!selectedDatabase || !selectedTable) {
      return;
    }

    const loadedView = await desktopApi.openTable(
      selectedSourceId,
      selectedDatabase,
      selectedTable,
      tableQuery,
    );
    setView(loadedView);
  };

  return (
    <div className="app-shell">
      <Sidebar
        sources={sources}
        databases={databases}
        tables={tables}
        selectedSourceId={selectedSourceId}
        selectedDatabase={selectedDatabase}
        selectedTable={selectedTable}
        pinnedTables={sidebarState.pinnedTables}
        recentTables={sidebarState.recentTables}
        recentExpanded={sidebarState.recentExpanded}
        connectionDraft={connectionDraft}
        isSavingSource={isSavingSource}
        onConnectionDraftChange={setConnectionDraft}
        onSourceSelect={setSelectedSourceId}
        onDatabaseSelect={setSelectedDatabase}
        onTableSelect={setSelectedTable}
        onTogglePin={(table) =>
          setSidebarState((state) => {
            const tableReference = buildSidebarTableReference(
              selectedSourceId,
              selectedDatabase,
              table,
            );
            const isPinned = state.pinnedTables.some(
              (item) => item.key === tableReference.key,
            );

            return {
              ...state,
              pinnedTables: isPinned
                ? state.pinnedTables.filter((item) => item.key !== tableReference.key)
                : [tableReference, ...state.pinnedTables].slice(0, 12),
            };
          })
        }
        onToggleRecent={() =>
          setSidebarState((state) => ({
            ...state,
            recentExpanded: !state.recentExpanded,
          }))
        }
        onSaveSource={async () => {
          setIsSavingSource(true);

          try {
            const savedSource = await desktopApi.saveSource(connectionDraft);
            const loadedSources = await desktopApi.listSources();
            setSources(loadedSources);
            setSelectedSourceId(savedSource.id);
            setConnectionDraft(defaultSourceForm);
            setStatus(`Saved source ${savedSource.name}.`);
          } catch (error: unknown) {
            setStatus(error instanceof Error ? error.message : "Failed to save source.");
          } finally {
            setIsSavingSource(false);
          }
        }}
      />

      <main className="app-main">
        <div className="app-main__status">{status}</div>
        <DataGrid
          sourceId={selectedSourceId}
          sourceName={activeSource?.name ?? "Source"}
          view={view}
          activeTab={activeTab}
          query={tableQuery}
          onTabChange={setActiveTab}
          onQueryChange={setTableQuery}
          onRefresh={reloadTable}
          onOpenRelated={(table, searchValue) => {
            setSelectedTable(table);
            setActiveTab("data");
            setTableQuery((query) => ({
              ...query,
              search: searchValue,
              page: 1,
            }));
            setStatus(`Browsing ${table} by ${searchValue}.`);
          }}
          onUpdateCell={async (input) => {
            await desktopApi.updateCell({
              sourceId: selectedSourceId,
              database: input.database,
              table: input.table,
              primaryKey: input.primaryKey,
              primaryValue: input.primaryValue,
              column: input.column,
              value: input.value,
            });
            await reloadTable();
            setStatus(`Updated ${input.column}.`);
          }}
          onInsertRow={async (input) => {
            await desktopApi.insertRow({
              sourceId: selectedSourceId,
              database: input.database,
              table: input.table,
              values: input.values,
            });
            await reloadTable();
            setStatus(`Inserted a row into ${input.table}.`);
          }}
          onDeleteRow={async (input) => {
            await desktopApi.deleteRow({
              sourceId: selectedSourceId,
              database: input.database,
              table: input.table,
              primaryKey: input.primaryKey,
              primaryValue: input.primaryValue,
            });
            await reloadTable();
            setStatus(`Deleted row ${String(input.primaryValue)}.`);
          }}
          onExportCsv={async (input) => {
            await desktopApi.exportTableCsv({
              sourceId: selectedSourceId,
              database: input.database,
              table: input.table,
              path: input.path,
            });
            setStatus(`Exported CSV to ${input.path}.`);
          }}
          onImportCsv={async (input) => {
            const importedRows = await desktopApi.importTableCsv({
              sourceId: selectedSourceId,
              database: input.database,
              table: input.table,
              path: input.path,
            });
            await reloadTable();
            setStatus(`Imported ${importedRows} rows from CSV.`);
          }}
          onAddColumn={async (input: Omit<AddColumnInput, "sourceId">) => {
            await desktopApi.addColumn({
              sourceId: selectedSourceId,
              ...input,
            });
            await reloadTable();
            setStatus(`Added ${input.name} to ${input.table}.`);
          }}
        />
      </main>
    </div>
  );
}
