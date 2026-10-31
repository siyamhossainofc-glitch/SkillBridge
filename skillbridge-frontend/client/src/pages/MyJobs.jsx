import { useEffect, useState } from "react";
import { ArrowUpRight, BriefcaseBusiness, Check, Plus, Trash2, X } from "lucide-react";
import { Link } from "wouter";
import { acceptBid, cancelJob, deleteJob, getMyJobs, hasApi, listBids } from "@/lib/api";
import { useAuth } from "@/contexts/AuthContext";
import StatusBadge from "@/components/StatusBadge";
import { toast } from "sonner";

function formatBudget(job) {
  return job.budget_min || job.budget_max ? `৳${job.budget_min || 0}–${job.budget_max || "open"}` : "Budget to discuss";
}

export default function MyJobs() {
  const { user } = useAuth();
  const [jobs, setJobs] = useState([]);
  const [bidsByJob, setBidsByJob] = useState({});
  const [loading, setLoading] = useState(Boolean(hasApi));
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  const load = async () => {
    if (!hasApi) return;
    setLoading(true);
    setError("");
    try {
      const result = await getMyJobs();
      const nextJobs = result.jobs || result.data || [];
      setJobs(nextJobs);
      const bidResults = await Promise.all(nextJobs.map(async (job) => {
        try { return [job.id, (await listBids(job.id)).bids || []]; }
        catch { return [job.id, []]; }
      }));
      setBidsByJob(Object.fromEntries(bidResults));
    } catch (e) {
      setError(e.message);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { load(); }, [user?.id]);

  const act = async (action, success) => {
    setBusy(true);
    try { await action(); toast.success(success); await load(); }
    catch (e) { toast.error(e.message); }
    finally { setBusy(false); }
  };

  const confirmCancel = (job) => {
    if (window.confirm(`Cancel “${job.title}”? Providers will no longer be able to bid.`)) {
      act(() => cancelJob(job.id), "Job cancelled.");
    }
  };

  const confirmDelete = (job) => {
    if (window.confirm(`Delete “${job.title}”? This cannot be undone.`)) {
      act(() => deleteJob(job.id), "Job deleted.");
    }
  };

  if (!hasApi) return <div className="page-frame"><div className="page-topline"><div><div className="eyebrow"><span className="section-number">03</span> MY JOBS</div><h1>Own the brief.</h1></div></div><div className="honest-state"><strong>Live jobs need the Laravel API.</strong><span>Configure <code>VITE_API_BASE_URL</code> to load your posted jobs.</span></div></div>;

  return <div className="page-frame">
    <div className="page-topline"><div><div className="eyebrow"><span className="section-number">03</span> MY JOBS</div><h1>Own the brief.</h1><p>Track every job you posted and review the proposals arriving at the door.</p></div><Link className="primary-button" href="/jobs/new"><Plus size={15} /> Post a new job</Link></div>
    {error && <div className="honest-state state-error"><strong>Could not load your jobs.</strong><span>{error}</span></div>}
    {loading ? <div className="jobs-register"><div className="job-skeleton large" /><div className="job-skeleton large" /></div> : jobs.length === 0 ? <div className="empty-state"><BriefcaseBusiness size={21} /><strong>No jobs posted yet.</strong><span>Create your first brief and let the right capability find you.</span><Link className="text-button" href="/jobs/new">Post a job brief <ArrowUpRight size={14} /></Link></div> : <div className="my-record-list">{jobs.map((job) => {
      const bids = bidsByJob[job.id] || [];
      const canManage = job.status === "open";
      return <article className="my-record-card" key={job.id}>
        <div className="my-record-main"><div className="job-meta"><span>{job.category_relation?.name || job.category || "General"}</span><i>·</i><span>{job.created_at ? new Date(job.created_at).toLocaleDateString() : "Recently posted"}</span></div><div className="my-record-title"><h2>{job.title}</h2><StatusBadge status={job.status || "open"} /></div><p>{job.description || "No description provided."}</p><div className="record-facts"><span><b>BUDGET</b>{formatBudget(job)}</span><span><b>WORK MODE</b>{job.work_mode || "Not specified"}</span><span><b>LOCATION</b>{job.location || "Flexible"}</span><span><b>PROPOSALS</b>{bids.length}</span></div><div className="record-actions"><Link className="text-button" href={`/jobs/${job.id}`}>Open brief <ArrowUpRight size={14} /></Link>{canManage && <><Link className="outline-button" href={`/jobs/${job.id}/edit`}>Edit brief</Link><button className="outline-button" disabled={busy} onClick={() => confirmCancel(job)}><X size={14} /> Cancel</button>{bids.length === 0 && <button className="text-button danger-button" disabled={busy} onClick={() => confirmDelete(job)}><Trash2 size={14} /> Delete</button>}</>}</div></div>
        <aside className="received-bids"><div className="panel-heading"><div><div className="eyebrow">RECEIVED PROPOSALS</div><h3>{bids.length ? `${bids.length} ${bids.length === 1 ? "proposal" : "proposals"}` : "Waiting for responses"}</h3></div></div>{bids.length === 0 ? <p className="record-muted">Responses will appear here as providers bid.</p> : bids.map((bid) => <div className="received-bid" key={bid.id}><div className="provider-avatar">{(bid.provider?.name || "P").slice(0, 2).toUpperCase()}</div><div className="received-bid-copy"><strong>{bid.provider?.name || `Provider #${bid.provider_id}`}</strong><span>৳{bid.amount}</span><p>{bid.message || "No message provided."}</p></div><div className="received-bid-action"><StatusBadge status={bid.status} />{bid.status === "pending" && <button className="mini-accept" disabled={busy} onClick={() => act(() => acceptBid(bid.id), "Proposal accepted. Contract created.")}><Check size={13} /> Accept</button>}</div></div>)}</aside>
      </article>;
    })}</div>}
  </div>;
}
