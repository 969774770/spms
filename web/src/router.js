import { createRouter, createWebHashHistory } from 'vue-router'
import { useUserStore } from './stores/user'
import { session } from './api/session'

// hash 路由：URL 形如 /index.html#/software/S0001/files
// 不依赖任何伪静态规则，刷新/直贴地址栏均可打开
const routes = [
  {
    path: '/login',
    name: 'login',
    component: () => import('./views/login.vue'),
    meta: { public: true, title: '登录' },
  },
  {
    path: '/',
    component: () => import('./layouts/main-layout.vue'),
    children: [
      { path: '', name: 'dashboard', component: () => import('./views/dashboard.vue'), meta: { title: '仪表盘' } },
      { path: 'software', name: 'software', component: () => import('./views/software-list.vue'), meta: { title: '软件管理' } },
      { path: 'software/:id', name: 'software-detail', component: () => import('./views/software-detail.vue'), meta: { title: '软件详情' } },
      { path: 'software/:id/files', name: 'software-files', component: () => import('./views/file-manager.vue'), meta: { title: '文件管理' } },
      { path: 'users', name: 'users', component: () => import('./views/users.vue'), meta: { title: '用户管理', admin: true } },
      { path: 'audit', name: 'audit', component: () => import('./views/audit.vue'), meta: { title: '审计日志', admin: true } },
      { path: 'settings', name: 'settings', component: () => import('./views/settings.vue'), meta: { title: '系统设置', admin: true } },
      { path: 'profile', name: 'profile', component: () => import('./views/profile.vue'), meta: { title: '个人资料' } },
    ],
  },
  { path: '/:pathMatch(.*)*', redirect: '/' },
]

const router = createRouter({
  history: createWebHashHistory(),
  routes,
})

router.beforeEach(async (to) => {
  const store = useUserStore()
  if (to.meta.public) {
    if (to.name === 'login' && store.isLogin) return { path: '/' }
    return true
  }
  // 首次导航先恢复登录态（刷新不掉线）
  if (!store.loaded) {
    session.onUnauthorized = () => {
      store.user = null
      store.roles = []
      store.loaded = true
    }
    await store.fetchMe()
  }
  if (!store.isLogin) {
    return { name: 'login', query: to.fullPath && to.fullPath !== '/' ? { redirect: to.fullPath } : {} }
  }
  if (to.meta.admin && !store.isAdmin) {
    return { name: 'dashboard' }
  }
  return true
})

export default router
