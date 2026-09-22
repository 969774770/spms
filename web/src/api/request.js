import axios from 'axios'
import { ElMessage } from 'element-plus'
import { session } from './session'

/**
 * 统一请求层：
 * - 自动注入 X-CSRF-Token
 * - 响应信封解包 { ok, data, msg } / { ok:false, error:{code,message} }
 * - 401 跳登录；403/409/413/429 可读提示
 */
const http = axios.create({
  timeout: 30000,
  withCredentials: true,
})

http.interceptors.request.use((cfg) => {
  if (session.csrf) {
    cfg.headers['X-CSRF-Token'] = session.csrf
  }
  return cfg
})

http.interceptors.response.use(
  (resp) => {
    if (resp.config.responseType === 'blob') {
      return resp
    }
    const body = resp.data
    if (body && body.ok) {
      // 后端在登录/whoami 响应里带最新 csrf token
      if (body.data && typeof body.data === 'object' && 'csrf_token' in body.data) {
        session.csrf = body.data.csrf_token
      }
      return body.data
    }
    const msg = body?.error?.message || '请求失败'
    ElMessage.error(msg)
    return Promise.reject(body?.error || new Error(msg))
  },
  (err) => {
    const status = err.response?.status
    const data = err.response?.data
    const msg = data?.error?.message || (status ? `请求失败（HTTP ${status}）` : '网络异常，请检查连接')
    if (status === 401) {
      session.onUnauthorized?.()
      if (!location.hash.startsWith('#/login')) {
        ElMessage.error(msg)
        const redirect = location.hash.replace(/^#/, '')
        location.hash = '#/login' + (redirect && redirect !== '/' ? `?redirect=${encodeURIComponent(redirect)}` : '')
      }
    } else if (status === 403) {
      ElMessage.error(msg || '无权限执行该操作')
    } else if (status === 409) {
      ElMessage.warning(msg || '内容冲突')
    } else if (status === 413) {
      ElMessage.warning(msg || '超出大小限制')
    } else if (status === 429) {
      ElMessage.warning(msg || '操作过于频繁，请稍后再试')
    } else {
      ElMessage.error(msg)
    }
    return Promise.reject(data?.error || err)
  },
)

/** GET 读取接口 */
export function api(action, params = {}) {
  const q = new URLSearchParams()
  for (const [k, v] of Object.entries(params)) {
    if (v !== undefined && v !== null && v !== '') q.set(k, v)
  }
  const qs = q.toString()
  return http.get(`/api.php?action=${action}${qs ? '&' + qs : ''}`)
}

/** POST 写接口（自动带 CSRF 头） */
export function post(action, params = {}) {
  return http.post(`/api.php?action=${action}`, params)
}

export default http
