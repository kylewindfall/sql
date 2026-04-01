import clsx from "clsx";
import {
  flexRender,
  getCoreRowModel,
  useReactTable,
  type ColumnDef,
} from "@tanstack/react-table";
import { save, open } from "@tauri-apps/plugin-dialog";
import {
  ArrowDown,
  ArrowLeft,
  ArrowRight,
  ArrowUp,
  Download,
  Pencil,
  Plus,
  Trash2,
  Upload,
} from "lucide-react";
import { useMemo, useState } from "react";
import type { AddColumnInput, RowRecord, TableQuery, TableView } from "../lib/types";

interface DataGridProps {
  sourceId: string;
  sourceName: string;
  view: TableView | null;
  activeTab: "data" | "schema";
  query: TableQuery;
  onTabChange(tab: "data" | "schema"): void;
  onQueryChange(query: TableQuery): void;
  onRefresh(): Promise<void>;
  onOpenRelated(table: string, searchValue: string): void;
  onUpdateCell(input: {
    database: string;
    table: string;
    primaryKey: string;
    primaryValue: RowRecord[keyof RowRecord];
    column: string;
    value: RowRecord[keyof RowRecord];
  }): Promise<void>;
  onInsertRow(input: {
    database: string;
    table: string;
    values: Record<string, unknown>;
  }): Promise<void>;
  onDeleteRow(input: {
    database: string;
    table: string;
    primaryKey: string;
    primaryValue: RowRecord[keyof RowRecord];
  }): Promise<void>;
  onExportCsv(input: { sourceId: string; database: string; table: string; path: string }): Promise<void>;
  onImportCsv(input: { sourceId: string; database: string; table: string; path: string }): Promise<void>;
  onAddColumn(input: Omit<AddColumnInput, "sourceId">): Promise<void>;
}

const isTauriRuntime =
  typeof window !== "undefined" && "__TAURI_INTERNALS__" in window;

function inferRelatedTable(columnName: string): string | null {
  if (!columnName.toLowerCase().endsWith("_id")) {
    return null;
  }

  const baseName = columnName.slice(0, -3).toLowerCase();

  return `${baseName}s`;
}

