import { useEffect, useState } from "react";
import { ArrowUpRight, CircleDollarSign, FileCheck2 } from "lucide-react";
import { Link } from "wouter";
import { getTransactions, hasApi } from "@/lib/api";
import StatusBadge from "@/components/StatusBadge";

function label(value) { return String(value || "—").replace(/_/g, " "); }
function date(value) { return value ? new Date(value).toLocaleString([], { dateStyle: "medium", timeStyle: "short" }) : "Date unavailable"; }

export default function Transactions() {
  const [transactions, setTransactions] = useState([]);
  const [loading, setLoading] = useState(Boolean(hasApi));
  const [error, setError] = useState("");

  useEffect(() => {
    if (!hasApi) return;
    setLoading(true); setError("");
    getTransactions().then((result) => setTransactions(result.transactions || [])).catch((e) => setError(e.message)).finally(() => setLoading(false));
  }, []);

  if (!hasApi) return <div className="page-frame"><div className="page-topline"><div><div className="eyebrow"><span className="section-number">06</span> TRANSACTIONS</div><h1>Follow every taka.</h1></div></div><div className="honest-state"><strong>Live transaction history needs the Laravel API.</strong><span>Configure <code>VITE_API_BASE_URL</code> to load authenticated records.</span></div></div>;

  return <div className="page-frame transactions-page">
    <div className="page-topline"><div><div className="eyebrow"><span className="section-number">06</span> TRANSACTION LEDGER</div><h1>Follow every taka.</h1><p>System-generated escrow movements for contracts you are part of.</p></div><Link className="outline-button" href="/contracts"><FileCheck2 size={15} /> View contracts</Link></div>
    {error && <div className="honest-state state-error"><strong>Could not load the ledger.</strong><span>{error}</span></div>}
    {loading ? <div className="jobs-register"><div className="job-skeleton large" /><div className="job-skeleton large" /></div> : transactions.length === 0 ? <div className="empty-state"><CircleDollarSign size={21} /><strong>No transactions yet.</strong><span>Contract deposits, releases, and refunds will appear here when recorded.</span></div> : <div className="transaction-ledger"><div className="transaction-ledger-head"><span>TYPE</span><span>CONTRACT / JOB</span><span>AMOUNT</span><span>STATUS</span><span>RECORDED</span></div>{transactions.map((transaction) => <article className="transaction-row" key={transaction.id}><div className="transaction-type"><div className={`transaction-icon transaction-${transaction.type}`}><CircleDollarSign size={15} /></div><strong>{label(transaction.type)}</strong></div><div className="transaction-context">{transaction.contract?.job_post_id ? <Link className="text-button" href={`/jobs/${transaction.contract.job_post_id}`}>Job #{transaction.contract.job_post_id} <ArrowUpRight size={13} /></Link> : <span>Contract #{transaction.contract_id || "—"}</span>}<small>{transaction.contract?.status ? `Contract ${label(transaction.contract.status)}` : "Contract context unavailable"}</small></div><strong className="transaction-amount">৳{transaction.amount ?? "—"}</strong><StatusBadge status={transaction.status || "unknown"} /><time>{date(transaction.created_at)}</time></article>)}</div>}
  </div>;
}
