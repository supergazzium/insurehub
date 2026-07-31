import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import AppLayout from '../layouts/AppLayout.vue'
import AuthLayout from '../layouts/AuthLayout.vue'
import PublicLayout from '../layouts/PublicLayout.vue'
import Dashboard from '../pages/Dashboard.vue'
import ModulePlaceholder from '../pages/ModulePlaceholder.vue'

import Login from '../pages/auth/Login.vue'
import ForgotPassword from '../pages/auth/ForgotPassword.vue'
import ResetPassword from '../pages/auth/ResetPassword.vue'
import AcceptInvitation from '../pages/auth/AcceptInvitation.vue'
import PublicHome from '../pages/public/PublicHome.vue'
import AgentRegister from '../pages/public/AgentRegister.vue'
import RecruitLinkRedirect from '../pages/public/RecruitLinkRedirect.vue'
import PortalDashboard from '../pages/portal/PortalDashboard.vue'
import PortalProfile from '../pages/portal/PortalProfile.vue'
import PortalReferral from '../pages/portal/PortalReferral.vue'
import PortalSettings from '../pages/portal/PortalSettings.vue'
import PortalEarnings from '../pages/portal/PortalEarnings.vue'
import AdminAgentApprovals from '../pages/admin/AdminAgentApprovals.vue'
import AdminDownlineTree from '../pages/admin/AdminDownlineTree.vue'
import AdminPayouts from '../pages/admin/AdminPayouts.vue'
import AdminMotorTariffs from '../pages/admin/AdminMotorTariffs.vue'
import FreelookReport from '../pages/reports/FreelookReport.vue'
import MailingReport from '../pages/reports/MailingReport.vue'
import PaymentHistoryReport from '../pages/reports/PaymentHistoryReport.vue'
import CancellationReport from '../pages/reports/CancellationReport.vue'
import QuoteList from '../pages/quotes/QuoteList.vue'
import QuoteEdit from '../pages/quotes/QuoteEdit.vue'
import QuoteDetail from '../pages/quotes/QuoteDetail.vue'
import PolicyEdit from '../pages/policies/PolicyEdit.vue'
import AuthModule from '../pages/auth/AuthModule.vue'
import MfaSetup from '../pages/auth/MfaSetup.vue'
import TenantSettings from '../pages/settings/TenantSettings.vue'
import UserAccount from '../pages/settings/UserAccount.vue'
import CarrierManagement from '../pages/carriers/CarrierManagementV2.vue'
import ProductManagement from '../pages/products/ProductManagementV2.vue'
import ContractManagement from '../pages/contracts/ContractManagement.vue'
import AgentList from '../pages/agents/AgentListV2.vue'
import AgentHierarchy from '../pages/agents/AgentHierarchy.vue'
import AgentRecruitment from '../pages/agents/AgentRecruitment.vue'
import CustomerList from '../pages/customers/CustomerListV2.vue'
import CustomerReferral from '../pages/customers/CustomerReferral.vue'
import PolicyList from '../pages/policies/PolicyListV2.vue'
import ExpiringSoon from '../pages/policies/ExpiringSoon.vue'
import CommissionEngine from '../pages/commissions/CommissionEngine.vue'
import CommissionLedger from '../pages/commissions/CommissionLedger.vue'
import RebateReconciliation from '../pages/commissions/RebateReconciliation.vue'
import ImportFailures from '../pages/settings/ImportFailures.vue'
import AgentSupport from '../pages/support/AgentSupport.vue'
import AgentOperationSupport from '../pages/support/AgentOperationSupport.vue'

import { MODULES } from '../types/modules'

const IMPLEMENTED_MODULES = new Set([
  'auth', 'tenant-settings', 'carriers', 'products', 'contracts',
  'agents', 'customers', 'policies', 'renewal-pipeline',
  'commission-engine', 'commission-ledger', 'rebate-reconciliation',
  'import-failures',
  'agent-support', 'agent-operation-support',
  // Phase 2 (agent portal)
  'portal-dashboard', 'portal-profile', 'portal-referral', 'portal-settings', 'portal-earnings',
  // Phase 4 (admin agent oversight)
  'admin-agent-approvals', 'admin-downline-tree',
  // Phase 7b (admin payout cycles)
  'admin-payouts',
  // Phase 9 (motor tariff admin)
  'admin-motor-tariffs',
  // Phase 5 (quotation)
  'quotes',
  // Phase 8a (operational reports)
  'report-freelook', 'report-mailing', 'report-payments', 'report-cancellations',
])

