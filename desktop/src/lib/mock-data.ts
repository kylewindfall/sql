import type { DatabaseItem, RowRecord, Source, TableItem, TableView } from "./types";

export const mockSources: Source[] = [
  {
    id: "local-herd",
    name: "Local Herd",
    kind: "local",
    hostLabel: "127.0.0.1 · Herd MySQL",
    hasDatabasePassword: false,
    isPinned: true,
  },
  {
    id: "forge-production",
    name: "Forge Production",
    kind: "ssh",
    hostLabel: "forge@app.example.com",
    hasDatabasePassword: true,
  },
];

export const mockDatabases: DatabaseItem[] = [
  { name: "goodneighbor", tables: 12 },
  { name: "sandbox", tables: 5 },
];

export const mockTables: TableItem[] = [
  { database: "goodneighbor", name: "listings", rows: 148 },
  { database: "goodneighbor", name: "cities", rows: 349 },
  { database: "goodneighbor", name: "users", rows: 24 },
  { database: "goodneighbor", name: "activities", rows: 812 },
];

const listingRows: RowRecord[] = [
  {
    id: 1,
    title: "Mountain Retreat",
    city_id: 18,
    host_id: 7,
    status: "published",
    nightly_rate: 245,
    updated_at: "2026-03-31 22:15:17",
  },
  {
    id: 2,
    title: "Downtown Loft",
    city_id: 5,
    host_id: 12,
    status: "draft",
    nightly_rate: 189,
    updated_at: "2026-03-31 19:04:11",
  },
  {
    id: 3,
    title: "River Cabin",
    city_id: 18,
    host_id: 7,
    status: "published",
    nightly_rate: 212,
    updated_at: "2026-03-30 14:41:55",
  },
];

export const mockView: TableView = {
  database: "goodneighbor",
  table: "listings",
  columns: [
    { name: "id", type: "bigint", nullable: false, primary: true, width: 96 },
    { name: "title", type: "varchar", nullable: false, width: 280 },
    {
      name: "city_id",
      type: "bigint",
      nullable: false,
      width: 130,
      referencedTable: "cities",
      referencedColumn: "id",
      inferredRelation: true,
    },
    {
      name: "host_id",
      type: "bigint",
      nullable: false,
      width: 130,
      referencedTable: "users",
      referencedColumn: "id",
      inferredRelation: true,
    },
    { name: "status", type: "varchar", nullable: false, width: 140 },
    { name: "nightly_rate", type: "decimal", nullable: false, width: 140 },
    { name: "updated_at", type: "datetime", nullable: true, width: 220 },
  ],
  rows: listingRows,
  totalRows: listingRows.length,
  page: 1,
  perPage: 50,
  totalPages: 1,
  relatedPreviews: {
    "0:city_id": {
      summary: "Boulder",
      fields: [
        { label: "id", value: "18" },
        { label: "name", value: "Boulder" },
        { label: "state", value: "Colorado" },
      ],
    },
    "0:host_id": {
      summary: "Avery Martin",
      fields: [
        { label: "id", value: "7" },
        { label: "email", value: "avery@example.com" },
        { label: "role", value: "host" },
      ],
    },
    "1:city_id": {
      summary: "Denver",
      fields: [
        { label: "id", value: "5" },
        { label: "name", value: "Denver" },
        { label: "state", value: "Colorado" },
      ],
    },
  },
};
