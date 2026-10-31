import { useEffect, useState } from "react";
import { ArrowUpRight, ChevronLeft, ChevronRight, RotateCcw, Search, UserRound } from "lucide-react";
import { Link } from "wouter";
import { hasApi, listCategories, listProviders } from "@/lib/api";

export default function Providers() {
  const [providers, setProviders] = useState([]);
  const [categories, setCategories] = useState([]);
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1 });
  const [filters, setFilters] = useState({ search: "", location: "", skill_id: "", category_id: "", sort: "recent" });
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(Boolean(hasApi));
  const [error, setError] = useState("");
  const category = categories.find((item) => String(item.id) === String(filters.category_id));
  const hasFilters = Object.entries(filters).some(([key, value]) => key !== "sort" && Boolean(value));

  useEffect(() => {
    if (!hasApi) { setLoading(false); return; }
    listCategories().then((result) => setCategories(result.categories || [])).catch((e) => setError(e.message));
  }, []);
  useEffect(() => {
    if (!hasApi) return;
    const timer = setTimeout(() => {
      setLoading(true); setError("");
      listProviders({ ...filters, page }).then((result) => { setProviders(result.data || []); setMeta(result); }).catch((e) => setError(e.message)).finally(() => setLoading(false));
    }, filters.search ? 300 : 0);
    return () => clearTimeout(timer);
  }, [filters, page]);
  const update = (field) => (event) => { setPage(1); setFilters((current) => ({ ...current, [field]: event.target.value, ...(field === "category_id" ? { skill_id: "" } : {}) })); };
  const clear = () => { setPage(1); setFilters({ search: "", location: "", skill_id: "", category_id: "", sort: "recent" }); };

  return <div className="page-frame"><div className="page-topline"><div><div className="eyebrow"><span className="section-number">13</span> PROVIDER DIRECTORY</div><h1>Find the right capability.</h1><p>Search provider profiles by title, bio, location, skills, or category.</p></div><Link className="primary-button" href="/dashboard">Back to overview <ArrowUpRight size={15} /></Link></div><div className="filter-bar"><label className="search-field"><Search size={17} /><input value={filters.search} onChange={update("search")} placeholder="Search providers" /></label><input value={filters.location} onChange={update("location")} placeholder="Location" aria-label="Filter providers by location" /><select value={filters.category_id} onChange={update("category_id")}><option value="">All skill categories</option>{categories.map((item) => <option value={item.id} key={item.id}>{item.name}</option>)}</select><select value={filters.skill_id} onChange={update("skill_id")} disabled={!category}><option value="">All skills</option>{(category?.skills || []).map((item) => <option value={item.id} key={item.id}>{item.name}</option>)}</select><select value={filters.sort} onChange={update("sort")}><option value="recent">Recently joined</option><option value="smart">Smart ranking</option><option value="rating">Highest rated</option><option value="name">Name</option></select>{hasFilters && <button className="outline-button" type="button" onClick={clear}><RotateCcw size={14} /> Clear</button>}</div>{!hasApi && <div className="honest-state"><strong>Provider discovery is not connected yet.</strong><span>Configure <code>VITE_API_BASE_URL</code> to browse live providers.</span></div>}{error && <div className="honest-state state-error"><strong>Could not load providers.</strong><span>{error}</span><button type="button" className="outline-button" onClick={() => setPage(1)}>Try again</button></div>}{loading ? <div className="jobs-register"><div className="job-skeleton large" /><div className="job-skeleton large" /></div> : providers.length === 0 ? <div className="empty-state"><strong>{hasApi ? "No providers match these filters." : "No live providers to display."}</strong><span>Try a broader search or clear the filters.</span>{hasApi && hasFilters && <button type="button" className="text-button" onClick={clear}>Clear filters</button>}</div> : <div className="jobs-register">{providers.map((provider) => <Link className="live-job-row" href={`/providers/${provider.id}`} key={provider.id}><UserRound className="job-marker" size={24} /><div className="live-job-copy"><div className="job-meta"><span>{provider.professional_title || "Provider"}</span>{provider.address && <><i>·</i><span>{provider.address}</span></>}</div><h2>{provider.name}</h2><p>{provider.bio || "This provider has not added a public bio yet."}</p><div className="live-job-bottom"><strong>{provider.rating ? `${provider.rating} rating` : "No rating yet"}</strong><span>{provider.published_services_count || 0} published service{provider.published_services_count === 1 ? "" : "s"}</span><span>{(provider.skills || []).slice(0, 3).map((skill) => skill.name).join(" · ")}</span></div></div><ArrowUpRight className="row-arrow" size={18} /></Link>)}</div>}{hasApi && meta.last_page > 1 && <div className="pagination"><button disabled={page <= 1} onClick={() => setPage(page - 1)}><ChevronLeft size={15} /> Previous</button><span>Page {meta.current_page || page} of {meta.last_page}</span><button disabled={page >= meta.last_page} onClick={() => setPage(page + 1)}>Next <ChevronRight size={15} /></button></div>}</div>;
}
