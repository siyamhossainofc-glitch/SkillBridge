/* NotificationBell — header badge + dropdown panel for Task 6A notifications */
import { useEffect, useRef, useState } from "react";
import { Link } from "wouter";
import { getUnreadCount, getNotifications, markNotificationRead, markAllNotificationsRead } from "@/lib/api";

function timeAgo(dateStr) {
  const diff = Math.floor((Date.now() - new Date(dateStr)) / 1000);
  if (diff < 60) return "just now";
  if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
  if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
  return `${Math.floor(diff / 86400)}d ago`;
}

export default function NotificationBell({ liveApi }) {
  const [unread, setUnread] = useState(0);
  const [open, setOpen] = useState(false);
  const [notifications, setNotifications] = useState([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);
  const panelRef = useRef(null);
  const pollRef = useRef(null);

  // ── Fetch unread count (polls every 60s while mounted) ──────────────────────
  const refreshCount = () => {
    if (!liveApi) return;
    getUnreadCount().then((r) => setUnread(r.unread_count ?? 0)).catch(() => {});
  };

  useEffect(() => {
    refreshCount();
    pollRef.current = setInterval(refreshCount, 60_000);
    return () => clearInterval(pollRef.current);
  }, [liveApi]);

  // ── Close panel on outside click ────────────────────────────────────────────
  useEffect(() => {
    if (!open) return;
    const handler = (e) => {
      if (panelRef.current && !panelRef.current.contains(e.target)) setOpen(false);
    };
    document.addEventListener("mousedown", handler);
    return () => document.removeEventListener("mousedown", handler);
  }, [open]);

  // ── Load notifications when panel opens ────────────────────────────────────
  const openPanel = () => {
    setOpen((prev) => !prev);
    if (!open && liveApi) {
      setLoading(true);
      setError(null);
      getNotifications(1, 15)
        .then((r) => setNotifications(r.data ?? []))
        .catch(() => setError("Couldn't load notifications."))
        .finally(() => setLoading(false));
    }
  };

  // ── Mark single read ────────────────────────────────────────────────────────
  const handleMarkRead = async (id) => {
    await markNotificationRead(id).catch(() => {});
    setNotifications((prev) =>
      prev.map((n) => (n.id === id ? { ...n, read_at: new Date().toISOString() } : n))
    );
    setUnread((c) => Math.max(0, c - 1));
  };

  // ── Mark all read ───────────────────────────────────────────────────────────
  const handleMarkAllRead = async () => {
    await markAllNotificationsRead().catch(() => {});
    setNotifications((prev) => prev.map((n) => ({ ...n, read_at: n.read_at ?? new Date().toISOString() })));
    setUnread(0);
  };

  const handleNotificationClick = async (notification) => {
    if (!notification.read_at) await handleMarkRead(notification.id);
    if (notification.data?.link) window.location.href = notification.data.link;
    else setOpen(false);
  };

  return (
    <div className="notif-bell-wrap" ref={panelRef} style={{ position: "relative", display: "inline-flex" }}>
      <button
        className="notif-bell-btn"
        onClick={openPanel}
        aria-label={`Notifications${unread > 0 ? `, ${unread} unread` : ""}`}
        style={{
          background: "none",
          border: "none",
          cursor: "pointer",
          position: "relative",
          padding: "4px 6px",
          color: "#253754",
          fontSize: 18,
          lineHeight: 1,
        }}
      >
        🔔
        {unread > 0 && (
          <span
            style={{
              position: "absolute",
              top: 0,
              right: 0,
              background: "#e54242",
              color: "#fff",
              borderRadius: "9999px",
              fontSize: 10,
              fontWeight: 700,
              minWidth: 16,
              height: 16,
              display: "flex",
              alignItems: "center",
              justifyContent: "center",
              padding: "0 3px",
              lineHeight: 1,
            }}
          >
            {unread > 99 ? "99+" : unread}
          </span>
        )}
      </button>

      {open && (
        <div
          style={{
            position: "absolute",
            top: "calc(100% + 8px)",
            right: 0,
            width: 340,
            maxHeight: 440,
            overflowY: "auto",
            background: "#fff",
            border: "1px solid #e2e0dc",
            borderRadius: 10,
            boxShadow: "0 4px 20px rgba(0,0,0,0.12)",
            zIndex: 1000,
          }}
        >
          {/* Header */}
          <div
            style={{
              display: "flex",
              alignItems: "center",
              justifyContent: "space-between",
              padding: "12px 16px",
              borderBottom: "1px solid #e2e0dc",
              position: "sticky",
              top: 0,
              background: "#fff",
            }}
          >
            <span style={{ fontWeight: 700, fontSize: 14, color: "#253754" }}>Notifications</span>
            <div style={{ display: "flex", gap: 8 }}>
              {unread > 0 && (
                <button
                  onClick={handleMarkAllRead}
                  style={{
                    fontSize: 11,
                    color: "#2457d6",
                    background: "none",
                    border: "none",
                    cursor: "pointer",
                    padding: 0,
                  }}
                >
                  Mark all read
                </button>
              )}
              <Link
                href="/notifications"
                style={{ fontSize: 11, color: "#2457d6", textDecoration: "none" }}
                onClick={() => setOpen(false)}
              >
                View all
              </Link>
            </div>
          </div>

          {/* Body */}
          {loading && (
            <div style={{ padding: 24, textAlign: "center", color: "#88857f", fontSize: 13 }}>
              Loading…
            </div>
          )}

          {error && (
            <div style={{ padding: 16, color: "#b44", fontSize: 13, textAlign: "center" }}>
              {error}
            </div>
          )}

          {!loading && !error && notifications.length === 0 && (
            <div style={{ padding: 24, textAlign: "center", color: "#88857f", fontSize: 13 }}>
              No notifications yet.
            </div>
          )}

          {!loading &&
            !error &&
            notifications.map((n) => (
              <div
                key={n.id}
                onClick={() => handleNotificationClick(n)}
                style={{
                  padding: "12px 16px",
                  borderBottom: "1px solid #f0eeeb",
                  cursor: n.read_at ? "default" : "pointer",
                  background: n.read_at ? "#fff" : "#f4f6ff",
                  transition: "background 0.15s",
                }}
              >
                <div style={{ display: "flex", alignItems: "flex-start", gap: 8 }}>
                  {!n.read_at && (
                    <span
                      style={{
                        width: 8,
                        height: 8,
                        borderRadius: "50%",
                        background: "#2457d6",
                        flexShrink: 0,
                        marginTop: 5,
                      }}
                    />
                  )}
                  <div style={{ flex: 1, minWidth: 0 }}>
                    <div style={{ fontWeight: n.read_at ? 400 : 600, fontSize: 13, color: "#253754" }}>
                      {n.title}
                    </div>
                    <div
                      style={{
                        fontSize: 12,
                        color: "#5a5855",
                        marginTop: 2,
                        overflow: "hidden",
                        textOverflow: "ellipsis",
                        whiteSpace: "nowrap",
                      }}
                    >
                      {n.body}
                    </div>
                    <div style={{ fontSize: 11, color: "#88857f", marginTop: 4 }}>
                      {timeAgo(n.created_at)}
                    </div>
                  </div>
                </div>
              </div>
            ))}
        </div>
      )}
    </div>
  );
}
