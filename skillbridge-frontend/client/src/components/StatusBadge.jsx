/* Civic Blueprints: statuses read like ledger stamps, with restrained semantic color and uppercase labels. */
const tone = { open: "status-open", pending: "status-pending", active: "status-active", in_progress: "status-active", completed: "status-completed", cancelled: "status-cancelled", rejected: "status-cancelled", accepted: "status-completed" };
export default function StatusBadge({ status = "unknown" }) { return <span className={`status-badge ${tone[status] || "status-neutral"}`}>{String(status).replaceAll("_", " ")}</span>; }
