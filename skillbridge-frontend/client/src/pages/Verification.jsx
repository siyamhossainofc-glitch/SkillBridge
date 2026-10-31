import { useEffect, useState } from "react";
import { ArrowLeft, ShieldCheck } from "lucide-react";
import { Link } from "wouter";
import { getVerification, hasApi, submitVerification } from "@/lib/api";
import { useAuth } from "@/contexts/AuthContext";
import { toast } from "sonner";

const labels = { unverified: "Not submitted", pending: "Pending review", verified: "Verified", rejected: "Rejected" };

export default function Verification() {
  const { user, loading: authLoading } = useAuth();
  const [verification, setVerification] = useState(null);
  const [loading, setLoading] = useState(Boolean(hasApi));
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  const load = () => {
    if (!hasApi) return;
    setLoading(true); setError("");
    getVerification().then((response) => setVerification(response.verification)).catch((err) => setError(err.message)).finally(() => setLoading(false));
  };

  useEffect(() => {
    if (!authLoading && user?.role === "provider") load();
  }, [authLoading, user?.role]);

  const submit = async () => {
    setBusy(true); setError("");
    try {
      const response = await submitVerification();
      setVerification(response.verification);
      toast.success("Verification request submitted.");
    } catch (err) { setError(err.message); }
    finally { setBusy(false); }
  };

  if (!hasApi) return <div className="page-frame"><Link className="back-link" href="/profile"><ArrowLeft size={15} /> Back to profile</Link><div className="honest-state"><strong>Verification needs the Laravel API.</strong><span>Configure <code>VITE_API_BASE_URL</code> first.</span></div></div>;
  if (authLoading || loading) return <div className="page-frame"><div className="jobs-register"><div className="job-skeleton large" /></div></div>;
  if (!user || user.role !== "provider") return <div className="page-frame"><Link className="back-link" href="/profile"><ArrowLeft size={15} /> Back to profile</Link><div className="honest-state state-error"><strong>Provider access required.</strong><span>Only providers can manage verification.</span></div></div>;

  const status = verification?.status || "unverified";
  const canSubmit = status === "unverified" || status === "rejected";
  return <div className="page-frame">
    <Link className="back-link" href="/profile"><ArrowLeft size={15} /> Back to profile</Link>
    <div className="section-heading"><div><div className="eyebrow">TRUST & VERIFICATION</div><h1>Build confidence.</h1><p className="panel-intro">Submit your verification request for review. Sensitive documents are not collected in this workflow.</p></div><ShieldCheck size={34} strokeWidth={1.2} /></div>
    {error && <div className="honest-state state-error"><strong>Verification action failed.</strong><span>{error}</span></div>}
    <section className="brief-sheet">
      <div className="job-meta"><span>STATUS</span><i>·</i><strong>{labels[status] || status}</strong></div>
      {status === "verified" && <p className="status-active">Your provider profile is marked Verified.</p>}
      {status === "pending" && <p> your request is waiting for administrator review.</p>}
      {status === "rejected" && <div className="honest-state state-error"><strong>Review feedback</strong><span>{verification.rejection_reason || "Please resubmit when ready."}</span></div>}
      {canSubmit && <button type="button" className="primary-button" onClick={submit} disabled={busy}>{busy ? "Submitting…" : status === "rejected" ? "Resubmit for review" : "Submit for review"}</button>}
    </section>
  </div>;
}
