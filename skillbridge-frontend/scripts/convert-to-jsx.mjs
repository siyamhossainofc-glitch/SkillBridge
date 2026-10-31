import { renameSync, readFileSync, writeFileSync } from "node:fs";
import { dirname, resolve } from "node:path";

const root = resolve(process.cwd(), "client/src");
const renames = [
  ["App.tsx", "App.jsx"],
  ["main.tsx", "main.jsx"],
  ["contexts/AuthContext.tsx", "contexts/AuthContext.jsx"],
  ["contexts/ThemeContext.tsx", "contexts/ThemeContext.jsx"],
  ["components/ErrorBoundary.tsx", "components/ErrorBoundary.jsx"],
  ["lib/api.ts", "lib/api.js"],
  ["lib/utils.ts", "lib/utils.js"],
  ["pages/Auth.tsx", "pages/Auth.jsx"],
  ["pages/Home.tsx", "pages/Home.jsx"],
];
for (const [from, to] of renames) renameSync(resolve(root, from), resolve(root, to));

const replaceIn = (relative, edits) => {
  const path = resolve(root, relative);
  let source = readFileSync(path, "utf8");
  for (const [pattern, replacement] of edits) source = source.replace(pattern, replacement);
  writeFileSync(path, source);
};

replaceIn("main.jsx", [["document.getElementById(\"root\")!", "document.getElementById(\"root\")"]]);
replaceIn("lib/utils.js", [["import { clsx, type ClassValue } from \"clsx\";", "import { clsx } from \"clsx\";"], ["export function cn(...inputs: ClassValue[]) {", "export function cn(...inputs) {"]]);
replaceIn("lib/api.js", [[/export type ApiUser = [\s\S]*?\n\nconst API_BASE/, "const API_BASE"], ["export async function apiRequest<T>(path: string, options: RequestInit = {}): Promise<T> {", "export async function apiRequest(path, options = {}) {"], ["apiRequest<{ user: ApiUser; token: string }>", "apiRequest"], ["apiRequest<{ user: ApiUser }>", "apiRequest"], ["apiRequest<{ message: string }>", "apiRequest"], ["apiRequest<{ jobs: unknown[] }>", "apiRequest"]]);
replaceIn("contexts/ThemeContext.jsx", [[/type Theme = .*?\ninterface ThemeContextType \{[\s\S]*?\n\}\n/, ""], [/const ThemeContext = createContext<ThemeContextType \| undefined>\(undefined\);\ninterface ThemeProviderProps \{[\s\S]*?\n\}\n/, "const ThemeContext = createContext(undefined);\n"], ["export function ThemeProvider({\n  children,\n  defaultTheme = \"light\",\n  switchable = false,\n}: ThemeProviderProps) {", "export function ThemeProvider({ children, defaultTheme = \"light\", switchable = false }) {"], ["const [theme, setTheme] = useState<Theme>(() => {", "const [theme, setTheme] = useState(() => {"], ["return (stored as Theme) || defaultTheme;", "return stored || defaultTheme;"]]);
replaceIn("contexts/AuthContext.jsx", [[/type RegisterInput = .*?\n/, ""], [/type AuthValue = .*?\n/, ""], ["const demoUser = (role: \"seeker\" | \"provider\"): ApiUser =>", "const demoUser = (role) =>"], ["export function AuthProvider({ children }: { children: React.ReactNode }) {", "export function AuthProvider({ children }) {"], ["const [user, setUser] = useState<ApiUser | null>(null);", "const [user, setUser] = useState(null);"], ["const store = (result: { user: ApiUser; token: string }) =>", "const store = (result) =>"], ["const value = useMemo<AuthValue>(() =>", "const value = useMemo(() =>"], ["export function useAuth()", "export function useAuth()"]]);
replaceIn("components/ErrorBoundary.jsx", [["import { Component, ReactNode } from \"react\";", "import { Component } from \"react\";"], [/interface Props \{[\s\S]*?\n\}\ninterface State \{[\s\S]*?\n\}\n/, ""], ["class ErrorBoundary extends Component<Props, State> {", "class ErrorBoundary extends Component {"], ["constructor(props: Props)", "constructor(props)"], ["static getDerivedStateFromError(error: Error): State", "static getDerivedStateFromError(error)" ]]);
replaceIn("pages/Auth.jsx", [["export default function Auth({ onComplete }: { onComplete: () => void }) {", "export default function Auth({ onComplete }) {"], ["const [mode, setMode] = useState<\"login\" | \"register\">(\"login\");", "const [mode, setMode] = useState(\"login\");"], ["const [role, setRole] = useState<\"seeker\" | \"provider\">(\"seeker\");", "const [role, setRole] = useState(\"seeker\");"], ["const submit = async (event: FormEvent) =>", "const submit = async (event) =>"]]);
replaceIn("pages/Home.jsx", [["function RailItem({ icon: Icon, label, active, onClick, count }: { icon: typeof LayoutDashboard; label: string; active?: boolean; onClick: () => void; count?: number })", "function RailItem({ icon: Icon, label, active, onClick, count })"], ["const { user, logout, liveApi } = useAuth();", "const { user, logout, liveApi } = useAuth();"], ["const [role, setRole] = useState<\"seeker\" | \"provider\">(user?.role === \"provider\" ? \"provider\" : \"seeker\");", "const [role, setRole] = useState(user?.role === \"provider\" ? \"provider\" : \"seeker\");"], [/const \[saved, setSaved\] = useState<number\[\]>\(\[\]\);/, "const [saved, setSaved] = useState([]);"]]);
