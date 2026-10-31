/* Civic Blueprints: the profile is a factual identity card with role-aware editing and honest empty states. */
import { useEffect, useMemo, useState } from "react";
import { ArrowLeft, Plus, Save, UserRound, X } from "lucide-react";
import { Link } from "wouter";
import { useAuth } from "@/contexts/AuthContext";
import { attachSkill, detachSkill, getProfile, getProfileCompletion, getProviderPortfolio, hasApi, listSkills, updateProfile } from "@/lib/api";
import { toast } from "sonner";

const roleCopy = { provider: "Provider", seeker: "Seeker" };

function CompletionPanel({ completion }) {
  if (!completion) return null;
  return <section className="brief-sheet" style={{ marginBottom: 24 }}><div className="page-topline"><div><div className="eyebrow">PROFILE COMPLETION</div><h2>{completion.completion_percentage}% complete.</h2><p className="panel-intro">A clearer profile helps people decide whether your brief or capability is a good fit.</p></div><strong className="status-stamp">{completion.missing_items?.length ? `${completion.missing_items.length} next step${completion.missing_items.length === 1 ? "" : "s"}` : "Complete"}</strong></div><div className="completion-meter" aria-label={`Profile ${completion.completion_percentage}% complete`}><span style={{ width: `${completion.completion_percentage}%` }} /></div>{completion.next_steps?.length > 0 ? <div className="jobs-register">{completion.next_steps.slice(0, 4).map((step) => <Link className="live-job-row" href={step.link} key={step.key}><div className="live-job-copy"><div className="job-meta"><span>NEXT STEP</span></div><h2>{step.label}</h2></div><ArrowLeft size={16} style={{ transform: "rotate(180deg)" }} /></Link>)}</div> : <div className="empty-state"><strong>Your profile has all tracked items.</strong><span>Keep the details current as your work changes.</span></div>}</section>;
}

