/* Civic Blueprints: persistent index rail, editorial canvas, cobalt actions, warm paper surfaces. */
import { Toaster } from "@/components/ui/sonner";
import { TooltipProvider } from "@/components/ui/tooltip";
import NotFound from "@/pages/NotFound";
import { Route, Switch } from "wouter";
import ErrorBoundary from "./components/ErrorBoundary";
import { ThemeProvider } from "./contexts/ThemeContext";
import Home from "./pages/Home";
import Auth from "./pages/Auth";
import Jobs from "./pages/Jobs";
import JobCreate from "./pages/JobCreate";
import JobEdit from "./pages/JobEdit";
import JobDetail from "./pages/JobDetail";
import Services from "./pages/Services";
import ServiceDetail from "./pages/ServiceDetail";
import ServiceManage from "./pages/ServiceManage";
import MyServices from "./pages/MyServices";
import PortfolioManage from "./pages/PortfolioManage";
import ProviderProfile from "./pages/ProviderProfile";
import SavedJobs from "./pages/SavedJobs";
import Favorites from "./pages/Favorites";
import Contracts from "./pages/Contracts";
import ContractDetail from "./pages/ContractDetail";
import Profile from "./pages/Profile";
import MyJobs from "./pages/MyJobs";
import MyBids from "./pages/MyBids";
import Messages from "./pages/Messages";
import Transactions from "./pages/Transactions";
import Availability from "./pages/Availability";
import ServiceAreas from "./pages/ServiceAreas";
import Providers from "./pages/Providers";
import Notifications from "./pages/Notifications";
import Verification from "./pages/Verification";
import AdminVerifications from "./pages/AdminVerifications";
import AdminDisputes from "./pages/AdminDisputes";
import AdminModeration from "./pages/AdminModeration";
import PageNav from "./components/PageNav";
import { AuthProvider } from "./contexts/AuthContext";

function WithNav({ Component }) {
  return (props) => (
    <>
      <PageNav />
      <Component {...props} />
    </>
  );
}

function Router() {
  return (
    <Switch>
      <Route path="/" component={Home} />
      <Route path="/dashboard" component={Home} />
      <Route path="/auth" component={() => <Auth onComplete={() => (window.location.href = "/dashboard")} />} />
      <Route path="/jobs" component={WithNav({ Component: Jobs })} />
      <Route path="/jobs/new" component={WithNav({ Component: JobCreate })} />
      <Route path="/jobs/:id/edit" component={WithNav({ Component: JobEdit })} />
      <Route path="/my-jobs" component={WithNav({ Component: MyJobs })} />
      <Route path="/my-bids" component={WithNav({ Component: MyBids })} />
      <Route path="/messages" component={WithNav({ Component: Messages })} />
      <Route path="/notifications" component={WithNav({ Component: Notifications })} />
      <Route path="/verification" component={WithNav({ Component: Verification })} />
      <Route path="/admin/verifications" component={WithNav({ Component: AdminVerifications })} />
      <Route path="/admin/disputes" component={WithNav({ Component: AdminDisputes })} />
      <Route path="/admin/moderation" component={WithNav({ Component: AdminModeration })} />
      <Route path="/transactions" component={WithNav({ Component: Transactions })} />
      <Route path="/jobs/:id" component={WithNav({ Component: JobDetail })} />
      <Route path="/services" component={WithNav({ Component: Services })} />
      <Route path="/providers" component={WithNav({ Component: Providers })} />
      <Route path="/services/new" component={WithNav({ Component: ServiceManage })} />
      <Route path="/my-services" component={WithNav({ Component: MyServices })} />
      <Route path="/my-portfolio" component={WithNav({ Component: PortfolioManage })} />
      <Route path="/availability" component={WithNav({ Component: Availability })} />
      <Route path="/service-areas" component={WithNav({ Component: ServiceAreas })} />
      <Route path="/portfolio/new" component={WithNav({ Component: PortfolioManage })} />
      <Route path="/portfolio/:id/edit" component={WithNav({ Component: PortfolioManage })} />
      <Route path="/providers/:id" component={WithNav({ Component: ProviderProfile })} />
      <Route path="/saved-jobs" component={WithNav({ Component: SavedJobs })} />
      <Route path="/favorites" component={WithNav({ Component: Favorites })} />
      <Route path="/services/:id/edit" component={WithNav({ Component: ServiceManage })} />
      <Route path="/services/:id" component={WithNav({ Component: ServiceDetail })} />
      <Route path="/contracts" component={WithNav({ Component: Contracts })} />
      <Route path="/contracts/:id" component={WithNav({ Component: ContractDetail })} />
      <Route path="/profile" component={WithNav({ Component: Profile })} />
      <Route path="/404" component={NotFound} />
      <Route component={NotFound} />
    </Switch>
  );
}

export default function App() {
  return (
    <ErrorBoundary>
      <ThemeProvider defaultTheme="light">
        <AuthProvider>
          <TooltipProvider>
            <Toaster />
            <Router />
          </TooltipProvider>
        </AuthProvider>
      </ThemeProvider>
    </ErrorBoundary>
  );
}
