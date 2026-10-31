/* Task 6B — In-app Messaging: conversation-based (no job_post_id required). */
import { useEffect, useRef, useState, useCallback } from "react";
import { MessageSquareText, Send, Plus, ChevronLeft, RefreshCw } from "lucide-react";
import {
  hasApi,
  listConversations,
  startConversation,
  getConversationMessages,
  sendConversationMessage,
  markMessageRead,
  listMessagingUsers,
} from "@/lib/api";
import { useAuth } from "@/contexts/AuthContext";
import { toast } from "sonner";

function formatDate(value) {
  if (!value) return "";
  return new Date(value).toLocaleString([], { dateStyle: "medium", timeStyle: "short" });
}

function formatShort(value) {
  if (!value) return "";
  const d = new Date(value);
  const now = new Date();
  if (d.toDateString() === now.toDateString()) {
    return d.toLocaleTimeString([], { timeStyle: "short" });
  }
  return d.toLocaleDateString([], { dateStyle: "short" });
}

// ── New Conversation Dialog ────────────────────────────────────────────────────
function NewConversationDialog({ onStart, onClose }) {
  const [search, setSearch] = useState("");
  const [recipients, setRecipients] = useState([]);
  const [selectedRecipient, setSelectedRecipient] = useState(null);
  const [searching, setSearching] = useState(true);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    let active = true;
    setSearching(true);
    const timer = setTimeout(() => {
      listMessagingUsers({ search, per_page: 12 })
        .then((data) => { if (active) setRecipients(data.data || []); })
        .catch((err) => { if (active) setError(err.message || "Could not find providers."); })
        .finally(() => { if (active) setSearching(false); });
    }, 250);
    return () => { active = false; clearTimeout(timer); };
  }, [search]);

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (!selectedRecipient) { setError("Select a user to continue."); return; }
    setLoading(true); setError("");
    try {
      const data = await startConversation(selectedRecipient.id);
      onStart(data.conversation);
    } catch (err) {
      setError(err.message || "Could not start conversation.");
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="modal-backdrop" onClick={onClose}>
      <div className="modal-panel" onClick={(e) => e.stopPropagation()}>
        <div className="panel-heading">
          <div>
            <div className="eyebrow">NEW CONVERSATION</div>
            <h2>Message a user</h2>
          </div>
        </div>
        <form onSubmit={handleSubmit} style={{ display: "flex", flexDirection: "column", gap: 12, padding: "0 0 4px" }}>
          <label style={{ fontSize: 13, fontWeight: 600, color: "#253754" }}>
            Search users
            <input
              className="field-input"
              type="search"
              placeholder="Search by name, title, bio, or location…"
              value={search}
              onChange={(e) => { setSearch(e.target.value); setSelectedRecipient(null); setError(""); }}
              style={{ marginTop: 6 }}
              autoFocus
            />
          </label>
          <div style={{ display: "flex", flexDirection: "column", gap: 6, maxHeight: 220, overflowY: "auto" }}>
            {searching ? <span style={{ fontSize: 13, color: "#777" }}>Searching users…</span> : recipients.length === 0 ? <span style={{ fontSize: 13, color: "#777" }}>No eligible users found.</span> : recipients.map((recipient) => (
              <button type="button" key={recipient.id} onClick={() => { setSelectedRecipient(recipient); setError(""); }} className={selectedRecipient?.id === recipient.id ? "conversation-item conversation-selected" : "conversation-item"} style={{ textAlign: "left", width: "100%" }}>
                <strong>{recipient.name}</strong>
                <span style={{ display: "block", fontSize: 12, color: "#777" }}>{recipient.professional_title || (recipient.role === "provider" ? "Provider" : "Seeker")}{recipient.address ? ` · ${recipient.address}` : ""}</span>
              </button>
            ))}
          </div>
          {selectedRecipient && <p style={{ fontSize: 13, color: "#253754", margin: 0 }}>Selected: <strong>{selectedRecipient.name}</strong></p>}
          {error && <p style={{ color: "#c0392b", fontSize: 13 }}>{error}</p>}
          <div style={{ display: "flex", gap: 8, justifyContent: "flex-end" }}>
            <button type="button" className="outline-button" onClick={onClose}>Cancel</button>
            <button type="submit" className="primary-button" disabled={loading || !selectedRecipient}>
              {loading ? "Starting…" : "Start conversation"}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

// ── Main Page ─────────────────────────────────────────────────────────────────
export default function Messages() {
  const { user } = useAuth();
  const deepLinkedConversationId = new URLSearchParams(window.location.search).get("conversation");
  const [conversations, setConversations]   = useState([]);
  const [conversationPagination, setConversationPagination] = useState(null);
  const [selected, setSelected]             = useState(null);
  const [messages, setMessages]             = useState([]);
  const [pagination, setPagination]         = useState(null);
  const [draft, setDraft]                   = useState("");
  const [loading, setLoading]               = useState(Boolean(hasApi));
  const [threadLoading, setThreadLoading]   = useState(false);
  const [sending, setSending]               = useState(false);
  const [loadingMore, setLoadingMore]       = useState(false);
  const [loadingMoreConversations, setLoadingMoreConversations] = useState(false);
  const [error, setError]                   = useState("");
  const [threadError, setThreadError]       = useState("");
  const [showNew, setShowNew]               = useState(false);
  const [mobileView, setMobileView]         = useState("list"); // "list" | "thread"
  const endRef    = useRef(null);
  const inputRef  = useRef(null);

  // ── Load conversation list ─────────────────────────────────────────────────
  const loadConversations = useCallback(async (keepSelection = true, page = 1) => {
    if (!hasApi) return;
    if (page === 1) setLoading(true); else setLoadingMoreConversations(true);
    setError("");
    try {
      const data = await listConversations({ page, per_page: deepLinkedConversationId && page === 1 ? 50 : 15 });
      const list = data.conversations || data.data || [];
      setConversations((prev) => page === 1 ? list : [...prev, ...list]);
      setConversationPagination(data.meta || null);
      const deepLinkedConversation = deepLinkedConversationId && page === 1
        ? list.find((conversation) => String(conversation.id) === deepLinkedConversationId)
        : null;
      if (deepLinkedConversation) {
        setSelected(deepLinkedConversation);
      } else if (keepSelection && selected) {
        const refreshed = list.find((c) => c.id === selected.id);
        if (refreshed) setSelected(refreshed);
      } else if (!selected && !deepLinkedConversationId && list.length) {
        setSelected(list[0]);
      }
    } catch (e) { setError(e.message); }
    finally { setLoading(false); setLoadingMoreConversations(false); }
  }, [selected]);

  // ── Load thread ───────────────────────────────────────────────────────────
  const loadThread = useCallback(async (conv, page = 1) => {
    if (!conv) return;
    setThreadLoading(page === 1); setThreadError("");
    try {
      const data = await getConversationMessages(conv.id, page);
      const incoming = data.data || [];
      setMessages((prev) => page === 1 ? incoming : [...prev, ...incoming]);
      setPagination(data.pagination || null);

      // Mark unread messages from the other user as read
      incoming
        .filter((m) => !m.is_read && m.sender_id !== user?.id)
        .forEach((m) => markMessageRead(m.id).catch(() => {}));
    } catch (e) {
      setMessages([]); setThreadError(e.message);
    } finally { setThreadLoading(false); }
  }, [user?.id]);

  useEffect(() => { loadConversations(false); }, [user?.id]);
  useEffect(() => {
    if (selected) {
      setMessages([]); setPagination(null);
      loadThread(selected, 1);
      setMobileView("thread");
      inputRef.current?.focus();
    }
  }, [selected?.id]);
  useEffect(() => { endRef.current?.scrollIntoView({ behavior: "smooth" }); }, [messages.length]);

  // ── Send ──────────────────────────────────────────────────────────────────
  const submit = async (e) => {
    e.preventDefault();
    const body = draft.trim();
    if (!body || !selected || sending) return;
    setSending(true);
    try {
      await sendConversationMessage(selected.id, body);
      setDraft("");
      await loadThread(selected, 1);
      await loadConversations(true);
    } catch (e) { toast.error(e.message); }
    finally { setSending(false); }
  };

  const handleKeyDown = (e) => {
    if (e.key === "Enter" && !e.shiftKey) { e.preventDefault(); submit(e); }
  };

  // ── New conversation callback ─────────────────────────────────────────────
  const handleNewConversation = (conv) => {
    setShowNew(false);
    setConversations((prev) => {
      const existing = prev.find((c) => c.id === conv.id);
      return existing ? prev : [conv, ...prev];
    });
    setSelected(conv);
  };

  // ── Load more ─────────────────────────────────────────────────────────────
  const loadMore = async () => {
    if (!selected || !pagination || pagination.current_page >= pagination.last_page) return;
    setLoadingMore(true);
    await loadThread(selected, pagination.current_page + 1);
    setLoadingMore(false);
  };

  const loadMoreConversations = () => {
    if (!conversationPagination || conversationPagination.current_page >= conversationPagination.last_page || loadingMoreConversations) return;
    loadConversations(true, conversationPagination.current_page + 1);
  };

  if (!hasApi) {
    return (
      <div className="page-frame">
        <div className="page-topline">
          <div>
            <div className="eyebrow"><span className="section-number">06</span> MESSAGES</div>
            <h1>In-app Messaging</h1>
          </div>
        </div>
        <div className="honest-state">
          <strong>Live messaging needs the Laravel API.</strong>
          <span>Configure <code>VITE_API_BASE_URL</code> to use this feature.</span>
        </div>
      </div>
    );
  }

  return (
    <div className="page-frame messages-page">
      {showNew && (
        <NewConversationDialog onStart={handleNewConversation} onClose={() => setShowNew(false)} />
      )}

      <div className="page-topline">
        <div>
          <div className="eyebrow"><span className="section-number">06</span> MESSAGES</div>
          <h1>In-app Messaging</h1>
          <p>Direct, authenticated conversations between users.</p>
        </div>
        <div style={{ display: "flex", gap: 8, alignItems: "center" }}>
          <button className="outline-button" onClick={() => loadConversations(true)} title="Refresh">
            <RefreshCw size={14} />
          </button>
          <button className="primary-button" onClick={() => setShowNew(true)}>
            <Plus size={15} /> New conversation
          </button>
        </div>
      </div>

      {error && (
        <div className="honest-state state-error">
          <strong>Could not load conversations.</strong>
          <span>{error}</span>
        </div>
      )}

      <div className="messages-layout">
        {/* ── Sidebar: conversation list ── */}
        <aside className={`conversation-sidebar ${mobileView === "thread" ? "mobile-hidden" : ""}`}>
          <div className="panel-heading">
            <div>
              <div className="eyebrow">INBOX</div>
              <h2>Conversations</h2>
            </div>
            <MessageSquareText size={22} />
          </div>

          {loading ? (
            <div className="message-loading">Loading conversations…</div>
          ) : conversations.length === 0 ? (
            <div className="message-empty">
              <strong>No conversations yet.</strong>
              <span>Start one by clicking "New conversation" above.</span>
            </div>
          ) : (
            <div className="conversation-list">
              {conversations.map((conv) => {
                const isSelected = selected?.id === conv.id;
                return (
                  <button
                    key={conv.id}
                    className={`conversation-item ${isSelected ? "conversation-selected" : ""}`}
                    onClick={() => setSelected(conv)}
                  >
                    <div className="provider-avatar">
                      {(conv.other_user?.name || "U").slice(0, 2).toUpperCase()}
                    </div>
                    <div className="conversation-copy">
                      <strong>{conv.other_user?.name || `User #${conv.other_user?.id}`}</strong>
                      <p className="conv-preview">{conv.last_message || "No messages yet."}</p>
                      {conv.last_message_at && (
                        <time style={{ fontSize: 11, color: "#88857f" }}>
                          {formatShort(conv.last_message_at)}
                        </time>
                      )}
                    </div>
                    {conv.unread_count > 0 && (
                      <b className="unread-count">{conv.unread_count}</b>
                    )}
                  </button>
                );
              })}
              {conversationPagination && conversationPagination.current_page < conversationPagination.last_page && (
                <button className="outline-button" type="button" onClick={loadMoreConversations} disabled={loadingMoreConversations} style={{ width: "100%", marginTop: 8 }}>
                  {loadingMoreConversations ? "Loading…" : "Load more conversations"}
                </button>
              )}
            </div>
          )}
        </aside>

        {/* ── Thread panel ── */}
        <section className={`thread-panel ${mobileView === "list" ? "mobile-hidden" : ""}`}>
          {!selected ? (
            <div className="thread-placeholder">
              <MessageSquareText size={27} />
              <strong>Select a conversation.</strong>
              <span>Choose a thread to read the message history.</span>
            </div>
          ) : (
            <>
              <header className="thread-header">
                <div style={{ display: "flex", alignItems: "center", gap: 10 }}>
                  <button
                    className="outline-button mobile-back"
                    onClick={() => setMobileView("list")}
                    aria-label="Back to conversations"
                  >
                    <ChevronLeft size={15} />
                  </button>
                  <div>
                    <div className="eyebrow">CONVERSATION</div>
                    <h2>{selected.other_user?.name || `User #${selected.other_user?.id}`}</h2>
                  </div>
                </div>
              </header>

              <div className="message-history">
                {/* Load more older messages */}
                {pagination && pagination.current_page < pagination.last_page && (
                  <div style={{ textAlign: "center", padding: "8px 0" }}>
                    <button
                      className="outline-button"
                      onClick={loadMore}
                      disabled={loadingMore}
                      style={{ fontSize: 12 }}
                    >
                      {loadingMore ? "Loading…" : "Load older messages"}
                    </button>
                  </div>
                )}

                {threadLoading ? (
                  <div className="message-loading">Loading messages…</div>
                ) : threadError ? (
                  <div className="honest-state state-error">
                    <strong>Could not load this conversation.</strong>
                    <span>{threadError}</span>
                  </div>
                ) : messages.length === 0 ? (
                  <div className="message-empty">
                    <strong>No messages yet.</strong>
                    <span>Send the first message below.</span>
                  </div>
                ) : (
                  messages.map((msg) => {
                    const mine = msg.sender_id === user?.id;
                    return (
                      <div
                        key={msg.id}
                        className={`message-row ${mine ? "message-mine" : "message-theirs"}`}
                      >
                        <div className="message-bubble">
                          {!mine && (
                            <span className="message-sender">{msg.sender_name}</span>
                          )}
                          <p>{msg.body}</p>
                          <div style={{ display: "flex", gap: 6, alignItems: "center", justifyContent: "flex-end" }}>
                            <time style={{ fontSize: 11, opacity: 0.7 }}>{formatDate(msg.created_at)}</time>
                            {mine && (
                              <span style={{ fontSize: 10, opacity: 0.6 }} title={msg.is_read ? "Read" : "Unread"}>
                                {msg.is_read ? "✓✓" : "✓"}
                              </span>
                            )}
                          </div>
                        </div>
                      </div>
                    );
                  })
                )}
                <div ref={endRef} />
              </div>

              <form className="message-composer" onSubmit={submit}>
                <textarea
                  ref={inputRef}
                  value={draft}
                  onChange={(e) => setDraft(e.target.value)}
                  onKeyDown={handleKeyDown}
                  placeholder="Write a message… (Enter to send, Shift+Enter for newline)"
                  maxLength={5000}
                  rows={2}
                  aria-label="Message"
                  style={{ resize: "vertical", minHeight: 40 }}
                />
                <button
                  type="submit"
                  className="primary-button"
                  disabled={!draft.trim() || sending}
                >
                  {sending ? "Sending…" : <><Send size={15} /> Send</>}
                </button>
              </form>
            </>
          )}
        </section>
      </div>
    </div>
  );
}
