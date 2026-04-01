import type { SidebarState } from "./types";

const STORAGE_KEY = "herd-studio-desktop.sidebar";

const defaultSidebarState: SidebarState = {
  recentExpanded: false,
  pinnedTables: [],
  recentTables: [],
};

export function loadSidebarState(): SidebarState {
  try {
    const raw = window.localStorage.getItem(STORAGE_KEY);

    if (!raw) {
      return defaultSidebarState;
    }

    return {
      ...defaultSidebarState,
      ...JSON.parse(raw),
    };
  } catch {
    return defaultSidebarState;
  }
}

export function saveSidebarState(state: SidebarState): void {
  window.localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
}
