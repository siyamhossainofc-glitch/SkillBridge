import { useEffect, useState } from "react";
import { ArrowLeft, Send } from "lucide-react";
import { Link, useLocation, useRoute } from "wouter";
import { toast } from "sonner";
import { useAuth } from "@/contexts/AuthContext";
import { getJob, hasApi, listCategories, updateJob } from "@/lib/api";

const WORK_MODES = [["remote", "Remote"], ["on-site", "On-site"], ["hybrid", "Hybrid"]];

export default function JobEdit() {
  const [, params] = useRoute("/jobs/:id/edit");
  const [, navigate] = useLocation();
  const { user } = useAuth();
  const [categories, setCategories] = useState([]);
  const [form, setForm] = useState({ title: "", description: "", category_id: "", subcategory_id: "", budget_min: "", budget_max: "", location: "", work_mode: "" });
  const [loading, setLoading] = useState(Boolean(hasApi));
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const category = categories.find((item) => String(item.id) === String(form.category_id));

  useEffect(() => {
    if (!hasApi || !params?.id) return;
    Promise.all([getJob(params.id), listCategories()]).then(([jobResult, categoryResult]) => {
      const job = jobResult.job;
      if (user && job.user_id !== user.id) throw new Error("Only the job owner can edit this brief.");
      setCategories(categoryResult.categories || []);
      setForm({ title: job.title || "", description: job.description || "", category_id: job.category_id || "", subcategory_id: job.subcategory_id || "", budget_min: job.budget_min ?? "", budget_max: job.budget_max ?? "", location: job.location || "", work_mode: job.work_mode || "" });
    }).catch((e) => setError(e.message || "The brief could not be loaded.")).finally(() => setLoading(false));
  }, [params?.id, user?.id]);

  const update = (field) => (event) => setForm((current) => ({ ...current, [field]: event.target.value, ...(field === "category_id" ? { subcategory_id: "" } : {}) }));
  const submit = async (event) => {
    event.preventDefault(); setBusy(true); setError("");
    try {
      const result = await updateJob(params.id, { ...form, category_id: form.category_id ? Number(form.category_id) : null, subcategory_id: form.subcategory_id ? Number(form.subcategory_id) : null, budget_min: form.budget_min === "" ? null : Number(form.budget_min), budget_max: form.budget_max === "" ? null : Number(form.budget_max) });
      toast.success("Job brief updated."); navigate(`/jobs/${result.job?.id || params.id}`);
    } catch (e) { setError(e.message || "The brief could not be updated."); } finally { setBusy(false); }
  };

  if (!hasApi) return <div className="page-frame"><Link className="back-link" href="/my-jobs"><ArrowLeft size={15} /> Back to my jobs</Link><div className="honest-state"><strong>Live editing needs the Laravel API.</strong><span>Configure <code>VITE_API_BASE_URL</code> first.</span></div></div>;
  if (loading) return <div className="page-frame"><div className="jobs-register"><div className="job-skeleton large" /></div></div>;
  return <div className="page-frame"><Link className="back-link" href="/my-jobs"><ArrowLeft size={15} /> Back to my jobs</Link><div className="page-topline"><div><div className="eyebrow"><span className="section-number">05</span> EDIT JOB BRIEF</div><h1>Keep the brief clear.</h1><p>Only open jobs owned by you can be edited.</p></div></div>{error && <div className="honest-state state-error"><strong>Job brief not updated.</strong><span>{error}</span></div>}<form className="brief-sheet bid-form compact" onSubmit={submit}><label>Title<input required maxLength={255} value={form.title} onChange={update("title")} /></label><label>Description<textarea required value={form.description} onChange={update("description")} /></label><div className="content-grid"><label>Category<select required value={form.category_id} onChange={update("category_id")}><option value="">Select a category</option>{categories.map((item) => <option value={item.id} key={item.id}>{item.name}</option>)}</select></label><label>Subcategory<select value={form.subcategory_id} onChange={update("subcategory_id")} disabled={!category}><option value="">Select a subcategory (optional)</option>{(category?.subcategories || []).map((item) => <option value={item.id} key={item.id}>{item.name}</option>)}</select></label></div><div className="content-grid"><label>Work mode<select required value={form.work_mode} onChange={update("work_mode")}><option value="">Select work mode</option>{WORK_MODES.map(([value, label]) => <option value={value} key={value}>{label}</option>)}</select></label><label>Location<input required maxLength={255} value={form.location} onChange={update("location")} /></label></div><div className="content-grid"><label>Minimum budget<input required type="number" min="0" value={form.budget_min} onChange={update("budget_min")} /></label><label>Maximum budget<input required type="number" min="0" value={form.budget_max} onChange={update("budget_max")} /></label></div><button className="primary-button" disabled={busy}>{busy ? "Saving…" : "Save changes"} <Send size={15} /></button></form></div>;
}
