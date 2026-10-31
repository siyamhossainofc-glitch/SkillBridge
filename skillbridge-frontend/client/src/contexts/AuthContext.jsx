/* Civic Blueprints: auth state is a visible handoff—current identity, role, and next action remain explicit. */
import { createContext, useContext, useEffect, useMemo, useState } from "react";
import { currentUser, hasApi, login as apiLogin, logout as apiLogout, register as apiRegister } from "@/lib/api";

const AuthContext = createContext(undefined);
const demoUser = (role) => ({ id: 0, name: "Md. Siyam", email: "demo@skillbridge.local", role, rating: "4.9", nid_verified: true });

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(true);
  useEffect(() => {
    const token = localStorage.getItem("skillbridge_token");
    const savedDemoUser = localStorage.getItem("skillbridge_demo_user");
    if (token && hasApi) {
      currentUser().then((result) => setUser(result.user)).catch(() => localStorage.removeItem("skillbridge_token")).finally(() => setLoading(false));
    } else if (savedDemoUser) {
      try { setUser(JSON.parse(savedDemoUser)); } catch { localStorage.removeItem("skillbridge_demo_user"); }
      setLoading(false);
    } else {
      setLoading(false);
    }
  }, []);
  const store = (result) => { localStorage.setItem("skillbridge_token", result.token); setUser(result.user); };
  const value = useMemo(() => ({ user, loading, liveApi: hasApi, login: async (email, password) => store(await apiLogin(email, password)), register: async (input) => store(await apiRegister(input)), logout: async () => { if (hasApi && localStorage.getItem("skillbridge_token")) await apiLogout().catch(() => undefined); localStorage.removeItem("skillbridge_token"); localStorage.removeItem("skillbridge_demo_user"); setUser(null); }, enterDemo: (role) => { const nextUser = demoUser(role); localStorage.setItem("skillbridge_demo_user", JSON.stringify(nextUser)); setUser(nextUser); } }), [loading, user]);
  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}
export function useAuth() { const context = useContext(AuthContext); if (!context) throw new Error("useAuth must be used inside AuthProvider"); return context; }
