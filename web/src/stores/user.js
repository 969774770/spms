import { defineStore } from 'pinia'
import { api, post } from '../api/request'
import { session } from '../api/session'
import router from '../router'

export const useUserStore = defineStore('user', {
  state: () => ({
    user: null,
    roles: [],
    siteName: '',
    loaded: false, // 是否已尝试恢复登录态
  }),
  getters: {
    isLogin: (s) => !!s.user,
    isAdmin: (s) => s.roles.includes('admin'),
    canManage: (s) => s.roles.includes('admin') || s.roles.includes('publisher'),
  },
  actions: {
    applyMe(me) {
      this.user = me.user
      this.roles = me.roles || []
      this.siteName = me.site_name || ''
      if (me.csrf_token) session.csrf = me.csrf_token
    },
    async fetchMe() {
      try {
        this.applyMe(await api('auth.me'))
      } catch {
        // 401 已由请求层处理（onUnauthorized）
      } finally {
        this.loaded = true
      }
    },
    /** 登录页初始化：匿名会话取 CSRF token 与站点名 */
    async initAuth() {
      const init = await api('auth.init')
      session.csrf = init.csrf_token
      this.siteName = init.site_name
      return init
    },
    async login(username, password) {
      this.applyMe(await post('auth.login', { username, password }))
    },
    async logout() {
      try {
        await post('auth.logout')
      } catch {
        // 忽略退出接口异常，本地状态照常清理
      }
      this.user = null
      this.roles = []
      router.push('/login')
    },
    has(role) {
      return this.roles.includes(role)
    },
  },
})
