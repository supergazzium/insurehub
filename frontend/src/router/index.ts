import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import AppLayout from '../layouts/AppLayout.vue'
import AuthLayout from '../layouts/AuthLayout.vue'
import Dashboard from '../pages/Dashboard.vue'
import ModulePlaceholder from '../pages/ModulePlaceholder.vue'

import Login from '../pages/auth/Login.vue'
import Register from '../pages/auth/Register.vue'
import ForgotPassword from '../pages/auth/ForgotPassword.vue'
import ResetPassword from '../pages/auth/ResetPassword.vue'
import AcceptInvitation from '../pages/auth/AcceptInvitation.vue'
import AuthModule from '../pages/auth/AuthModule.vue'
import MfaSetup from '../pages/auth/MfaSetup.vue'
import TenantSettings from '../pages/settings/TenantSettings.vue'
import CarrierManagement from '../pages/carriers/CarrierManagement.vue'
import ProductManagement from '../pages/products/ProductManagement.vue'
import ContractManagement from '../pages/contracts/ContractManagement.vue'
import AgentList from '../pages/agents/AgentList.vue'
import AgentHierarchy from '../pages/agents/AgentHierarchy.vue'
import AgentRecruitment from '../pages/agents/AgentRecruitment.vue'
import CustomerList from '../pages/customers/CustomerList.vue'
import CustomerReferral from '../pages/customers/CustomerReferral.vue'
import PolicyList from '../pages/policies/PolicyList.vue'
import CommissionEngine from '../pages/commissions/CommissionEngine.vue'
import AgentSupport from '../pages/support/AgentSupport.vue'
import AgentOperationSupport from '../pages/support/AgentOperationSupport.vue'

import { MODULES } from '../types/modules'

const IMPLEMENTED_MODULES = new Set([
  'auth', 'tenant-settings', 'carriers', 'products', 'contracts',
  'agents', 'customers', 'policies', 'commission-engine',
  'agent-support', 'agent-operation-support',
])

const moduleRoutes: RouteRecordRaw[] = MODULES.filter((m) => !IMPLEMENTED_MODULES.has(m.key)).map((m) => ({
  path: m.routePath,
  name: m.routeName,
  component: ModulePlaceholder,
  meta: { moduleKey: m.key },
}))

const routes: RouteRecordRaw[] = [
  // Public auth routes (no app shell)
  {
    path: '/',
    component: AuthLayout,
    children: [
      { path: 'login', name: 'login', component: Login },
      { path: 'register', name: 'register', component: Register },
      { path: 'forgot-password', name: 'forgot-password', component: ForgotPassword },
      { path: 'reset-password', name: 'reset-password', component: ResetPassword },
      { path: 'invite/:token?', name: 'accept-invitation', component: AcceptInvitation },
    ],
  },
  // App-shell routes
  {
    path: '/',
    component: AppLayout,
    children: [
      { path: '', name: 'dashboard', component: Dashboard },
      { path: 'auth', name: 'auth', component: AuthModule, meta: { moduleKey: 'auth' } },
      { path: 'auth/mfa', name: 'auth-mfa', component: MfaSetup, meta: { moduleKey: 'auth' } },
      { path: 'settings', name: 'tenant-settings', component: TenantSettings, meta: { moduleKey: 'tenant-settings' } },
      { path: 'carriers', name: 'carriers', component: CarrierManagement, meta: { moduleKey: 'carriers' } },
      { path: 'products', name: 'products', component: ProductManagement, meta: { moduleKey: 'products' } },
      { path: 'contracts', name: 'contracts', component: ContractManagement, meta: { moduleKey: 'contracts' } },
      { path: 'agents', name: 'agents', component: AgentList, meta: { moduleKey: 'agents' } },
      { path: 'agents/hierarchy', name: 'agents-hierarchy', component: AgentHierarchy, meta: { moduleKey: 'agents' } },
      { path: 'agents/recruitment', name: 'agents-recruitment', component: AgentRecruitment, meta: { moduleKey: 'agents' } },
      { path: 'customers', name: 'customers', component: CustomerList, meta: { moduleKey: 'customers' } },
      { path: 'customers/referrals', name: 'customers-referrals', component: CustomerReferral, meta: { moduleKey: 'customers' } },
      { path: 'policies', name: 'policies', component: PolicyList, meta: { moduleKey: 'policies' } },
      { path: 'commissions/engine', name: 'commission-engine', component: CommissionEngine, meta: { moduleKey: 'commission-engine' } },
      { path: 'support', name: 'agent-support', component: AgentSupport, meta: { moduleKey: 'agent-support' } },
      { path: 'ops', name: 'agent-operation-support', component: AgentOperationSupport, meta: { moduleKey: 'agent-operation-support' } },
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
  'login',
  'register',
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

  if (!auth.isAuthenticated && !isPublic) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }
  if (auth.isAuthenticated && isPublic) {
    return { name: 'dashboard' }
  }
})

export default router
