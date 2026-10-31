/* Phase 4 Task 1: AI assists the manual job brief; the existing create endpoint remains authoritative. */
import { useEffect, useMemo, useState } from "react";
import { ArrowLeft, ArrowUpRight, BrainCircuit, Send } from "lucide-react";
import { Link, useLocation } from "wouter";
import { toast } from "sonner";
import { useAuth } from "@/contexts/AuthContext";
import { createJob, hasApi, listCategories, understandJob } from "@/lib/api";

const emptyForm = { title: "", description: "", category_id: "", subcategory_id: "", required_skill_ids: [], budget_min: "", budget_max: "", location: "", work_mode: "", deadline: "", urgency: "", preferred_experience: "" };

export default function JobCreate() {
  const [, navigate] = useLocation();
  const { user } = useAuth();
  const [categories, setCategories] = useState([]);
  const [busy, setBusy] = useState(false);
  const [understandingBusy, setUnderstandingBusy] = useState(false);
  const [error, setError] = useState("");
  const [understandingText, setUnderstandingText] = useState("");
  const [form, setForm] = useState(emptyForm);

  useEffect(() => {
    if (!hasApi) return;
    listCategories().then((result) => setCategories(result.categories || [])).catch((e) => setError(e.message || "Categories could not be loaded."));
  }, []);

  const category = categories.find((item) => String(item.id) === String(form.category_id));
  const skills = useMemo(() => categories.flatMap((item) => item.skills || []), [categories]);
  const update = (field) => (event) => setForm((current) => ({ ...current, [field]: event.target.value, ...(field === "category_id" ? { subcategory_id: "" } : {}) }));

  const understand = async () => {
    if (!understandingText.trim()) { setError("Describe the request first so AI can identify its requirements."); return; }
    if (!hasApi) { setError("Connect VITE_API_BASE_URL to use AI understanding."); return; }
    setError(""); setUnderstandingBusy(true);
    try {
      const result = await understandJob(understandingText.trim());
      const requirements = result.requirements || {};
      setForm((current) => ({ ...current, category_id: requirements.category_id ? String(requirements.category_id) : current.category_id, subcategory_id: requirements.subcategory_id ? String(requirements.subcategory_id) : current.subcategory_id, required_skill_ids: requirements.required_skill_ids || [], work_mode: requirements.work_mode || current.work_mode, location: requirements.location || current.location, budget_min: requirements.budget_min ?? current.budget_min, budget_max: requirements.budget_max ?? current.budget_max, deadline: requirements.deadline || current.deadline, urgency: requirements.urgency || current.urgency, preferred_experience: requirements.preferred_experience || current.preferred_experience }));
      toast.success("Requirements understood. Review them before posting.");
    } catch (e) { setError(`${e.message || "AI understanding failed."} You can continue with the manual form.`); }
    finally { setUnderstandingBusy(false); }
  };

  const toggleSkill = (skillId) => setForm((current) => ({ ...current, required_skill_ids: current.required_skill_ids.includes(skillId) ? current.required_skill_ids.filter((id) => id !== skillId) : [...current.required_skill_ids, skillId] }));
  const submit = async (event) => {
    event.preventDefault(); setError("");
    if (!hasApi) { setError("Connect VITE_API_BASE_URL to a reachable Laravel API before posting a job."); return; }
    if (!user) { setError("Sign in as a seeker before posting a job."); return; }
    setBusy(true);
    try {
      const result = await createJob({ ...form, category_id: Number(form.category_id), subcategory_id: form.subcategory_id ? Number(form.subcategory_id) : null, required_skill_ids: form.required_skill_ids, budget_min: form.budget_min || null, budget_max: form.budget_max || null, deadline: form.deadline || null, urgency: form.urgency || null, preferred_experience: form.preferred_experience || null });
      toast.success("Job brief posted."); navigate(result.job?.id ? `/jobs/${result.job.id}` : "/jobs");
    } catch (e) { setError(e.message || "The job could not be posted."); } finally { setBusy(false); }
  };

  return <div className="page-frame"><Link className="back-link" href="/"><ArrowLeft size={15} /> Back to overview</Link><div className="page-topline"><div><div className="eyebrow"><span className="section-number">05</span> NEW JOB BRIEF</div><h1>Give good work a clear beginning.</h1><p>Describe the outcome, constraints, and budget so providers can make a useful first move.</p></div><Link className="text-button" href="/jobs">Browse existing jobs <ArrowUpRight size={15} /></Link></div>{error && <div className="honest-state state-error"><strong>Job brief needs attention.</strong><span>{error}</span></div>}<section className="brief-sheet" aria-labelledby="ai-understanding-heading"><h2 id="ai-understanding-heading"><BrainCircuit size={18} /> Understand a request with AI</h2><p>Optional assistance only. Review every field; if AI is unavailable, use the manual form below.</p><textarea value={understandingText} onChange={(event) => setUnderstandingText(event.target.value)} maxLength={10000} placeholder="Example: I need an urgent remote React developer for a two-week dashboard project, budget ৳50,000–৳80,000." /><button type="button" className="outline-button" onClick={understand} disabled={understandingBusy}>{understandingBusy ? "Understanding…" : "Understand requirements"} <BrainCircuit size={15} /></button></section><form className="brief-sheet bid-form compact" onSubmit={submit}><label>Title<input required maxLength={255} value={form.title} onChange={update("title")} placeholder="What needs to be done?" /></label><label>Description<textarea required value={form.description} onChange={update("description")} placeholder="Describe the outcome and important context." /></label><div className="content-grid"><label>Category<select required value={form.category_id} onChange={update("category_id")}><option value="">Select a category</option>{categories.map((item) => <option value={item.id} key={item.id}>{item.name}</option>)}</select></label><label>Subcategory<select value={form.subcategory_id} onChange={update("subcategory_id")} disabled={!category}><option value="">Select a subcategory (optional)</option>{(category?.subcategories || []).map((item) => <option value={item.id} key={item.id}>{item.name}</option>)}</select></label></div><label>Required skills <span className="field-note">Select or review AI suggestions</span><div className="skill-chip-list">{skills.map((skill) => <button type="button" key={skill.id} className={form.required_skill_ids.includes(skill.id) ? "primary-button" : "outline-button"} onClick={() => toggleSkill(skill.id)}>{skill.name}</button>)}</div></label><div className="content-grid"><label>Work mode<select required value={form.work_mode} onChange={update("work_mode")}><option value="">Select work mode</option><option value="remote">Remote</option><option value="on-site">On-site</option><option value="hybrid">Hybrid</option></select></label><label>Location<input required maxLength={255} value={form.location} onChange={update("location")} placeholder="Remote or city" /></label></div><div className="content-grid"><label>Minimum budget<input required type="number" min="0" value={form.budget_min} onChange={update("budget_min")} placeholder="৳" /></label><label>Maximum budget<input required type="number" min="0" value={form.budget_max} onChange={update("budget_max")} placeholder="৳" /></label></div><div className="content-grid"><label>Deadline<input type="date" value={form.deadline} onChange={update("deadline")} /></label><label>Urgency<select value={form.urgency} onChange={update("urgency")}><option value="">Not specified</option><option value="low">Low</option><option value="normal">Normal</option><option value="high">High</option><option value="urgent">Urgent</option></select></label></div><label>Preferred experience<input maxLength={255} value={form.preferred_experience} onChange={update("preferred_experience")} placeholder="Example: 3+ years in React" /></label><button className="primary-button" disabled={busy || !categories.length}>{busy ? "Posting…" : "Post job brief"} <Send size={15} /></button></form></div>;
}
