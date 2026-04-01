import clsx from "clsx";
import {
  ChevronDown,
  ChevronRight,
  Database,
  HardDrive,
  Plus,
  Search,
  Pin,
  PinOff,
  Star,
} from "lucide-react";
import type {
  DatabaseItem,
  SidebarTableReference,
  Source,
  SourceInput,
  TableItem,
} from "../lib/types";

interface SidebarProps {
  sources: Source[];
  databases: DatabaseItem[];
  tables: TableItem[];
  selectedSourceId: string;
  selectedDatabase: string;
  selectedTable: string;
  pinnedTables: SidebarTableReference[];
  recentTables: SidebarTableReference[];
  recentExpanded: boolean;
  connectionDraft: SourceInput;
  isSavingSource: boolean;
  onConnectionDraftChange(next: SourceInput): void;
  onSourceSelect(sourceId: string): void;
  onDatabaseSelect(database: string): void;
  onTableSelect(table: string): void;
  onTogglePin(table: string): void;
  onToggleRecent(): void;
  onSaveSource(): void;
}

export function Sidebar({
  sources,
  databases,
  tables,
  selectedSourceId,
  selectedDatabase,
  selectedTable,
  pinnedTables,
  recentTables,
  recentExpanded,
  connectionDraft,
  isSavingSource,
  onConnectionDraftChange,
  onSourceSelect,
  onDatabaseSelect,
  onTableSelect,
  onTogglePin,
  onToggleRecent,
  onSaveSource,
}: SidebarProps) {
  const visiblePinnedTables = pinnedTables.filter(
    (item) => item.sourceId === selectedSourceId && item.database === selectedDatabase,
  );

  const visibleRecentTables = recentTables.filter(
    (item) => item.sourceId === selectedSourceId,
  );

  return (
    <aside className="sidebar">
      <div className="sidebar__brand">
        <div className="sidebar__logo">HS</div>
        <div>
          <div className="sidebar__title">Herd Studio</div>
          <div className="sidebar__subtitle">Desktop rewrite</div>
        </div>
      </div>

      <div className="sidebar__section">
        <label className="sidebar__label">Source</label>
        <div className="source-picker">
          {sources.map((source) => (
            <button
              key={source.id}
              type="button"
              onClick={() => onSourceSelect(source.id)}
              className={clsx(
                "source-picker__item",
                source.id === selectedSourceId && "source-picker__item--active",
              )}
            >
              <div>
                <div className="source-picker__name">{source.name}</div>
                <div className="source-picker__meta">{source.hostLabel}</div>
              </div>
              {source.kind === "local" ? <HardDrive size={12} /> : <Star size={12} />}
            </button>
          ))}
        </div>
      </div>

      <div className="sidebar__section sidebar__section--form">
        <div className="sidebar__group-header">
          <span className="sidebar__label">New Connection</span>
          <Plus size={14} />
        </div>
        <div className="sidebar__form">
          <input
            value={connectionDraft.name}
            onChange={(event) =>
              onConnectionDraftChange({ ...connectionDraft, name: event.target.value })
            }
            placeholder="Forge Production"
          />
          <input
            value={connectionDraft.sshHost}
            onChange={(event) =>
              onConnectionDraftChange({ ...connectionDraft, sshHost: event.target.value })
            }
            placeholder="app.example.com"
          />
          <input
            value={connectionDraft.sshUsername}
            onChange={(event) =>
              onConnectionDraftChange({ ...connectionDraft, sshUsername: event.target.value })
            }
            placeholder="forge"
          />
          <input
            value={connectionDraft.privateKeyPath}
            onChange={(event) =>
              onConnectionDraftChange({
                ...connectionDraft,
                privateKeyPath: event.target.value,
              })
            }
            placeholder="/Users/kyle/.ssh/id_rsa"
          />
          <input
            value={connectionDraft.databaseUsername}
            onChange={(event) =>
              onConnectionDraftChange({
                ...connectionDraft,
                databaseUsername: event.target.value,
              })
            }
            placeholder="MySQL username"
          />
          <input
            type="password"
            value={connectionDraft.databasePassword}
            onChange={(event) =>
              onConnectionDraftChange({
                ...connectionDraft,
                databasePassword: event.target.value,
              })
            }
            placeholder="MySQL password"
          />
          <button
            type="button"
            className="source-picker__create"
            onClick={onSaveSource}
            disabled={isSavingSource}
          >
            {isSavingSource ? "Saving…" : "Save Connection"}
          </button>
        </div>
      </div>

      <div className="sidebar__section">
        <div className="sidebar__group-header">
          <span className="sidebar__label">Databases</span>
          <Search size={13} />
        </div>
        <div className="sidebar__list">
          {databases.map((database) => (
            <button
              key={database.name}
              type="button"
              onClick={() => onDatabaseSelect(database.name)}
              className={clsx(
                "sidebar__item",
                selectedDatabase === database.name && "sidebar__item--active",
              )}
            >
              <span className="sidebar__item-leading">
                <Database size={14} />
                {database.name}
              </span>
              <span className="sidebar__item-meta">{database.tables}</span>
            </button>
          ))}
        </div>
      </div>

      <div className="sidebar__section sidebar__section--grow">
        {visiblePinnedTables.length > 0 ? (
          <div className="sidebar__section">
            <div className="sidebar__group-header">
              <span className="sidebar__label">Pinned</span>
              <Pin size={13} />
            </div>
            <div className="sidebar__list">
              {visiblePinnedTables.map((item) => (
                <button
                  key={item.key}
                  type="button"
                  onClick={() => {
                    onDatabaseSelect(item.database);
                    onTableSelect(item.table);
                  }}
                  className={clsx(
                    "sidebar__item",
                    selectedDatabase === item.database &&
                      selectedTable === item.table &&
                      "sidebar__item--active",
                  )}
                >
                  <span>{item.table}</span>
                  <span className="sidebar__item-meta">{item.database}</span>
                </button>
              ))}
            </div>
          </div>
        ) : null}

        <div className="sidebar__group-header">
          <span className="sidebar__label">Tables</span>
          <span className="sidebar__caption">{selectedDatabase || "Select DB"}</span>
        </div>
        <div className="sidebar__list">
          {tables.map((table) => (
            <button
              key={table.name}
              type="button"
              onClick={() => onTableSelect(table.name)}
              className={clsx(
                "sidebar__item",
                selectedTable === table.name && "sidebar__item--active",
              )}
            >
              <span>{table.name}</span>
              <span className="sidebar__item-actions">
                <span className="sidebar__item-meta">{table.rows}</span>
                <span
                  className="sidebar__pin-button"
                  onClick={(event) => {
                    event.stopPropagation();
                    onTogglePin(table.name);
                  }}
                >
                  {visiblePinnedTables.some((item) => item.table === table.name) ? (
                    <PinOff size={12} />
                  ) : (
                    <Pin size={12} />
                  )}
                </span>
              </span>
            </button>
          ))}
        </div>
      </div>

      <div className="sidebar__section">
        <button type="button" onClick={onToggleRecent} className="sidebar__toggle">
          <span className="sidebar__label">Recent</span>
          {recentExpanded ? <ChevronDown size={14} /> : <ChevronRight size={14} />}
        </button>

        {recentExpanded ? (
          <div className="sidebar__list sidebar__list--muted">
            {visibleRecentTables.length > 0 ? (
              visibleRecentTables.map((item) => (
                <button
                  key={item.key}
                  type="button"
                  className="sidebar__item"
                  onClick={() => {
                    onDatabaseSelect(item.database);
                    onTableSelect(item.table);
                  }}
                >
                  <span>{item.table}</span>
                  <span className="sidebar__item-meta">{item.database}</span>
                </button>
              ))
            ) : (
              <div className="sidebar__empty">No recent tables yet.</div>
            )}
          </div>
        ) : null}
      </div>

      <div className="sidebar__footer">
        <button type="button" className="sidebar__action">
          Actions
        </button>
      </div>
    </aside>
  );
}