export default function Profile() {
  const { user } = useAuth();
  const [profile, setProfile] = useState(null);
  const [skills, setSkills] = useState([]);
  const [form, setForm] = useState({ name: "", professional_title: "", email: "", bio: "", address: "", phone: "" });
  const [selectedSkill, setSelectedSkill] = useState("");
  const [loading, setLoading] = useState(Boolean(hasApi && user));
  const [saving, setSaving] = useState(false);
  const [skillSaving, setSkillSaving] = useState(false);
  const [error, setError] = useState("");
  const [skillError, setSkillError] = useState("");
  const [portfolio, setPortfolio] = useState([]);
  const [completion, setCompletion] = useState(null);

  const load = async () => {
    if (!hasApi || !user) return;
    setLoading(true);
    setError("");
    try {
      const [profileResult, skillsResult, completionResult] = await Promise.all([getProfile(), listSkills(), getProfileCompletion()]);
      setCompletion(completionResult);
      const current = profileResult.user || profileResult.profile || {};
      setProfile(current);
      setForm({
        name: current.name || "",
        professional_title: current.professional_title || "",
        email: current.email || "",
        bio: current.bio || "",
        address: current.address || "",
        phone: current.phone || "",
      });
      setSkills(skillsResult.skills || []);
      if ((current.role || user.role) === "provider") {
        const portfolioResult = await getProviderPortfolio(current.id || user.id);
        setPortfolio(portfolioResult.items || []);
      }
    } catch (e) {
      setError(e.message || "The profile could not be loaded.");
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { load(); }, [user?.id]);

  const attachedSkills = profile?.skills || [];
  const attachedIds = useMemo(() => new Set(attachedSkills.map((skill) => skill.id)), [attachedSkills]);
  const availableSkills = skills.filter((skill) => !attachedIds.has(skill.id));
  const role = profile?.role || user?.role || "seeker";
  const roleLabel = roleCopy[role] || role;
  const reviews = profile?.reviews_received || [];
  const updateField = (field) => (event) => setForm((current) => ({ ...current, [field]: event.target.value }));

  const save = async (event) => {
    event.preventDefault();
    setSaving(true);
    try {
      const result = await updateProfile({ name: form.name, professional_title: form.professional_title || null, phone: form.phone || null, address: form.address || null, bio: form.bio || null });
      const updated = result.user || result.profile || profile;
      setProfile((current) => ({ ...current, ...updated }));
      toast.success("Profile saved.");
    } catch (e) {
      toast.error(e.message || "The profile could not be saved.");
    } finally {
      setSaving(false);
    }
  };

  const addSkill = async () => {
    if (!selectedSkill) return;
    setSkillSaving(true);
    setSkillError("");
    try {
      const result = await attachSkill({ skill_id: Number(selectedSkill) });
      setProfile((current) => ({ ...current, skills: result.skills || current?.skills || [] }));
      setSelectedSkill("");
      toast.success("Skill added.");
    } catch (e) {
      setSkillError(e.message || "The skill could not be added.");
    } finally {
      setSkillSaving(false);
    }
  };

  const removeSkill = async (id) => {
    setSkillSaving(true);
    setSkillError("");
    try {
      await detachSkill(id);
      setProfile((current) => ({ ...current, skills: (current?.skills || []).filter((skill) => skill.id !== id) }));
      toast.success("Skill removed.");
    } catch (e) {
      setSkillError(e.message || "The skill could not be removed.");
    } finally {
      setSkillSaving(false);
    }
  };

  if (!hasApi) return <div className="page-frame"><Link className="back-link" href="/dashboard"><ArrowLeft size={15} /> Back to workspace</Link><div className="honest-state"><strong>Profile editing needs the Laravel API.</strong><span>Configure <code>VITE_API_BASE_URL</code> before editing your profile.</span></div></div>;
  if (!user) return <div className="page-frame"><Link className="back-link" href="/auth"><ArrowLeft size={15} /> Sign in</Link><div className="honest-state"><strong>Sign in to view your profile.</strong><span>Your profile and skills are private.</span></div></div>;
  if (loading) return <div className="page-frame"><div className="jobs-register"><div className="job-skeleton large" /><div className="job-skeleton large" /></div></div>;
  if (error) return <div className="page-frame"><Link className="back-link" href="/dashboard"><ArrowLeft size={15} /> Back to workspace</Link><div className="honest-state state-error"><strong>Profile unavailable.</strong><span>{error}</span><button className="outline-button" type="button" onClick={load}>Try again</button></div></div>;

  return <div className="page-frame"><Link className="back-link" href="/dashboard"><ArrowLeft size={15} /> Back to workspace</Link><div className="section-heading"><div><div className="eyebrow"><span className="section-number">10</span> PROFILE</div><h1>Make the record useful.</h1><p className="panel-intro">Keep the details people rely on for a clear handoff.</p></div><UserRound size={32} strokeWidth={1.2} /></div><CompletionPanel completion={completion} /><div className="content-grid profile-grid"><section className="context-panel"><div className="panel-heading"><div><div className="eyebrow">IDENTITY CARD</div><h2>{profile?.name || form.name || "Your profile"}</h2><p>{profile?.professional_title || "Professional title not added yet."}</p></div><span className="status-stamp">{roleLabel}</span></div><div className="brief-facts"><div><span>EMAIL</span><strong>{profile?.email || form.email}</strong></div><div><span>LOCATION</span><strong>{profile?.address || "Location not added yet"}</strong></div><div><span>REVIEWS</span><strong>{reviews.length ? `${reviews.length} review${reviews.length === 1 ? "" : "s"}` : "No reviews yet"}</strong></div></div><div className="profile-copy"><span>BIO</span><p>{profile?.bio || "No bio added yet."}</p></div><div className="skill-line">{attachedSkills.length === 0 ? <span>{role === "provider" ? "No skills attached yet." : "Skills and preferences are optional."}</span> : attachedSkills.map((skill) => <em key={skill.id}>{skill.category?.name ? `${skill.category.name}: ` : ""}{skill.name}<button type="button" onClick={() => removeSkill(skill.id)} disabled={skillSaving} aria-label={`Remove ${skill.name}`}><X size={12} /></button></em>)}</div>{skillError && <div className="honest-state state-error"><strong>Skill update failed.</strong><span>{skillError}</span></div>}<div className="hero-actions"><select value={selectedSkill} onChange={(event) => setSelectedSkill(event.target.value)} disabled={skillSaving}><option value="">Choose a skill</option>{availableSkills.map((skill) => <option key={skill.id} value={skill.id}>{skill.category?.name ? `${skill.category.name} · ` : ""}{skill.name}</option>)}</select><button type="button" className="outline-button" onClick={addSkill} disabled={!selectedSkill || skillSaving}><Plus size={15} /> {skillSaving ? "Updating…" : "Add skill"}</button></div></section><section className="context-panel"><div className="eyebrow">EDIT DETAILS</div><h2>Say what is true.</h2><form className="bid-form" onSubmit={save}><label>Name<input value={form.name} onChange={updateField("name")} required /></label><label>Professional title<input value={form.professional_title} onChange={updateField("professional_title")} maxLength={255} placeholder={role === "provider" ? "e.g. Laravel Developer" : "e.g. Startup founder"} /></label><label>Email<input type="email" value={form.email} readOnly aria-describedby="email-note" required /><small id="email-note">Email is managed by account authentication.</small></label><label>Phone<input value={form.phone} onChange={updateField("phone")} maxLength={20} /></label><label>Location<input value={form.address} onChange={updateField("address")} maxLength={500} placeholder="City, area, or remote" /></label><label>Bio<textarea value={form.bio} onChange={updateField("bio")} maxLength={1000} placeholder="Describe the work you do or what you are looking for." /></label><button disabled={saving} className="primary-button">{saving ? "Saving…" : "Save profile"} <Save size={15} /></button></form></section></div>{role === "provider" && <section className="brief-sheet" style={{ marginTop: 24 }}><div className="page-topline"><div><div className="eyebrow">PORTFOLIO</div><h2>Selected work.</h2></div><Link className="outline-button" href="/my-portfolio">Manage portfolio</Link></div>{portfolio.length === 0 ? <div className="empty-state"><strong>No portfolio items yet</strong><span>Published work will appear here.</span></div> : <div className="jobs-register">{portfolio.map((item) => <article className="live-job-row" key={item.id}>{item.image_url && <img src={item.image_url} alt="" style={{ width: 96, height: 72, objectFit: "cover" }} />}<div className="live-job-copy"><div className="job-meta"><span>{item.category?.name || "Portfolio"}</span>{item.subcategory?.name && <><i>·</i><span>{item.subcategory.name}</span></>}</div><h2>{item.title}</h2><p>{item.description}</p>{item.project_url && <a href={item.project_url} target="_blank" rel="noreferrer">View project</a>}</div></article>)}</div>}</section>}</div>;
}
