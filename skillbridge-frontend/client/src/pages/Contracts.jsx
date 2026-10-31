/* Civic Blueprints: contracts are a calm ledger of agreed handoffs, with explicit status and no invented activity. */
import { useEffect, useState } from "react";
import { ArrowLeft, ArrowUpRight, FileCheck2 } from "lucide-react";
import { Link } from "wouter";
import { getContracts, hasApi } from "@/lib/api";
import { useAuth } from "@/contexts/AuthContext";
import StatusBadge from "@/components/StatusBadge";

export default function Contracts() {
  const { user } = useAuth();
  const [contracts, setContracts] = useState([]);
  const [loading, setLoading] = useState(Boolean(hasApi && user));
  const [error, setError] = useState("");

  useEffect(() => {
    if (!hasApi || !user) return;
    setLoading(true);
    getContracts()
      .then((payload) => setContracts(payload.contracts || []))
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, [user?.id]);

  if (!hasApi) return <div className="page-frame"><Link className="back-link" href="/dashboard"><ArrowLeft size={15} /> Back to workspace</Link><div className="honest-state"><strong>Contracts need the Laravel API.</strong><span>Configure <code>VITE_API_BASE_URL</code> before opening the contract ledger.</span></div></div>;
  if (!user) return <div className="page-frame"><Link className="back-link" href="/auth"><ArrowLeft size={15} /> Sign in</Link><div className="honest-state"><strong>Sign in to view contracts.</strong><span>Only contract participants can access these records.</span></div></div>;
  if (loading) return <div className="page-frame"><div className="jobs-register"><div className="job-skeleton large" /><div className="job-skeleton large" /></div></div>;
  if (error) return <div className="page-frame"><Link className="back-link" href="/dashboard"><ArrowLeft size={15} /> Back to workspace</Link><div className="honest-state state-error"><strong>Contract ledger unavailable.</strong><span>{error}</span></div></div>;

  return (
    <div className="page-frame">
      <Link className="back-link" href="/dashboard"><ArrowLeft size={15} /> Back to workspace</Link>
      <div className="section-heading"><div><div className="eyebrow"><span className="section-number">05</span> CONTRACT LEDGER</div><h1>Agreed work, clearly held.</h1><p className="panel-intro">Every active handoff, completion, and next action in one place.</p></div><FileCheck2 size={32} strokeWidth={1.2} /></div>
      {contracts.length === 0 ? <div className="honest-state"><strong>No contracts to show yet.</strong><span>Accepted bids will appear here as shared work records.</span></div> : <div className="job-list">{contracts.map((contract) => { const other = contract.seeker_id === user.id ? contract.provider : contract.seeker; return <Link className="job-card contract-card" href={`/contracts/${contract.id}`} key={contract.id}><div className="job-marker marker-blue"></div><div className="job-main"><div className="job-meta"><span>{contract.jobPost?.category || "Contract"}</span><i>·</i><span>{other?.name || "Other participant"}</span></div><h3>{contract.jobPost?.title || `Contract #${contract.id}`}</h3><div className="job-footer"><strong>৳{contract.amount}</strong><StatusBadge status={contract.status} /></div></div><ArrowUpRight size={18} /></Link>; })}</div>}
    </div>
  );
}
