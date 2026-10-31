import { useEffect, useState } from "react";
import { ArrowLeft, Plus, Save, Trash2 } from "lucide-react";
import { Link, useLocation, useRoute } from "wouter";
import { toast } from "sonner";
import { createPortfolioItem, deletePortfolioItem, getMyPortfolio, hasApi, listCategories, updatePortfolioItem } from "@/lib/api";

const blank = { title: "", description: "", category_id: "", subcategory_id: "", project_url: "", image_url: "", sort_order: "0", status: "draft" };

export default function PortfolioManage() {
  const [, editParams] = useRoute("/portfolio/:id/edit");
  const [isCreateRoute] = useRoute("/portfolio/new");
  const [isListRoute] = useRoute("/my-portfolio");
  const [, navigate] = useLocation();
  const editing = Boolean(editParams?.id);
  const listing = Boolean(isListRoute) && !editing && !isCreateRoute;
  const [categories, setCategories] = useState([]);
  const [items, setItems] = useState([]);
  const [form, setForm] = useState(blank);
  const [loading, setLoading] = useState(Boolean(hasApi));
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const category = categories.find((c) => String(c.id) === String(form.category_id));

  useEffect(() => {
    if (!hasApi) return;
    const requests = [listCategories()];
    if (listing || editing) requests.push(getMyPortfolio());
    Promise.all(requests).then(([categoryResult, portfolioResult]) => {
      setCategories(categoryResult.categories || []);
      const ownItems = portfolioResult?.items || [];
      if (listing || editing) setItems(ownItems);
      if (editing) {
        const item = ownItems.find((value) => String(value.id) === String(editParams.id));
        if (item) setForm({ ...blank, ...item, category_id: item.category_id || "", subcategory_id: item.subcategory_id || "" });
      } else if (isCreateRoute) {
        setForm(blank);
      }
    }).catch((e) => setError(e.message)).finally(() => setLoading(false));
  }, [editing, editParams?.id, isCreateRoute, listing]);

  const setField = (field) => (e) => setForm((value) => ({ ...value, [field]: e.target.value, ...(field === "category_id" ? { subcategory_id: "" } : {}) }));

  const submit = async (e) => {
    e.preventDefault();
    setBusy(true); setError("");
    try {
      const input = { ...form, category_id: form.category_id || null, subcategory_id: form.subcategory_id || null, sort_order: Number(form.sort_order) };
      const result = editing ? await updatePortfolioItem(editParams.id, input) : await createPortfolioItem(input);
      toast.success(editing ? "Portfolio item updated." : "Portfolio item created.");
      if (editing) navigate("/my-portfolio"); else { setItems((value) => [...value, result.item]); setForm(blank); }
    } catch (e2) { setError(e2.message); } finally { setBusy(false); }
  };

  const remove = async (id) => {
    if (!window.confirm("Delete this portfolio item?")) return;
    setBusy(true); setError("");
    try { await deletePortfolioItem(id); setItems((value) => value.filter((item) => item.id !== id)); toast.success("Portfolio item deleted."); if (editing) navigate("/my-portfolio"); }
    catch (e) { setError(e.message); } finally { setBusy(false); }
  };

  if (!hasApi) return <div className="page-frame"><div className="honest-state"><strong>Portfolio management needs the Laravel API.</strong><span>Configure <code>VITE_API_BASE_URL</code> first.</span></div></div>;
  if (loading) return <div className="page-frame"><div className="job-skeleton large" /></div>;

  if (listing) return <div className="page-frame"><Link className="back-link" href="/profile"><ArrowLeft size={15} /> Back to profile</Link><div className="page-topline"><div><div className="eyebrow">MY PORTFOLIO</div><h1>Show the work clearly.</h1><p>Manage your portfolio records and publication status.</p></div><Link className="primary-button" href="/portfolio/new"><Plus size={15} /> Add portfolio item</Link></div>{error && <div className="honest-state state-error"><strong>Portfolio action failed.</strong><span>{error}</span></div>}{items.length === 0 ? <div className="empty-state"><strong>No portfolio items yet</strong><span>Add a portfolio item to start showcasing your work.</span></div> : <div className="jobs-register">{items.map((item) => <div className="live-job-row" key={item.id}><div className="live-job-copy"><div className="job-meta"><span>{item.status}</span><i>·</i><span>Order {item.sort_order}</span></div><h2>{item.title}</h2><p>{item.description}</p></div><div><Link className="outline-button" href={`/portfolio/${item.id}/edit`}>Edit</Link><button type="button" className="text-button danger-button" onClick={() => remove(item.id)} disabled={busy}>Delete</button></div></div>)}</div>}</div>;

  return <div className="page-frame"><Link className="back-link" href={editing ? "/my-portfolio" : "/my-portfolio"}><ArrowLeft size={15} /> Back to portfolio</Link><div className="page-topline"><div><div className="eyebrow">{editing ? "EDIT PORTFOLIO ITEM" : "NEW PORTFOLIO ITEM"}</div><h1>Show the work clearly.</h1><p>Add published work without inventing outcomes or metrics.</p></div><Link className="outline-button" href="/my-portfolio">Portfolio list</Link></div>{error && <div className="honest-state state-error"><strong>Portfolio action failed.</strong><span>{error}</span></div>}<form className="brief-sheet bid-form compact" onSubmit={submit}><label>Title<input required maxLength={255} value={form.title} onChange={setField("title")} /></label><label>Description<textarea required value={form.description} onChange={setField("description")} /></label><div className="content-grid"><label>Category<select value={form.category_id} onChange={setField("category_id")}><option value="">No category</option>{categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</select></label><label>Subcategory<select value={form.subcategory_id} onChange={setField("subcategory_id")} disabled={!category}><option value="">No subcategory</option>{(category?.subcategories || []).map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select></label></div><label>Project URL<input type="url" value={form.project_url} onChange={setField("project_url")} placeholder="https://example.com/project" /></label><label>Image URL<input type="url" value={form.image_url} onChange={setField("image_url")} placeholder="https://example.com/image.jpg" /></label><div className="content-grid"><label>Sort order<input type="number" min="0" value={form.sort_order} onChange={setField("sort_order")} /></label><label>Status<select value={form.status} onChange={setField("status")}><option value="draft">Draft</option><option value="published">Published</option><option value="archived">Archived</option></select></label></div><button className="primary-button" disabled={busy}>{busy ? "Saving…" : editing ? "Save changes" : "Add portfolio item"} <Save size={15} /></button>{editing && <button type="button" className="text-button danger-button" disabled={busy} onClick={() => remove(editParams.id)}><Trash2 size={14} /> Delete item</button>}</form></div>;
}