export function DataGrid({
  sourceId,
  sourceName,
  view,
  activeTab,
  query,
  onTabChange,
  onQueryChange,
  onRefresh,
  onOpenRelated,
  onUpdateCell,
  onInsertRow,
  onDeleteRow,
  onExportCsv,
  onImportCsv,
  onAddColumn,
}: DataGridProps) {
  const [editingCell, setEditingCell] = useState<{
    rowId: string;
    column: string;
  } | null>(null);
  const [editValue, setEditValue] = useState("");
  const [newRowJson, setNewRowJson] = useState('{"title":"","status":"draft"}');
  const [newColumn, setNewColumn] = useState({
    name: "",
    type: "varchar",
    nullable: true,
  });
  const [pendingAction, setPendingAction] = useState<string | null>(null);
  const [hoveredPreviewKey, setHoveredPreviewKey] = useState<string | null>(null);

  const primaryKey = useMemo(
    () => view?.columns.find((column) => column.primary)?.name ?? "id",
    [view],
  );

  const columns = useMemo<ColumnDef<RowRecord>[]>(() => {
    if (!view) {
      return [];
    }

    return view.columns.map((column) => ({
      accessorKey: column.name,
      header: () => (
        <button
          type="button"
          className="grid-header__button"
          onClick={() => {
            onQueryChange({
              ...query,
              sortColumn: column.name,
              page: 1,
              sortDirection:
                query.sortColumn === column.name && query.sortDirection === "asc"
                  ? "desc"
                  : "asc",
            });
          }}
        >
          <span>{column.name}</span>
          <span className="grid-header__meta">
            {query.sortColumn === column.name ? (
              query.sortDirection === "asc" ? (
                <ArrowUp size={12} />
              ) : (
                <ArrowDown size={12} />
              )
            ) : null}
          </span>
        </button>
      ),
      cell: ({ row, getValue }) => {
        const value = getValue();
        const isEditing =
          editingCell?.rowId === String(row.original[primaryKey] ?? "") &&
          editingCell.column === column.name;
        const relatedTable =
          column.referencedTable ?? inferRelatedTable(column.name);
        const relatedPreview = view.relatedPreviews?.[`${row.index}:${column.name}`];

        if (isEditing) {
          return (
            <input
              autoFocus
              className="grid-cell__input"
              value={editValue}
              onChange={(event) => setEditValue(event.target.value)}
              onBlur={async () => {
                setPendingAction("Updating cell…");
                await onUpdateCell({
                  database: view.database,
                  table: view.table,
                  primaryKey,
                  primaryValue: row.original[primaryKey],
                  column: column.name,
                  value: editValue,
                });
                setPendingAction(null);
                setEditingCell(null);
              }}
            />
          );
        }

        return (
          <div
            className={clsx(
              "grid-cell",
              relatedPreview && "grid-cell--has-preview",
            )}
            onMouseEnter={() => {
              if (relatedPreview) {
                setHoveredPreviewKey(`${row.index}:${column.name}`);
              }
            }}
            onMouseLeave={() => {
              if (relatedPreview) {
                setHoveredPreviewKey((current) =>
                  current === `${row.index}:${column.name}` ? null : current,
                );
              }
            }}
          >
            <button
              type="button"
              className="grid-cell__button"
              onDoubleClick={() => {
                setEditingCell({
                  rowId: String(row.original[primaryKey] ?? ""),
                  column: column.name,
                });
                setEditValue(value === null ? "" : String(value));
              }}
              onFocus={() => {
                if (relatedPreview) {
                  setHoveredPreviewKey(`${row.index}:${column.name}`);
                }
              }}
              onBlur={() => {
                if (relatedPreview) {
                  setHoveredPreviewKey((current) =>
                    current === `${row.index}:${column.name}` ? null : current,
                  );
                }
              }}
            >
              {value === null ? "NULL" : String(value)}
            </button>

            {relatedTable && value !== null ? (
              <button
                type="button"
                className="grid-cell__relation"
                onClick={() => onOpenRelated(relatedTable, String(value))}
              >
                {relatedTable}
              </button>
            ) : null}

            {relatedPreview && hoveredPreviewKey === `${row.index}:${column.name}` ? (
              <div className="grid-relation-preview">
                <div className="grid-relation-preview__header">
                  <div className="grid-relation-preview__title">{relatedPreview.summary}</div>
                  <div className="grid-relation-preview__badge">Related</div>
                </div>
                <div className="grid-relation-preview__fields">
                  {relatedPreview.fields.map((field) => (
                    <div key={field.label} className="grid-relation-preview__field">
                      <span>{field.label}</span>
                      <strong>{field.value}</strong>
                    </div>
                  ))}
                </div>
              </div>
            ) : null}
          </div>
        );
      },
      size: column.width ?? 180,
    }));
  }, [
    editValue,
    editingCell,
    onOpenRelated,
    onQueryChange,
    onUpdateCell,
    primaryKey,
    query,
    view,
  ]);

  const table = useReactTable({
    data: view?.rows ?? [],
    columns,
    getCoreRowModel: getCoreRowModel(),
  });

  if (!view) {
    return <div className="grid-empty">Select a source, database, and table to begin.</div>;
  }

  return (
    <div className="grid-shell">
      <div className="grid-toolbar">
        <div>
          <div className="grid-toolbar__eyebrow">{sourceName}</div>
          <h2 className="grid-toolbar__title">
            {view.database}.{view.table}
          </h2>
        </div>
        <div className="grid-toolbar__actions">
          <button
            type="button"
            className={clsx(
              "grid-toolbar__button",
              activeTab === "data" && "grid-toolbar__button--active",
            )}
            onClick={() => onTabChange("data")}
          >
            Data
          </button>
          <button
            type="button"
            className={clsx(
              "grid-toolbar__button",
              activeTab === "schema" && "grid-toolbar__button--active",
            )}
            onClick={() => onTabChange("schema")}
          >
            Schema
          </button>
          <button
            type="button"
            className="grid-toolbar__button"
            onClick={async () => {
              setPendingAction("Refreshing table…");
              await onRefresh();
              setPendingAction(null);
            }}
          >
            Refresh
          </button>
        </div>
      </div>

      {activeTab === "data" ? (
        <>
          <div className="grid-utility-bar">
            <div className="grid-utility-group">
              <input
                className="grid-toolbar__search"
                value={query.search ?? ""}
                onChange={(event) =>
                  onQueryChange({
                    ...query,
                    search: event.target.value,
                    page: 1,
                  })
                }
                placeholder="Search rows"
              />
              <div className="grid-toolbar__pagination">
                <button
                  type="button"
                  className="grid-toolbar__button"
                  disabled={view.page <= 1}
                  onClick={() =>
                    onQueryChange({
                      ...query,
                      page: Math.max((query.page ?? view.page) - 1, 1),
                    })
                  }
                >
                  <ArrowLeft size={14} />
                </button>
                <div className="grid-toolbar__page-label">
                  Page {view.page} of {Math.max(view.totalPages, 1)}
                </div>
                <button
                  type="button"
                  className="grid-toolbar__button"
                  disabled={view.page >= view.totalPages}
                  onClick={() =>
                    onQueryChange({
                      ...query,
                      page: Math.min((query.page ?? view.page) + 1, view.totalPages),
                    })
                  }
                >
                  <ArrowRight size={14} />
                </button>
                <select
                  className="grid-toolbar__select"
                  value={query.perPage ?? view.perPage}
                  onChange={(event) =>
                    onQueryChange({
                      ...query,
                      page: 1,
                      perPage: Number(event.target.value),
                    })
                  }
                >
                  <option value={50}>50 rows</option>
                  <option value={100}>100 rows</option>
                  <option value={250}>250 rows</option>
                </select>
              </div>
              <button
                type="button"
                className="grid-toolbar__button"
                onClick={async () => {
                  const defaultPath = `${view.table}.csv`;
                  const path = isTauriRuntime
                    ? await save({ defaultPath })
                    : window.prompt("Export CSV path", defaultPath);

                  if (!path) {
                    return;
                  }

                  setPendingAction("Exporting CSV…");
                  await onExportCsv({
                    sourceId,
                    database: view.database,
                    table: view.table,
                    path,
                  });
                  setPendingAction(null);
                }}
              >
                <Download size={14} />
                Export CSV
              </button>
              <button
                type="button"
                className="grid-toolbar__button"
                onClick={async () => {
                  const path = isTauriRuntime
                    ? await open({
                        multiple: false,
                        directory: false,
                        filters: [{ name: "CSV", extensions: ["csv"] }],
                      })
                    : window.prompt("Import CSV path");

                  if (!path || Array.isArray(path)) {
                    return;
                  }

                  setPendingAction("Importing CSV…");
                  await onImportCsv({
                    sourceId,
                    database: view.database,
                    table: view.table,
                    path,
                  });
                  setPendingAction(null);
                }}
              >
                <Upload size={14} />
                Import CSV
              </button>
            </div>

            <div className="grid-utility-group">
              <textarea
                className="grid-toolbar__json"
                value={newRowJson}
                onChange={(event) => setNewRowJson(event.target.value)}
              />
              <button
                type="button"
                className="grid-toolbar__button"
                onClick={async () => {
                  setPendingAction("Inserting row…");
                  await onInsertRow({
                    database: view.database,
                    table: view.table,
                    values: JSON.parse(newRowJson) as Record<string, unknown>,
                  });
                  setPendingAction(null);
                }}
              >
                <Plus size={14} />
                Insert Row
              </button>
            </div>
          </div>

          {pendingAction ? <div className="grid-pending">{pendingAction}</div> : null}
          <div className="grid-table-meta">
            Showing {view.rows.length} rows on this page out of {view.totalRows} total.
          </div>

          <div className="grid-table-wrap">
            <table className="grid-table">
              <thead>
                {table.getHeaderGroups().map((headerGroup) => (
                  <tr key={headerGroup.id}>
                    {headerGroup.headers.map((header) => (
                      <th
                        key={header.id}
                        style={{ width: header.getSize(), minWidth: header.getSize() }}
                      >
                        {header.isPlaceholder
                          ? null
                          : flexRender(header.column.columnDef.header, header.getContext())}
                      </th>
                    ))}
                    <th className="grid-table__actions-header">Actions</th>
                  </tr>
                ))}
              </thead>
              <tbody>
                {table.getRowModel().rows.map((row) => (
                  <tr key={row.id}>
                    {row.getVisibleCells().map((cell) => (
                      <td
                        key={cell.id}
                        className={clsx(
                          String(cell.column.id).endsWith("_id") && "grid-cell--relation",
                        )}
                      >
                        {flexRender(cell.column.columnDef.cell, cell.getContext())}
                      </td>
                    ))}
                    <td className="grid-table__actions-cell">
                      <button
                        type="button"
                        className="grid-row-action"
                        onClick={() => {
                          const firstEditable = view.columns.find((column) => !column.primary);

                          if (!firstEditable) {
                            return;
                          }

                          setEditingCell({
                            rowId: String(row.original[primaryKey] ?? ""),
                            column: firstEditable.name,
                          });
                          setEditValue(String(row.original[firstEditable.name] ?? ""));
                        }}
                      >
                        <Pencil size={14} />
                      </button>
                      <button
                        type="button"
                        className="grid-row-action grid-row-action--danger"
                        onClick={async () => {
                          setPendingAction("Deleting row…");
                          await onDeleteRow({
                            database: view.database,
                            table: view.table,
                            primaryKey,
                            primaryValue: row.original[primaryKey],
                          });
                          setPendingAction(null);
                        }}
                      >
                        <Trash2 size={14} />
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      ) : (
        <div className="schema-shell">
          <div className="schema-add">
            <input
              value={newColumn.name}
              onChange={(event) =>
                setNewColumn((current) => ({ ...current, name: event.target.value }))
              }
              placeholder="new_column"
            />
            <select
              value={newColumn.type}
              onChange={(event) =>
                setNewColumn((current) => ({ ...current, type: event.target.value }))
              }
            >
              <option value="varchar">varchar</option>
              <option value="text">text</option>
              <option value="int">int</option>
              <option value="bigint">bigint</option>
              <option value="boolean">boolean</option>
              <option value="datetime">datetime</option>
              <option value="date">date</option>
              <option value="json">json</option>
              <option value="decimal">decimal</option>
            </select>
            <label className="schema-add__checkbox">
              <input
                type="checkbox"
                checked={newColumn.nullable}
                onChange={(event) =>
                  setNewColumn((current) => ({
                    ...current,
                    nullable: event.target.checked,
                  }))
                }
              />
              Nullable
            </label>
            <button
              type="button"
              className="grid-toolbar__button"
              onClick={async () => {
                setPendingAction("Adding column…");
                await onAddColumn({
                  database: view.database,
                  table: view.table,
                  ...newColumn,
                });
                setPendingAction(null);
                setNewColumn({
                  name: "",
                  type: "varchar",
                  nullable: true,
                });
              }}
            >
              Add Column
            </button>
          </div>

          <div className="schema-table-wrap">
            <table className="grid-table">
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Type</th>
                  <th>Nullable</th>
                  <th>Relation</th>
                </tr>
              </thead>
              <tbody>
                {view.columns.map((column) => (
                  <tr key={column.name}>
                    <td>{column.name}</td>
                    <td>{column.type}</td>
                    <td>{column.nullable ? "Yes" : "No"}</td>
                    <td>
                      {column.referencedTable
                        ? `${column.referencedTable}.${column.referencedColumn}`
                        : "—"}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  );
}
