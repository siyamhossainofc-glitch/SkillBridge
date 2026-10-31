import { useEffect, useState } from "react";
import { ArrowLeft, Check, MessageSquareText, X } from "lucide-react";
import { Link } from "wouter";
import { useAuth } from "@/contexts/AuthContext";
import { hasApi, listAdminDisputes, rejectDispute, resolveDispute } from "@/lib/api";
import { toast } from "sonner";

export default function AdminDisputes() {
  const { user } = useAuth();
  const [disputes, setDisputes] = useState([]);
  const [selected, setSelected] = useState(null);
  const [resolution, setResolution] = useState("");
  const [loading, setLoading] = useState(Boolean(hasApi && user));
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  const load = async () => {
    if (!hasApi || !user || user.role !== "admin") return;
    setLoading(true); setError("");
    try {
      const result = await listAdminDisputes();
      setDisputes(result.data || []);
    } catch (e) { setError(e.message); } finally { setLoading(false); }
  };
  useEffect(() => { load(); }, [user?.id]);

  const decide = async (type) => {
    if (!selected || !resolution.trim()) { toast.error("A resolution note is required."); return; }
    setBusy(true);
    try {
      await (type === "resolve" ? resolveDispute(selected.id, resolution) : rejectDispute(selected.id, resolution));
      toast.success(type === "resolve" ? "Dispute resolved." : "Dispute rejected.");
      setSelected(null); setResolution(""); await load();
    } catch (e) { toast.error(e.message); } finally { setBusy(false); }
  };

  if (!hasApi || !user) return <div className="page-frame"><Link className="back-link" href="/auth"><ArrowLeft size={15} /> Sign in</Link><div className="honest-state"><strong>Sign in to review disputes.</strong></div></div>;
  if (user.role !== "admin") return <div className="page-frame"><div className="honest-state state-error"><strong>Admin access required.</strong><span>Dispute resolution is restricted to administrators.</span></div></div>;
  if (loading) return <div className="page-frame"><div className="jobs-register"><div className="job-skeleton large" /></div></div>;
  if (error) return <div className="page-frame"><div className="honest-state state-error"><strong>Disputes unavailable.</strong><span>{error}</span></div></div>;

  return <div className="page-frame">
    <div className="eyebrow"><span className="section-number">12</span> ADMIN DISPUTE REVIEW</div>
    <h1>Review disputes without moving funds.</h1>
    <p className="brief-description">Resolution notes are recorded server-side. Opening or resolving a dispute does not automatically release or refund escrow.</p>
    {disputes.length === 0 ? <div className="honest-state"><strong>No pending disputes.</strong><span>Open and under-review disputes will appear here.</span></div> : <div className="activity-list">{disputes.map((dispute) => <div className="activity-row" key={dispute.id}><div className="activity-icon activity-bid"><MessageSquareText size={15} /></div><div style={{ flex: 1 }}><strong>#{dispute.id} · {dispute.reason}</strong><p>{dispute.description}</p><span>Contract #{dispute.contract_id} · {dispute.status}</span><button className="outline-button" type="button" onClick={() => { setSelected(dispute); setResolution(""); }}>Review</button></div></div>)}</div>}
    {selected && <section className="context-panel" style={{ marginTop: 24 }}><div className="panel-heading"><div><div className="eyebrow">SELECTED DISPUTE</div><h2>{selected.reason}</h2></div><MessageSquareText size={24} /></div><p>{selected.description}</p><label>Resolution note<textarea value={resolution} onChange={(e) => setResolution(e.target.value)} maxLength={10000} required placeholder="Record the decision and its reasoning." /></label><div className="hero-actions"><button className="primary-button" disabled={busy || !resolution.trim()} onClick={() => decide("resolve")}><Check size={15} /> Resolve</button><button className="outline-button" disabled={busy || !resolution.trim()} onClick={() => decide("reject")}><X size={15} /> Reject</button><button className="text-button" disabled={busy} onClick={() => setSelected(null)}>Close</button></div></section>}
  </div>;
}
