/* Civic Blueprints: a brief is an intake sheet; proposals become visible ledger entries with one clear next action. */
import { useEffect, useState } from "react";
import { ArrowLeft, ArrowUpRight, Bookmark, BookmarkCheck, Check, Send, X } from "lucide-react";
import { Link, useRoute } from "wouter";
import { acceptBid, createBid, draftProposal, getJob, getSavedJobs, hasApi, listBids, saveJob, unsaveJob, withdrawBid } from "@/lib/api";
import { useAuth } from "@/contexts/AuthContext";
import StatusBadge from "@/components/StatusBadge";
import { toast } from "sonner";

export default function JobDetail() {
  const [, params] = useRoute("/jobs/:id");
  const { user } = useAuth();
  const [job, setJob] = useState(null);
  const [bids, setBids] = useState([]);
  const [loading, setLoading] = useState(Boolean(hasApi));
  const [error, setError] = useState("");
  const [amount, setAmount] = useState("");
  const [message, setMessage] = useState("");
  const [busy, setBusy] = useState(false);
  const [draftBusy, setDraftBusy] = useState(false);
  const [contract, setContract] = useState(null);
  const [saved, setSaved] = useState(false);
  const [saveBusy, setSaveBusy] = useState(false);

  const load = async () => {
    if (!hasApi || !params?.id) return;
    setLoading(true); setError("");
    try {
      const jobResult = await getJob(params.id); const currentJob = jobResult.job; setJob(currentJob);
      if (user) getSavedJobs().then((result) => setSaved((result.saved_jobs || []).some((record) => record.job?.id === currentJob.id))).catch(() => setSaved(false));
      const isOwner = user?.id === currentJob.user_id || user?.id === currentJob.seeker?.id;
      if (user && (isOwner || user.role === "provider")) { const bidResult = await listBids(params.id); setBids(bidResult.bids || []); } else setBids([]);
    } catch (e) { setError(e.message); } finally { setLoading(false); }
  };

  useEffect(() => { load(); }, [params?.id, user?.id, user?.role]);

  const submit = async (event) => {
    event.preventDefault(); setBusy(true);
    try { await createBid(params.id, { amount: Number(amount), message }); toast.success("Proposal sent to the seeker."); setAmount(""); setMessage(""); await load(); }
    catch (e) { toast.error(e.message); } finally { setBusy(false); }
  };

  const generateDraft = async () => {
    setDraftBusy(true);
    try {
      const result = await draftProposal(params.id, { amount: amount ? Number(amount) : null, draft_message: message || null });
      setAmount(result.proposal?.amount ?? amount); setMessage(result.proposal?.message || message);
      toast.success(result.proposal?.source === "ai-v1" ? "AI draft ready to edit." : "Safe fallback draft ready to edit.");
    } catch (e) { toast.error(e.message); } finally { setDraftBusy(false); }
  };

  const toggleSaved = async () => {
    if (!user) { toast.info("Sign in to save this job."); return; }
    setSaveBusy(true);
    try { if (saved) { await unsaveJob(job.id); setSaved(false); toast.success("Job removed from saved jobs."); } else { await saveJob(job.id); setSaved(true); toast.success("Job saved."); } }
    catch (e) { toast.error(e.message); } finally { setSaveBusy(false); }
  };

  const accept = async (id) => {
    setBusy(true);
    try { const result = await acceptBid(id); setContract(result.contract); await load(); toast.success("Proposal accepted. Contract created."); }
    catch (e) { toast.error(e.message); } finally { setBusy(false); }
  };

  const withdraw = async (id) => {
    setBusy(true);
    try { await withdrawBid(id); await load(); toast.success("Proposal withdrawn."); }
    catch (e) { toast.error(e.message); } finally { setBusy(false); }
  };

  if (loading) return <div className="page-frame"><div className="jobs-register"><div className="job-skeleton large" /><div className="job-skeleton large" /></div></div>;
  if (!hasApi) return <div className="page-frame"><Link className="back-link" href="/jobs"><ArrowLeft size={15} /> Back to register</Link><div className="honest-state"><strong>Live job details need the Laravel API.</strong><span>Configure <code>VITE_API_BASE_URL</code> and open a job from the register.</span></div></div>;
  if (error || !job) return <div className="page-frame"><Link className="back-link" href="/jobs"><ArrowLeft size={15} /> Back to register</Link><div className="honest-state state-error"><strong>Brief unavailable.</strong><span>{error || "This job may have been removed."}</span></div></div>;

  const isOwner = user?.id === job.user_id || user?.id === job.seeker?.id;
  const canViewBids = Boolean(user && (isOwner || user.role === "provider"));
  const myBid = bids.find((bid) => bid.provider_id === user?.id);

  return <div className="page-frame"><Link className="back-link" href="/jobs"><ArrowLeft size={15} /> Back to register</Link><div className="detail-grid"><article className="brief-sheet"><div className="job-meta"><span>{job.category_relation?.name || job.category || "General"}</span>{job.subcategory?.name && <><i>·</i><span>{job.subcategory.name}</span></>}<i>·</i><span>{job.work_mode || "Work mode flexible"}</span><i>·</i><span>{job.location || "Location flexible"}</span></div><div className="detail-title-row"><h1>{job.title}</h1><div><button type="button" className="outline-button" onClick={toggleSaved} disabled={saveBusy}>{saved ? <BookmarkCheck size={15} /> : <Bookmark size={15} />}{saveBusy ? "Saving…" : saved ? "Saved" : "Save job"}</button> <StatusBadge status={job.status || "open"} /></div></div><p className="brief-description">{job.description}</p><div className="brief-facts"><div><span>BUDGET</span><strong>{job.budget_min || job.budget_max ? `৳${job.budget_min || 0}–${job.budget_max || "open"}` : "To discuss"}</strong></div><div><span>POSTED BY</span><strong>{job.seeker?.name || "Seeker"}</strong></div><div><span>WORK MODE</span><strong>{job.work_mode || "Not specified"}</strong></div><div><span>LOCATION</span><strong>{job.location || "Flexible"}</strong></div></div></article><aside className="bid-sheet">{!canViewBids ? <div className="honest-state compact-state"><strong>Proposal details are private.</strong><span>Sign in as a participant to view or submit proposals for this brief.</span></div> : isOwner ? <><div className="eyebrow"><span className="section-number">03</span> PROPOSALS</div><h2>Read the room.</h2><p className="panel-intro">Each proposal is a handoff. Choose the capability that fits the brief.</p><div className="proposal-list">{bids.length === 0 ? <div className="empty-state compact"><strong>No proposals yet.</strong><span>They will appear here when providers respond.</span></div> : bids.map((bid) => <div className="proposal-row" key={bid.id}><div className="provider-avatar">{(bid.provider?.name || "P").slice(0, 2).toUpperCase()}</div><div><strong>{bid.provider?.name || `Provider #${bid.provider_id}`}</strong><p>{bid.message}</p><b>৳{bid.amount}</b></div><div className="proposal-action"><StatusBadge status={bid.status} />{bid.status === "pending" && <button disabled={busy} onClick={() => accept(bid.id)} className="mini-accept"><Check size={13} /> Accept</button>}</div></div>)}</div>{contract && <div className="success-contract"><Check size={16} /><div><strong>Contract created</strong><Link className="text-button" href={`/contracts/${contract.id}`}>Open contract #{contract.id} <ArrowUpRight size={14} /></Link></div></div>}</> : <><div className="eyebrow"><span className="section-number">03</span> YOUR PROPOSAL</div><h2>{myBid ? "Your proposal is in." : "Make the first move."}</h2>{myBid ? <div className="success-contract"><Check size={16} /><div><strong>Submitted for review</strong><span>Status: {myBid.status}. The seeker can see your proposal now.</span>{myBid.status === "pending" && <button disabled={busy} onClick={() => withdraw(myBid.id)} className="text-button"><X size={14} /> Withdraw proposal</button>}</div></div> : <form className="bid-form" onSubmit={submit}><label>Proposed amount<input required type="number" min="1" value={amount} onChange={(e) => setAmount(e.target.value)} placeholder="e.g. 32000" /></label><label>Message<textarea required minLength={10} value={message} onChange={(e) => setMessage(e.target.value)} placeholder="Tell the seeker how you will carry this brief." /></label><div className="hero-actions"><button type="button" disabled={draftBusy} onClick={generateDraft} className="outline-button">{draftBusy ? "Drafting…" : "Draft with AI"}</button><button disabled={busy} className="primary-button">{busy ? "Sending…" : "Send proposal"} <Send size={15} /></button></div><small className="panel-intro">AI drafts are editable and use only this brief and your stored profile data.</small></form>}</>}</aside></div></div>;
}
