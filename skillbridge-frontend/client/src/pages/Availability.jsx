import { useEffect, useState } from "react";
import { ArrowLeft, Pencil, Plus, Trash2 } from "lucide-react";
import { Link } from "wouter";
import { createAvailability, deleteAvailability, getAvailability, hasApi, updateAvailability } from "@/lib/api";
import { useAuth } from "@/contexts/AuthContext";
import { toast } from "sonner";

const days = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"];
const blank = { day_of_week: "1", start_time: "09:00", end_time: "17:00", is_active: true };

export default function Availability() {
  const { user, loading: authLoading } = useAuth();
  const [items, setItems] = useState([]);
  const [form, setForm] = useState(blank);
  const [editing, setEditing] = useState(null);
  const [loading, setLoading] = useState(Boolean(hasApi));
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  const load = () => {
    if (!hasApi) return;
    setLoading(true);
    getAvailability().then((r) => setItems(r.availability || [])).catch((e) => setError(e.message)).finally(() => setLoading(false));
  };

  useEffect(() => {
    if (!authLoading && user?.role === "provider") load();
  }, [authLoading, user?.role]);

  const change = (key) => (e) => setForm((v) => ({ ...v, [key]: key === "is_active" ? e.target.checked : e.target.value }));
  const reset = () => { setEditing(null); setForm(blank); };
  const save = async (e) => {
    e.preventDefault(); setBusy(true); setError("");
    try {
      const payload = { ...form, day_of_week: Number(form.day_of_week) };
      if (editing) await updateAvailability(editing, payload); else await createAvailability(payload);
      toast.success(editing ? "Availability updated." : "Availability added."); reset(); load();
    } catch (err) { setError(err.message); } finally { setBusy(false); }
  };
  const remove = async (id) => {
    if (!window.confirm("Remove this availability window?")) return;
    setBusy(true);
    try { await deleteAvailability(id); toast.success("Availability removed."); load(); }
    catch (err) { setError(err.message); } finally { setBusy(false); }
  };

  if (!hasApi) return <div className="page-frame"><Link className="back-link" href="/profile"><ArrowLeft size={15} /> Back to profile</Link><div className="honest-state"><strong>Availability management needs the Laravel API.</strong><span>Configure <code>VITE_API_BASE_URL</code> first.</span></div></div>;
  if (authLoading) return <div className="page-frame"><div className="jobs-register"><div className="job-skeleton large" /></div></div>;
  if (!user || user.role !== "provider") return <div className="page-frame"><Link className="back-link" href="/profile"><ArrowLeft size={15} /> Back to profile</Link><div className="honest-state state-error"><strong>Provider access required.</strong><span>Only providers can manage availability.</span></div></div>;
  return <div className="page-frame"><Link className="back-link" href="/profile"><ArrowLeft size={15} /> Back to profile</Link><div className="page-topline"><div><div className="eyebrow">PROVIDER SETTINGS</div><h1>Availability.</h1><p>Share your regular working windows. This is informational only, not a booking calendar.</p></div></div><div className="content-grid"><section className="brief-sheet"><div className="eyebrow">{editing ? "EDIT WINDOW" : "ADD WINDOW"}</div><h2>{editing ? "Update a schedule." : "Set a weekly schedule."}</h2><form className="bid-form" onSubmit={save}><label>Day<select value={form.day_of_week} onChange={change("day_of_week")}>{days.map((day, index) => <option value={index} key={day}>{day}</option>)}</select></label><label>Start time<input type="time" value={form.start_time} onChange={change("start_time")} required /></label><label>End time<input type="time" value={form.end_time} onChange={change("end_time")} required /></label><label className="checkbox-label"><input type="checkbox" checked={form.is_active} onChange={change("is_active")} /> Active on public profile</label>{error && <div className="honest-state state-error"><strong>Could not save.</strong><span>{error}</span></div>}<div className="hero-actions"><button className="primary-button" disabled={busy}>{busy ? "Saving…" : editing ? "Update window" : "Add window"} <Plus size={15} /></button>{editing && <button type="button" className="outline-button" onClick={reset}>Cancel</button>}</div></form></section><section className="brief-sheet"><div className="eyebrow">WEEKLY WINDOWS</div><h2>Your schedule.</h2>{loading ? <div className="job-skeleton large" /> : items.length === 0 ? <div className="empty-state"><strong>No availability added yet.</strong><span>Add a recurring window to tell clients when you are generally available.</span></div> : <div className="jobs-register">{items.map((item) => <article className="live-job-row" key={item.id}><div className="live-job-copy"><div className="job-meta"><span>{days[item.day_of_week]}</span><i>·</i><span className={item.is_active ? "status-active" : "status-neutral"}>{item.is_active ? "Active" : "Inactive"}</span></div><h2>{item.start_time.slice(0, 5)} – {item.end_time.slice(0, 5)}</h2></div><div className="hero-actions"><button className="outline-button" onClick={() => { setEditing(item.id); setForm({ day_of_week: String(item.day_of_week), start_time: item.start_time.slice(0, 5), end_time: item.end_time.slice(0, 5), is_active: Boolean(item.is_active) }); }}><Pencil size={14} /> Edit</button><button className="text-button danger-button" onClick={() => remove(item.id)} disabled={busy}><Trash2 size={14} /> Delete</button></div></article>)}</div>}</section></div></div>;
}
