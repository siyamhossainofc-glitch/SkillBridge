/* Shared header for pages outside the dashboard shell. The dashboard (Home.jsx)
   has its own sidebar with a Sign out button, but every other page (Jobs,
   JobDetail, JobCreate, Contracts, ContractDetail, Profile) previously had no
   navigation at all, so there was no way to sign out once you left the
   dashboard. This component fixes that. */
import { Link } from "wouter";
import { useAuth } from "@/contexts/AuthContext";
import { toast } from "sonner";
import NotificationBell from "@/components/NotificationBell";

export default function PageNav() {
  const { user, logout, liveApi } = useAuth();

  const signOut = () => {
    logout().then(() => {
      toast.success("Signed out safely.");
      window.location.href = "/auth";
    });
  };

  return (
    <header className="topbar page-topbar">
      <div className="breadcrumb">
        <Link href="/dashboard" style={{ fontWeight: 650, color: "#253754", textDecoration: "none" }}>
          skill<span style={{ color: "#2457d6" }}>bridge</span>
        </Link>
        {user && (
          <nav className="page-links">
            <Link href="/dashboard">Dashboard</Link>
            <Link href="/jobs">Jobs</Link>
            <Link href="/services">Services</Link>
            <Link href="/providers">Providers</Link>
            <Link href="/saved-jobs">Saved Jobs</Link>
            <Link href="/favorites">Favorites</Link>
            <Link href="/messages">Messages</Link>
            <Link href="/notifications">Notifications</Link>
            <Link href="/transactions">Transactions</Link>
            {user.role === "seeker" && <Link href="/my-jobs">My Jobs</Link>}
            {user.role === "provider" && <Link href="/my-bids">My Bids</Link>}
            {user.role === "provider" && <Link href="/services/new">Create Service</Link>}
            {user.role === "provider" && <Link href="/my-services">My Services</Link>}
            {user.role === "provider" && <Link href="/my-portfolio">My Portfolio</Link>}
            {user.role === "provider" && <Link href="/availability">Availability</Link>}
            {user.role === "provider" && <Link href="/service-areas">Service Areas</Link>}
            {user.role === "provider" && <Link href="/verification">Verification</Link>}
            {user.role === "admin" && <Link href="/admin/verifications">Verification Review</Link>}
            {user.role === "admin" && <Link href="/admin/disputes">Dispute Review</Link>}
            <Link href="/contracts">Contracts</Link>
            <Link href="/profile">Profile</Link>
          </nav>
        )}
      </div>
      <div className="top-actions">
        {user ? (
          <>
            <span style={{ fontSize: 12, color: "#88857f" }}>{user.name} · {user.role}</span>
            <NotificationBell liveApi={liveApi} />
            <button className="outline-button nav-signout" onClick={signOut} title="Sign out">
              Sign out
            </button>
          </>
        ) : (
          <Link className="signin-link" href="/auth">
            Sign in
          </Link>
        )}
      </div>
    </header>
  );
}
