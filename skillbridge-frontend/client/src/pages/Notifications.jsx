/* Notifications page — Task 6A full notification list with pagination */
import { useEffect, useState } from "react";
import { useAuth } from "@/contexts/AuthContext";
import { getNotifications, markNotificationRead, markAllNotificationsRead } from "@/lib/api";
import { toast } from "sonner";
import { Link } from "wouter";

function timeAgo(dateStr) {
  const diff = Math.floor((Date.now() - new Date(dateStr)) / 1000);
  if (diff < 60) return "just now";
  if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
  if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
  return `${Math.floor(diff / 86400)}d ago`;
}

export default function Notifications() {
  const { user, liveApi } = useAuth();
  const [notifications, setNotifications] = useState([]);
  const [pagination, setPagination] = useState(null);
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  const load = (p = 1) => {
    if (!liveApi) { setLoading(false); return; }
    setLoading(true);
    setError(null);
    getNotifications(p, 20)
      .then((r) => {
        setNotifications(r.data ?? []);
        setPagination(r.pagination ?? null);
        setPage(p);
      })
      .catch(() => setError("Failed to load notifications."))
      .finally(() => setLoading(false));
  };

  useEffect(() => { load(1); }, [liveApi]);

  const handleMarkRead = async (id) => {
    await markNotificationRead(id).catch(() => toast.error("Couldn't mark read."));
    setNotifications((prev) =>
      prev.map((n) => (n.id === id ? { ...n, read_at: new Date().toISOString() } : n))
    );
  };

  const handleMarkAllRead = async () => {
    await markAllNotificationsRead().catch(() => toast.error("Couldn't mark all read."));
    setNotifications((prev) => prev.map((n) => ({ ...n, read_at: n.read_at ?? new Date().toISOString() })));
    toast.success("All notifications marked as read.");
  };

  const unreadCount = notifications.filter((n) => !n.read_at).length;

  const handleNotificationClick = async (notification) => {
    if (!notification.read_at) await handleMarkRead(notification.id);
    if (notification.data?.link) window.location.href = notification.data.link;
  };

  if (!user) {
    return (
      <main style={{ padding: "48px 24px", textAlign: "center" }}>
        <p>Please <Link href="/auth">sign in</Link> to view notifications.</p>
      </main>
    );
  }

  return (
    <main style={{ maxWidth: 680, margin: "0 auto", padding: "32px 16px" }}>
      {/* Page header */}
      <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", marginBottom: 24 }}>
        <h1 style={{ fontSize: 22, fontWeight: 700, color: "#253754", margin: 0 }}>
          Notifications
          {unreadCount > 0 && (
            <span
              style={{
                marginLeft: 10,
                background: "#2457d6",
                color: "#fff",
                borderRadius: 9999,
                fontSize: 12,
                fontWeight: 700,
                padding: "1px 8px",
              }}
            >
              {unreadCount} unread
            </span>
          )}
        </h1>
        {unreadCount > 0 && (
          <button
            onClick={handleMarkAllRead}
            style={{
              fontSize: 13,
              color: "#2457d6",
              background: "none",
              border: "1px solid #2457d6",
              borderRadius: 6,
              padding: "5px 12px",
              cursor: "pointer",
            }}
          >
            Mark all read
          </button>
        )}
      </div>

      {/* States */}
      {!liveApi && (
        <div style={{ padding: 32, textAlign: "center", color: "#88857f", fontSize: 14 }}>
          Notifications require a live API connection.
        </div>
      )}

      {liveApi && loading && (
        <div style={{ padding: 48, textAlign: "center", color: "#88857f" }}>Loading…</div>
      )}

      {liveApi && error && (
        <div style={{ padding: 24, textAlign: "center", color: "#b44", fontSize: 14 }}>
          {error}{" "}
          <button onClick={() => load(page)} style={{ color: "#2457d6", background: "none", border: "none", cursor: "pointer" }}>
            Retry
          </button>
        </div>
      )}

      {liveApi && !loading && !error && notifications.length === 0 && (
        <div
          style={{
            padding: 48,
            textAlign: "center",
            color: "#88857f",
            fontSize: 14,
            border: "1px dashed #d6d3ce",
            borderRadius: 10,
          }}
        >
          You have no notifications yet.
        </div>
      )}

      {/* List */}
      {!loading && !error && notifications.length > 0 && (
        <div style={{ borderRadius: 10, border: "1px solid #e2e0dc", overflow: "hidden" }}>
          {notifications.map((n, i) => (
            <div
              key={n.id}
              style={{
                display: "flex",
                gap: 12,
                padding: "14px 18px",
                background: n.read_at ? "#fff" : "#f4f6ff",
                borderBottom: i < notifications.length - 1 ? "1px solid #eeece8" : "none",
                cursor: n.read_at ? "default" : "pointer",
                transition: "background 0.15s",
              }}
              onClick={() => handleNotificationClick(n)}
            >
              {/* Unread dot */}
              <div style={{ paddingTop: 5, width: 10, flexShrink: 0 }}>
                {!n.read_at && (
                  <span
                    style={{
                      display: "block",
                      width: 8,
                      height: 8,
                      borderRadius: "50%",
                      background: "#2457d6",
                    }}
                  />
                )}
              </div>

              <div style={{ flex: 1 }}>
                <div
                  style={{
                    fontWeight: n.read_at ? 400 : 600,
                    fontSize: 14,
                    color: "#253754",
                    marginBottom: 3,
                  }}
                >
                  {n.title}
                </div>
                <div style={{ fontSize: 13, color: "#5a5855", lineHeight: 1.4 }}>{n.body}</div>
                <div style={{ fontSize: 11, color: "#88857f", marginTop: 5 }}>{timeAgo(n.created_at)}</div>
              </div>

              {!n.read_at && (
                <button
                  onClick={(e) => { e.stopPropagation(); handleMarkRead(n.id); }}
                  style={{
                    fontSize: 11,
                    color: "#2457d6",
                    background: "none",
                    border: "none",
                    cursor: "pointer",
                    flexShrink: 0,
                    alignSelf: "center",
                    padding: "4px 8px",
                  }}
                >
                  Mark read
                </button>
              )}
            </div>
          ))}
        </div>
      )}

      {/* Pagination */}
      {pagination && pagination.last_page > 1 && (
        <div style={{ display: "flex", justifyContent: "center", gap: 8, marginTop: 20 }}>
          <button
            disabled={page === 1}
            onClick={() => load(page - 1)}
            style={{
              padding: "6px 14px",
              border: "1px solid #d6d3ce",
              borderRadius: 6,
              cursor: page === 1 ? "not-allowed" : "pointer",
              background: "#fff",
              color: page === 1 ? "#bbb" : "#253754",
            }}
          >
            ← Prev
          </button>
          <span style={{ padding: "6px 4px", fontSize: 13, color: "#88857f" }}>
            {page} / {pagination.last_page}
          </span>
          <button
            disabled={page === pagination.last_page}
            onClick={() => load(page + 1)}
            style={{
              padding: "6px 14px",
              border: "1px solid #d6d3ce",
              borderRadius: 6,
              cursor: page === pagination.last_page ? "not-allowed" : "pointer",
              background: "#fff",
              color: page === pagination.last_page ? "#bbb" : "#253754",
            }}
          >
            Next →
          </button>
        </div>
      )}
    </main>
  );
}
