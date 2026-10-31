import { useEffect, useState } from "react";
import { ArrowUpRight, BriefcaseBusiness, Send, X } from "lucide-react";
import { Link } from "wouter";
import { getMyBids, hasApi, withdrawBid } from "@/lib/api";
import { useAuth } from "@/contexts/AuthContext";
import StatusBadge from "@/components/StatusBadge";
import { toast } from "sonner";

function formatBudget(job) {
  return job?.budget_min || job?.budget_max ? `৳${job.budget_min || 0}–${job.budget_max || "open"}` : "Budget to discuss";
}

export default function MyBids() {
  const { user } = useAuth();
  const [bids, setBids] = useState([]);
  const [loading, setLoading] = useState(Boolean(hasApi));
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  const load = async () => {
    if (!hasApi) return;
    setLoading(true);
    setError("");
    try { setBids((await getMyBids()).bids || []); }
    catch (e) { setError(e.message); }
    finally { setLoading(false); }
  };

  useEffect(() => { load(); }, [user?.id]);

  const withdraw = async (bid) => {
    setBusy(true);
    try { await withdrawBid(bid.id); toast.success("Proposal withdrawn."); await load(); }
    catch (e) { toast.error(e.message); }
    finally { setBusy(false); }
  };

  if (!hasApi) return <div className="page-frame"><div className="page-topline"><div><div className="eyebrow"><span className="section-number">04</span> MY BIDS</div><h1>Keep your proposals close.</h1></div></div><div className="honest-state"><strong>Live bids need the Laravel API.</strong><span>Configure <code>VITE_API_BASE_URL</code> to load your proposals.</span></div></div>;

  return <div className="page-frame">
    <div className="page-topline"><div><div className="eyebrow"><span className="section-number">04</span> MY BIDS</div><h1>Keep your proposals close.</h1><p>See where each proposal stands and return to the original brief when the conversation moves.</p></div><Link className="outline-button" href="/jobs"><BriefcaseBusiness size={15} /> Browse open jobs</Link></div>
    {error && <div className="honest-state state-error"><strong>Could not load your bids.</strong><span>{error}</span></div>}
    {loading ? <div className="jobs-register"><div className="job-skeleton large" /><div className="job-skeleton large" /></div> : bids.length === 0 ? <div className="empty-state"><Send size={21} /><strong>No proposals yet.</strong><span>Find an open brief that fits your capability and make the first move.</span><Link className="text-button" href="/jobs">Browse the opportunity register <ArrowUpRight size={14} /></Link></div> : <div className="my-bids-list">{bids.map((bid) => { const job = bid.job_post || bid.jobPost || {}; return <article className="my-bid-card" key={bid.id}><div className="my-bid-marker" /><div className="my-bid-copy"><div className="job-meta"><span>{job.category || "General"}</span><i>·</i><span>{bid.created_at ? new Date(bid.created_at).toLocaleDateString() : "Recently sent"}</span></div><div className="my-record-title"><h2>{job.title || `Job #${bid.job_post_id}`}</h2><StatusBadge status={bid.status || "pending"} /></div><p>{bid.message || "No message provided."}</p><div className="record-facts"><span><b>YOUR PROPOSAL</b>৳{bid.amount}</span><span><b>JOB BUDGET</b>{formatBudget(job)}</span><span><b>JOB STATUS</b>{job.status || "open"}</span></div><div className="record-actions"><Link className="text-button" href={`/jobs/${bid.job_post_id}`}>Open brief <ArrowUpRight size={14} /></Link>{bid.status === "pending" && <button className="text-button danger-button" disabled={busy} onClick={() => withdraw(bid)}><X size={14} /> Withdraw proposal</button>}</div></div></article>; })}</div>}
  </div>;
}
