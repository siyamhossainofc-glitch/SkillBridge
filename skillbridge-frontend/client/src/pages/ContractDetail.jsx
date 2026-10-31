/* Civic Blueprints: a contract detail is a shared ledger—state changes are explicit, reversible where allowed, and never implied. */
import { useEffect, useMemo, useState } from "react";
import { ArrowLeft, Check, CircleDollarSign, ClipboardList, MessageSquareText, Send, X } from "lucide-react";
import { Link, useRoute } from "wouter";
import { useAuth } from "@/contexts/AuthContext";
import StatusBadge from "@/components/StatusBadge";
import {
  cancelContract,
  completeContract,
  startContract,
  createReview,
  depositContract,
  getContract,
  hasApi,
  listReviews,
  listTransactions,
  refundContract,
  releaseContract,
  listMilestones,
  createMilestone,
  updateMilestone,
  deleteMilestone,
  startMilestone,
  submitMilestone,
  approveMilestone,
  createPaymentIntent,
  getPayment,
  getEscrow,
  releaseEscrow,
  refundEscrow,
  listContractDisputes,
  createContractDispute,
  cancelDispute,
  rejectMilestone,
} from "@/lib/api";
import { toast } from "sonner";

export default function ContractDetail() {
  const [, params] = useRoute("/contracts/:id");
  const { user } = useAuth();
  const [contract, setContract] = useState(null);
  const [reviews, setReviews] = useState([]);
  const [transactions, setTransactions] = useState([]);
  const [milestones, setMilestones] = useState([]);
  const [milestoneForm, setMilestoneForm] = useState({ title: "", description: "", amount: "", due_date: "" });
  const [editingMilestone, setEditingMilestone] = useState(null);
  const [payment, setPayment] = useState(null);
  const [escrow, setEscrow] = useState(null);
  const [disputes, setDisputes] = useState([]);
  const [disputeForm, setDisputeForm] = useState({ milestone_id: "", reason: "", description: "" });
  const [loading, setLoading] = useState(Boolean(hasApi && user));
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const [rating, setRating] = useState("5");
  const [comment, setComment] = useState("");

  const load = async () => {
    if (!hasApi || !user || !params?.id) return;
    setLoading(true);
    setError("");
    try {
      const [contractResult, reviewsResult, transactionsResult, milestonesResult, escrowResult, disputesResult] = await Promise.all([
        getContract(params.id),
        listReviews(params.id),
        listTransactions(params.id),
        listMilestones(params.id),
        getEscrow(params.id),
        listContractDisputes(params.id),
      ]);
      setContract(contractResult.contract);
      setReviews(reviewsResult.reviews || []);
      setTransactions(transactionsResult.transactions || []);
      setMilestones(milestonesResult.milestones || []);
      setEscrow(escrowResult.escrow || null);
      setDisputes(disputesResult.disputes || []);
    } catch (e) {
      setError(e.message);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { load(); }, [params?.id, user?.id]);

  const act = async (operation, successMessage) => {
    setBusy(true);
    try {
      await operation();
      await load();
      toast.success(successMessage);
    } catch (e) {
      toast.error(e.message);
    } finally {
      setBusy(false);
    }
  };

  const submitReview = async (event) => {
    event.preventDefault();
    await act(() => createReview(params.id, { rating: Number(rating), comment }), "Review submitted.");
    setComment("");
  };

  const saveMilestone = async (event) => {
    event.preventDefault();
    const payload = { ...milestoneForm, amount: Number(milestoneForm.amount), due_date: milestoneForm.due_date || null };
    await act(() => editingMilestone ? updateMilestone(editingMilestone, payload) : createMilestone(params.id, payload), editingMilestone ? "Milestone updated." : "Milestone created.");
    setMilestoneForm({ title: "", description: "", amount: "", due_date: "" }); setEditingMilestone(null);
  };

  const milestoneAction = async (operation, message) => act(operation, message);

  const initiatePayment = async (milestone) => {
    setBusy(true);
    try {
      const result = await createPaymentIntent(params.id, {
        milestone_id: milestone.id,
        amount: Number(milestone.amount),
        currency: "BDT",
        provider: "mock",
        idempotency_key: `contract-${params.id}-milestone-${milestone.id}-${globalThis.crypto?.randomUUID?.() || `${Date.now()}-${Math.random()}`}`,
      });
      setPayment(result.payment);
      toast.success("Payment intent created. Awaiting provider confirmation.");
    } catch (e) {
      toast.error(e.message);
    } finally {
      setBusy(false);
    }
  };

  const refreshPayment = async () => {
    if (!payment) return;
    setBusy(true);
    try {
      const result = await getPayment(payment.id);
      setPayment(result.payment);
    } catch (e) {
      toast.error(e.message);
    } finally {
      setBusy(false);
    }
  };

  const settleEscrow = async (type) => {
    if (!escrow?.balance || !window.confirm(`${type === "release" ? "Release" : "Refund"} the full held escrow balance?`)) return;
    await act(() => (type === "release" ? releaseEscrow(params.id, { idempotency_key: `escrow-${type}-${params.id}-${Date.now()}` }) : refundEscrow(params.id, { idempotency_key: `escrow-${type}-${params.id}-${Date.now()}` })), `${type === "release" ? "Escrow released" : "Escrow refunded"}.`);
  };

  const submitDispute = async (event) => {
    event.preventDefault();
    await act(() => createContractDispute(params.id, { ...disputeForm, milestone_id: disputeForm.milestone_id || null }), "Dispute opened.");
    setDisputeForm({ milestone_id: "", reason: "", description: "" });
  };

  const heldDeposit = transactions.find(
  (item) => item.type === "deposit" && item.status === "held"
);

const hasDeposit = transactions.some(
  (item) => item.type === "deposit"
);

const isSeeker = contract?.seeker_id === user?.id;
const isProvider = contract?.provider_id === user?.id;
const isParticipant = isSeeker || isProvider;

const canManageMilestones =
  isSeeker &&
  contract &&
  ["pending", "active"].includes(contract.status);

const canOpenDispute =
  isParticipant &&
  contract &&
  ["pending", "active"].includes(contract.status);

const canChangeActiveState =
  contract &&
  ["pending", "active"].includes(contract.status);

const canReleaseFunds =
  isSeeker &&
  contract &&
  contract.status === "completed" &&
  Boolean(heldDeposit);

const reviewee = isSeeker
  ? contract?.provider
  : contract?.seeker;

const alreadyReviewed = reviews.some(
  (item) => item.reviewer_id === user?.id
);

const otherParticipantReviewed = reviews.some(
  (item) => item.reviewer_id === reviewee?.id
);

const reviewPrompt = useMemo(
  () =>
    reviewee?.name
      ? `Rate your work with ${reviewee.name}.`
      : "Leave a review for the other participant.",
  [reviewee?.name]
);

  if (!hasApi) return <div className="page-frame"><Link className="back-link" href="/contracts"><ArrowLeft size={15} /> Back to contracts</Link><div className="honest-state"><strong>Contract details need the Laravel API.</strong><span>Configure <code>VITE_API_BASE_URL</code> first.</span></div></div>;
  if (!user) return <div className="page-frame"><Link className="back-link" href="/auth"><ArrowLeft size={15} /> Sign in</Link><div className="honest-state"><strong>Sign in to view this contract.</strong><span>Contract details are private to participants.</span></div></div>;
  if (loading) return <div className="page-frame"><div className="jobs-register"><div className="job-skeleton large" /><div className="job-skeleton large" /></div></div>;
  if (error || !contract) return <div className="page-frame"><Link className="back-link" href="/contracts"><ArrowLeft size={15} /> Back to contracts</Link><div className="honest-state state-error"><strong>Contract unavailable.</strong><span>{error || "This contract could not be loaded."}</span></div></div>;
  if (!isParticipant) return <div className="page-frame"><Link className="back-link" href="/contracts"><ArrowLeft size={15} /> Back to contracts</Link><div className="honest-state state-error"><strong>Access denied.</strong><span>Only the seeker or provider on this contract can view it.</span></div></div>;

  return (
    <div className="page-frame">
      <Link className="back-link" href="/contracts"><ArrowLeft size={15} /> Back to contracts</Link>
      <div className="detail-grid">
        <article className="brief-sheet">
          <div className="eyebrow"><span className="section-number">06</span> CONTRACT DETAIL</div>
          <div className="detail-title-row"><h1>{contract.jobPost?.title || `Contract #${contract.id}`}</h1><StatusBadge status={contract.status} /></div>
          <p className="brief-description">{contract.jobPost?.description || "The agreed brief is available to both contract participants."}</p>
          <div className="brief-facts">
            <div><span>AMOUNT</span><strong>৳{contract.amount}</strong></div>
            <div><span>SEEKER</span><strong>{contract.seeker?.name || `User #${contract.seeker_id}`}</strong></div>
            <div><span>PROVIDER</span><strong>{contract.provider?.name || `User #${contract.provider_id}`}</strong></div>
            <div><span>STARTED</span><strong>{contract.started_at ? new Date(contract.started_at).toLocaleDateString() : "Not started"}</strong></div>
            <div><span>COMPLETED</span><strong>{contract.completed_at ? new Date(contract.completed_at).toLocaleDateString() : "Not completed"}</strong></div>
          </div>
        </article>
        <aside className="bid-sheet">
          <div className="eyebrow"><span className="section-number">07</span> NEXT ACTION</div>
          <h2>Keep the handoff clear.</h2>
          <p className="panel-intro">Actions are limited to what this contract status and your role allow.</p>
          <div className="hero-actions">
            {contract.status === "pending" && isParticipant && <button disabled={busy} className="primary-button" onClick={() => act(() => startContract(contract.id), "Contract started.")}><Check size={15} /> Start contract</button>}
            {isSeeker && contract.status === "active" && <button disabled={busy} className="primary-button" onClick={() => act(() => completeContract(contract.id), "Contract marked complete.")}><Check size={15} /> Mark complete</button>}
            {canChangeActiveState && <button disabled={busy} className="outline-button" onClick={() => act(() => cancelContract(contract.id), "Contract cancelled.")}><X size={15} /> Cancel</button>}
            {isSeeker && canChangeActiveState && !hasDeposit && <button disabled={busy} className="outline-button" onClick={() => act(() => depositContract(contract.id), "Deposit held.")}><CircleDollarSign size={15} /> Hold deposit</button>}
            {isSeeker && contract.status === "completed" && heldDeposit && <button disabled={busy} className="primary-button" onClick={() => act(() => releaseContract(contract.id), "Funds released.")}><CircleDollarSign size={15} /> Release funds</button>}
            {contract.status && ["cancelled", "disputed"].includes(contract.status) && heldDeposit && <button disabled={busy} className="outline-button" onClick={() => act(() => refundContract(contract.id), "Refund recorded.")}><CircleDollarSign size={15} /> Refund deposit</button>}
          </div>
          {!canChangeActiveState && !heldDeposit && <div className="honest-state compact-state"><strong>No action is available.</strong><span>The current contract state has no permitted participant action.</span></div>}
        </aside>
      </div>

      <section className="context-panel" style={{ marginTop: 24 }}>
        <div className="panel-heading"><div><div className="eyebrow"><span className="section-number">08</span> MILESTONES</div><h2>Track the work.</h2></div><ClipboardList size={24} /></div>
        {canManageMilestones && <form className="bid-form" onSubmit={saveMilestone}><label>Title<input value={milestoneForm.title} onChange={(e) => setMilestoneForm((v) => ({ ...v, title: e.target.value }))} maxLength={255} required /></label><label>Description<textarea value={milestoneForm.description} onChange={(e) => setMilestoneForm((v) => ({ ...v, description: e.target.value }))} maxLength={5000} /></label><label>Amount<input type="number" min="0" step="0.01" value={milestoneForm.amount} onChange={(e) => setMilestoneForm((v) => ({ ...v, amount: e.target.value }))} required /></label><label>Due date<input type="date" value={milestoneForm.due_date} onChange={(e) => setMilestoneForm((v) => ({ ...v, due_date: e.target.value }))} /></label><div className="hero-actions"><button className="primary-button" disabled={busy}>{editingMilestone ? "Update milestone" : "Add milestone"}</button>{editingMilestone && <button type="button" className="outline-button" onClick={() => { setEditingMilestone(null); setMilestoneForm({ title: "", description: "", amount: "", due_date: "" }); }}>Cancel</button>}</div></form>}
        {milestones.length === 0 ? <div className="honest-state compact-state"><strong>No milestones yet.</strong><span>{canManageMilestones ? "Add a milestone to make the delivery plan explicit." : "Milestones will appear here for both participants."}</span></div> : <div className="activity-list">{milestones.map((milestone) => <div className="activity-row" key={milestone.id}><div className="activity-icon activity-bid"><ClipboardList size={15} /></div><div style={{ flex: 1 }}><div className="job-meta"><strong>{milestone.title}</strong><i>·</i><span>{milestone.status}</span></div><p>{milestone.description || "No description provided."} · ৳{milestone.amount}{milestone.due_date ? ` · Due ${milestone.due_date}` : ""}</p><div className="hero-actions">{isProvider && milestone.status === "pending" && contract.status === "active" && <button className="outline-button" type="button" disabled={busy} onClick={() => milestoneAction(() => startMilestone(milestone.id), "Milestone started.")}>Start</button>}{isProvider && ["in_progress", "rejected"].includes(milestone.status) && contract.status === "active" && <button className="outline-button" type="button" disabled={busy} onClick={() => milestoneAction(() => submitMilestone(milestone.id), "Milestone submitted.")}>Submit</button>}{isSeeker && milestone.status === "submitted" && <><button className="primary-button" type="button" disabled={busy} onClick={() => milestoneAction(() => approveMilestone(milestone.id), "Milestone approved.")}>Approve</button><button className="outline-button" type="button" disabled={busy} onClick={() => milestoneAction(() => rejectMilestone(milestone.id), "Milestone rejected.")}>Reject</button></>}{isSeeker && milestone.status === "approved" && <button className="outline-button" type="button" disabled={busy} onClick={() => initiatePayment(milestone)}>Initiate payment</button>}{canManageMilestones && ["pending", "rejected"].includes(milestone.status) && <><button className="outline-button" type="button" disabled={busy} onClick={() => { setEditingMilestone(milestone.id); setMilestoneForm({ title: milestone.title, description: milestone.description || "", amount: milestone.amount, due_date: milestone.due_date || "" }); }}>Edit</button><button className="text-button danger-button" type="button" disabled={busy} onClick={() => milestoneAction(() => deleteMilestone(milestone.id), "Milestone deleted.")}>Delete</button></>}</div>{payment?.milestone_id === milestone.id && <div className="honest-state compact-state"><strong>Payment status: {payment.status}</strong><span>{payment.status === "pending" ? "Awaiting verified provider confirmation. No funds have been marked successful." : payment.status === "succeeded" ? "Provider-confirmed payment." : "Payment requires attention."}</span>{payment.status === "pending" && <button className="text-button" type="button" disabled={busy} onClick={refreshPayment}>Refresh status</button>}</div>}</div></div>)}</div>}
      </section>

      <div className="content-grid contract-lower-grid">
        <section className="context-panel">
          <div className="panel-heading"><div><div className="eyebrow"><span className="section-number">08</span> REVIEWS</div><h2>Close the loop.</h2></div><MessageSquareText size={24} /></div>
          {contract.status !== "completed" ? <div className="honest-state compact-state"><strong>Reviews open after completion.</strong><span>Finish the contract before rating the other participant.</span></div> : alreadyReviewed ? <div className="honest-state compact-state"><strong>Your review is recorded.</strong><span>{otherParticipantReviewed ? "Both participants have submitted a published review." : "The other participant has not submitted a published review yet."}</span></div> : <form className="bid-form" onSubmit={submitReview}><p className="panel-intro">{reviewPrompt} {otherParticipantReviewed ? "The other participant has already reviewed this contract." : "The other participant has not reviewed this contract yet."}</p><label>Rating<select value={rating} onChange={(e) => setRating(e.target.value)}><option value="1">1 — Needs work</option><option value="2">2 — Below expectations</option><option value="3">3 — Solid handoff</option><option value="4">4 — Strong work</option><option value="5">5 — Excellent work</option></select></label><label>Comment<textarea value={comment} onChange={(e) => setComment(e.target.value)} maxLength={2000} placeholder="Describe the handoff honestly." /></label><button disabled={busy} className="primary-button">Submit review <Send size={15} /></button></form>}
          {reviews.length > 0 && <div className="activity-list">{reviews.map((review) => <div className="activity-row" key={review.id}><div className="activity-icon activity-complete"><Check size={15} /></div><div><strong>{review.reviewer?.name || "Participant"} · {review.rating}/5</strong><p>{review.comment || "No comment provided."}</p></div></div>)}</div>}
        </section>
        <section className="context-panel">
          <div className="panel-heading"><div><div className="eyebrow"><span className="section-number">09</span> TRANSACTIONS</div><h2>Follow the funds.</h2></div><CircleDollarSign size={24} /></div>
          {transactions.length === 0 ? <div className="honest-state compact-state"><strong>No transactions yet.</strong><span>The seeker can hold a deposit while the contract is pending or active.</span></div> : <div className="activity-list">{transactions.map((transaction) => <div className="activity-row" key={transaction.id}><div className="activity-icon activity-bid"><CircleDollarSign size={15} /></div><div><strong>{transaction.type} · ৳{transaction.amount}</strong><p>Status: {transaction.status} · Fee: ৳{transaction.platform_fee}</p></div></div>)}</div>}
        </section>
      </div>
      <section className="context-panel" style={{ marginTop: 24 }}>
        <div className="panel-heading"><div><div className="eyebrow"><span className="section-number">10</span> ESCROW</div><h2>Follow the held balance.</h2></div><CircleDollarSign size={24} /></div>
        {!escrow ? <div className="honest-state compact-state"><strong>Escrow unavailable.</strong><span>The server did not return an escrow account for this contract.</span></div> : <><div className="brief-facts"><div><span>BALANCE</span><strong>৳{escrow.balance} {escrow.currency}</strong></div><div><span>STATUS</span><strong>{escrow.status}</strong></div></div><div className="hero-actions">{isSeeker && escrow.balance > 0 && contract.status === "completed" && escrow.status === "open" && <button className="primary-button" disabled={busy} onClick={() => settleEscrow("release")}>Release escrow</button>}{isSeeker && escrow.balance > 0 && ["cancelled", "disputed"].includes(contract.status) && escrow.status === "open" && <button className="outline-button" disabled={busy} onClick={() => settleEscrow("refund")}>Refund escrow</button>}</div>{escrow.entries?.length === 0 ? <div className="honest-state compact-state"><strong>No held escrow entries.</strong><span>Only verified payment confirmations create held escrow.</span></div> : <div className="activity-list">{escrow.entries.map((entry) => <div className="activity-row" key={entry.id}><div className="activity-icon activity-bid"><CircleDollarSign size={15} /></div><div><strong>{entry.type} · ৳{entry.amount}</strong><p>Status: {entry.status}</p></div></div>)}</div>}</>}
      </section>
      <section className="context-panel" style={{ marginTop: 24 }}>
        <div className="panel-heading"><div><div className="eyebrow"><span className="section-number">11</span> DISPUTES</div><h2>Keep resolution explicit.</h2></div><MessageSquareText size={24} /></div>
        {!['cancelled', 'disputed'].includes(contract.status) && <form className="bid-form" onSubmit={submitDispute}><label>Milestone (optional)<select value={disputeForm.milestone_id} onChange={(e) => setDisputeForm((v) => ({ ...v, milestone_id: e.target.value }))}><option value="">Contract-level dispute</option>{milestones.map((milestone) => <option key={milestone.id} value={milestone.id}>{milestone.title}</option>)}</select></label><label>Reason<input value={disputeForm.reason} onChange={(e) => setDisputeForm((v) => ({ ...v, reason: e.target.value }))} maxLength={255} required /></label><label>Description<textarea value={disputeForm.description} onChange={(e) => setDisputeForm((v) => ({ ...v, description: e.target.value }))} maxLength={10000} required /></label><button className="primary-button" disabled={busy}>Open dispute</button></form>}
        {disputes.length === 0 ? <div className="honest-state compact-state"><strong>No disputes for this contract.</strong><span>Opening a dispute does not release or refund held funds.</span></div> : <div className="activity-list">{disputes.map((dispute) => <div className="activity-row" key={dispute.id}><div className="activity-icon activity-bid"><MessageSquareText size={15} /></div><div style={{ flex: 1 }}><strong>{dispute.reason} · {dispute.status}</strong><p>{dispute.description}</p><span>{dispute.milestone?.title || "Contract-level"}{dispute.resolution ? ` · Resolution: ${dispute.resolution}` : ""}</span>{dispute.raised_by === user?.id && dispute.status === "open" && <button className="text-button danger-button" type="button" disabled={busy} onClick={() => act(() => cancelDispute(dispute.id), "Dispute cancelled.")}>Cancel dispute</button>}</div></div>)}</div>}
      </section>
    </div>
  );
}