const moduleRoutes: RouteRecordRaw[] = MODULES.filter((m) => !IMPLEMENTED_MODULES.has(m.key)).map((m) => ({
  path: m.routePath,
  name: m.routeName,
  component: ModulePlaceholder,
  meta: { moduleKey: m.key },
}))

const routes: RouteRecordRaw[] = [
  // Bare `/` is not a page — it either sends authenticated users to the
  // dashboard or unauthenticated users to login (the beforeEach guard picks).
  { path: '/', redirect: { name: 'dashboard' } },
  // Public marketing routes (public shell — nav + footer).
  {
    path: '/home',
    component: PublicLayout,
    children: [
      { path: '', name: 'public-home', component: PublicHome },
    ],
  },
  {
    path: '/register-agent',
    component: PublicLayout,
    children: [
      { path: '', name: 'register-agent', component: AgentRegister },
    ],
  },
  // Short recruitment URL — /r/<token> → /register-agent?ref=<token>
  {
    path: '/r/:token',
    component: PublicLayout,
    children: [
      { path: '', name: 'recruit-link', component: RecruitLinkRedirect },
    ],
  },
  // Public auth routes (no app shell)
  {
    path: '/',
    component: AuthLayout,
    children: [
      { path: 'login', name: 'login', component: Login },
      { path: 'forgot-password', name: 'forgot-password', component: ForgotPassword },
      { path: 'reset-password', name: 'reset-password', component: ResetPassword },
      { path: 'invite/:token?', name: 'accept-invitation', component: AcceptInvitation },
    ],
  },
  // Legacy /register redirect — the standalone tenant-signup page was
  // removed; agent self-registration lives at /register-agent.
  { path: '/register', redirect: { name: 'register-agent' } },
  // App-shell routes (authenticated)
  {
    path: '/',
    component: AppLayout,
    children: [
      // Explicit path so the URL after login is /dashboard, and so this
      // route doesn't collide at `/` with the AuthLayout above (Vue Router
      // matches the first record whose path fits, which was breaking the
      // post-login render on `/`).
      { path: 'dashboard', name: 'dashboard', component: Dashboard },
      { path: 'auth', name: 'auth', component: AuthModule, meta: { moduleKey: 'auth' } },
      { path: 'auth/mfa', name: 'auth-mfa', component: MfaSetup, meta: { moduleKey: 'auth' } },
      { path: 'settings', name: 'tenant-settings', component: TenantSettings, meta: { moduleKey: 'tenant-settings' } },
      { path: 'account', name: 'user-account', component: UserAccount },
      { path: 'carriers', name: 'carriers', component: CarrierManagement, meta: { moduleKey: 'carriers' } },
      { path: 'products', name: 'products', component: ProductManagement, meta: { moduleKey: 'products' } },
      { path: 'contracts', name: 'contracts', component: ContractManagement, meta: { moduleKey: 'contracts' } },
      { path: 'agents', name: 'agents', component: AgentList, meta: { moduleKey: 'agents' } },
      { path: 'agents/hierarchy', name: 'agents-hierarchy', component: AgentHierarchy, meta: { moduleKey: 'agents' } },
      { path: 'agents/recruitment', name: 'agents-recruitment', component: AgentRecruitment, meta: { moduleKey: 'agents' } },
      { path: 'customers', name: 'customers', component: CustomerList, meta: { moduleKey: 'customers' } },
      { path: 'customers/referrals', name: 'customers-referrals', component: CustomerReferral, meta: { moduleKey: 'customers' } },
      { path: 'policies', name: 'policies', component: PolicyList, meta: { moduleKey: 'policies' } },
      { path: 'policies/expiring', name: 'policies-expiring', component: ExpiringSoon, meta: { moduleKey: 'renewal-pipeline' } },
      { path: 'commissions/engine', name: 'commission-engine', component: CommissionEngine, meta: { moduleKey: 'commission-engine' } },
      { path: 'commissions/ledger', name: 'commission-ledger', component: CommissionLedger, meta: { moduleKey: 'commission-ledger' } },
      { path: 'commissions/rebates', name: 'commission-rebates', component: RebateReconciliation, meta: { moduleKey: 'rebate-reconciliation' } },
      { path: 'settings/import-failures', name: 'settings-import-failures', component: ImportFailures, meta: { moduleKey: 'import-failures' } },
      { path: 'support', name: 'agent-support', component: AgentSupport, meta: { moduleKey: 'agent-support' } },
      { path: 'ops', name: 'agent-operation-support', component: AgentOperationSupport, meta: { moduleKey: 'agent-operation-support' } },
      // Agent Portal (Phase 2) — role-guarded in the router beforeEach.
      { path: 'portal', name: 'portal-dashboard', component: PortalDashboard, meta: { moduleKey: 'portal-dashboard' } },
      { path: 'portal/profile', name: 'portal-profile', component: PortalProfile, meta: { moduleKey: 'portal-profile' } },
      { path: 'portal/referral', name: 'portal-referral', component: PortalReferral, meta: { moduleKey: 'portal-referral' } },
      { path: 'portal/settings', name: 'portal-settings', component: PortalSettings, meta: { moduleKey: 'portal-settings' } },
      { path: 'portal/earnings', name: 'portal-earnings', component: PortalEarnings, meta: { moduleKey: 'portal-earnings' } },
      // Admin approval + oversight (Phase 4). Role gate lives on backend.
      { path: 'admin/agent-approvals', name: 'admin-agent-approvals', component: AdminAgentApprovals, meta: { moduleKey: 'admin-agent-approvals' } },
      { path: 'admin/downline-tree', name: 'admin-downline-tree', component: AdminDownlineTree, meta: { moduleKey: 'admin-downline-tree' } },
      { path: 'admin/payouts', name: 'admin-payouts', component: AdminPayouts, meta: { moduleKey: 'admin-payouts' } },
      { path: 'admin/motor-tariffs', name: 'admin-motor-tariffs', component: AdminMotorTariffs, meta: { moduleKey: 'admin-motor-tariffs' } },
      // Phase 8a — operational reports
      { path: 'reports/freelook', name: 'report-freelook', component: FreelookReport, meta: { moduleKey: 'report-freelook' } },
      { path: 'reports/mailing', name: 'report-mailing', component: MailingReport, meta: { moduleKey: 'report-mailing' } },
      { path: 'reports/payments', name: 'report-payments', component: PaymentHistoryReport, meta: { moduleKey: 'report-payments' } },
      { path: 'reports/cancellations', name: 'report-cancellations', component: CancellationReport, meta: { moduleKey: 'report-cancellations' } },
      // Phase 5 — Quotation module.
      { path: 'quotes', name: 'quotes', component: QuoteList, meta: { moduleKey: 'quotes' } },
      { path: 'quotes/new', name: 'quote-new', component: QuoteEdit },
      { path: 'quotes/:id/edit', name: 'quote-edit', component: QuoteEdit },
      { path: 'quotes/:id', name: 'quote-detail', component: QuoteDetail },
      // Phase 6 — Policy sectioned edit
      { path: 'policies/:id/edit', name: 'policy-edit', component: PolicyEdit, meta: { moduleKey: 'policies' } },
      ...moduleRoutes,
    ],
  },
]

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes,
  scrollBehavior() {
    return { top: 0 }
  },
})

// Public routes that don't require a session.
const PUBLIC_ROUTE_NAMES = new Set([
  'public-home',
  'register-agent',
  'recruit-link',
  'login',
  'forgot-password',
  'reset-password',
  'accept-invitation',
])

// Lazily import the auth store inside the guard. Importing at module top-level
// triggers `useAuthStore()` before Pinia has been installed on the app.
router.beforeEach(async (to) => {
  const { useAuthStore } = await import('../stores/auth')
  const auth = useAuthStore()
  const isPublic = typeof to.name === 'string' && PUBLIC_ROUTE_NAMES.has(to.name)
  const isAgent = auth.isAuthenticated && auth.user?.role === 'agent'

  if (!auth.isAuthenticated && !isPublic) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }
  if (auth.isAuthenticated && isPublic) {
    return { name: isAgent ? 'portal-dashboard' : 'dashboard' }
  }
  // Agents land on their portal instead of the admin dashboard.
  if (isAgent && to.name === 'dashboard') {
    return { name: 'portal-dashboard' }
  }
})

export default router
