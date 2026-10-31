import { useEffect, useState } from 'react';
import { ArrowLeft, ShieldAlert } from 'lucide-react';
import { Link } from 'wouter';
import { useAuth } from '@/contexts/AuthContext';
import { hasApi, getAdminModerationSummary, listAdminAuditLogs, listAdminContent, listAdminUsers, hideAdminContent, restoreAdminContent, suspendAdminUser, restoreAdminUser } from '@/lib/api';
import { toast } from 'sonner';

const tabs = [{ key: 'users', label: 'Users' }, { key: 'jobs', label: 'Jobs' }, { key: 'services', label: 'Services' }, { key: 'portfolio', label: 'Portfolio' }, { key: 'reviews', label: 'Reviews' }, { key: 'audit-logs', label: 'Audit logs' }];

export default function AdminModeration() {
  const { user, loading: authLoading } = useAuth();
  const [tab, setTab] = useState('users');
  const [items, setItems] = useState([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [summary, setSummary] = useState(null);

  const load = async () => {
    if (!user || user.role !== 'admin' || !hasApi) return;
    setLoading(true); setError('');
    try {
      const result = tab === 'users' ? await listAdminUsers() : tab === 'audit-logs' ? await listAdminAuditLogs() : await listAdminContent(tab);
      setItems(result.data || []);
    } catch (e) { setError(e.message); } finally { setLoading(false); }
  };
  useEffect(() => { load(); }, [tab, user?.id]);
  useEffect(() => {
    if (!user || user.role !== 'admin' || !hasApi) return;
    getAdminModerationSummary().then((result) => setSummary(result.counts || null)).catch(() => setSummary(null));
  }, [user?.id]);

  const moderate = async (item, action) => {
    const target = tab;
    if (action === 'hide' && !reason.trim()) { toast.error('A nonblank reason is required to hide content.'); return; }
    if (!window.confirm(action === 'hide' ? 'Hide this content from public APIs?' : action === 'suspend' ? 'Suspend this user from marketplace actions?' : 'Restore this item?')) return;
    setBusy(true);
    try {
      if (target === 'users') action === 'suspend' ? await suspendAdminUser(item.id, reason.trim()) : await restoreAdminUser(item.id);
      else action === 'hide' ? await hideAdminContent(target, item.id, reason.trim()) : await restoreAdminContent(target, item.id);
      toast.success(action === 'hide' ? 'Content hidden.' : action === 'suspend' ? 'User suspended.' : 'Item restored.');
      setReason(''); await load();
    } catch (e) { toast.error(e.message); } finally { setBusy(false); }
  };

  if (!hasApi) return <div className="page-frame"><div className="honest-state state-error"><strong>Admin moderation needs the Laravel API.</strong></div></div>;
  if (authLoading) return <div className="page-frame"><div className="job-skeleton large" /></div>;
  if (!user || user.role !== 'admin') return <div className="page-frame"><Link className="back-link" href="/dashboard"><ArrowLeft size={15} /> Back to dashboard</Link><div className="honest-state state-error"><strong>Admin access required.</strong><span>Moderation is restricted server-side to administrators.</span></div></div>;

  return <div className="page-frame">
    <Link className="back-link" href="/dashboard"><ArrowLeft size={15} /> Back to dashboard</Link>
    <div className="page-topline"><div><div className="eyebrow"><ShieldAlert size={15} /> PLATFORM MODERATION</div><h1>Keep the marketplace trustworthy.</h1><p>Every moderation decision is validated server-side and recorded in the audit log.</p></div></div>
    {summary && <div className="activity-list" aria-label="Moderation summary">{[['users', 'Users', 'active', 'suspended'], ['jobs', 'Jobs', 'visible', 'hidden'], ['services', 'Services', 'visible', 'hidden'], ['portfolio', 'Portfolio', 'visible', 'hidden'], ['reviews', 'Reviews', 'visible', 'hidden']].map(([key, label, first, second]) => <div className="activity-row" key={key}><div style={{ flex: 1 }}><strong>{label}</strong><p>{first}: {summary[key]?.[first] ?? 0} · {second}: {summary[key]?.[second] ?? 0}</p></div></div>)}</div>}
    <div className="hero-actions">{tabs.map((item) => <button key={item.key} type="button" className={tab === item.key ? 'primary-button' : 'outline-button'} onClick={() => { setTab(item.key); setReason(''); }}>{item.label}</button>)}</div>
    {error && <div className="honest-state state-error"><strong>Moderation data unavailable.</strong><span>{error}</span></div>}
    {tab !== 'audit-logs' && tab !== 'users' && <label>Reason for hide actions<textarea value={reason} onChange={(e) => setReason(e.target.value)} maxLength={2000} placeholder="Required when hiding content" /></label>}
    {loading ? <div className="jobs-register"><div className="job-skeleton large" /></div> : items.length === 0 ? <div className="honest-state"><strong>No records found.</strong><span>Try another moderation area or filter.</span></div> : <div className="activity-list">{items.map((item) => {
      const name = item.name || item.title || item.comment || item.action || `#${item.id}`;
      const status = item.moderation_status || item.status || '';
      const owner = item.email || item.user?.name || item.provider?.name || item.reviewer?.name || item.admin?.name || '';
      return <div className="activity-row" key={item.id || `${item.action}-${item.created_at}`}><div className="activity-icon activity-bid"><ShieldAlert size={15} /></div><div style={{ flex: 1 }}><strong>{name}</strong><p>{owner} {item.description ? `· ${item.description}` : ''}</p><span>{status} {item.reason || item.moderation_reason ? `· ${item.reason || item.moderation_reason}` : ''}</span>{tab === 'users' && item.role !== 'admin' && <button type="button" className={status === 'suspended' ? 'primary-button' : 'outline-button'} disabled={busy} onClick={() => moderate(item, status === 'suspended' ? 'restore' : 'suspend')}>{status === 'suspended' ? 'Restore' : 'Suspend'}</button>}{['jobs', 'services', 'portfolio', 'reviews'].includes(tab) && <button type="button" className={status === 'hidden' ? 'primary-button' : 'outline-button'} disabled={busy} onClick={() => moderate(item, status === 'hidden' ? 'restore' : 'hide')}>{status === 'hidden' ? 'Restore' : 'Hide'}</button>}</div></div>;
    })}</div>}
  </div>;
}
