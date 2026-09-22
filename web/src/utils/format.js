import dayjs from 'dayjs'
import utc from 'dayjs/plugin/utc'

dayjs.extend(utc)

/** 后端时间（ISO8601 UTC）→ 本地展示 */
export function fmtTime(iso, tpl = 'YYYY-MM-DD HH:mm:ss') {
  if (!iso) return '-'
  const d = dayjs.utc(iso)
  return d.isValid() ? d.local().format(tpl) : iso
}

/** 字节数人性化 */
export function fmtSize(bytes) {
  const n = Number(bytes) || 0
  if (n < 1024) return `${n} B`
  const units = ['KB', 'MB', 'GB', 'TB']
  let v = n
  let i = -1
  do {
    v /= 1024
    i++
  } while (v >= 1024 && i < units.length - 1)
  return `${v.toFixed(v >= 100 ? 0 : 1)} ${units[i]}`
}

/** 角色代码 → 中文名 */
export const ROLE_NAMES = { admin: '管理员', publisher: '发布员', viewer: '访客' }

/** 可在线编辑的文本扩展名（与后端 fs_is_text_ext 呼应，前端用于显隐编辑入口） */
export function isTextEditable(name) {
  const ext = name.includes('.') ? name.split('.').pop().toLowerCase() : ''
  return ['', 'txt', 'md', 'json', 'xml', 'yml', 'yaml', 'ini', 'conf', 'cfg', 'log', 'html', 'htm', 'css', 'js', 'mjs', 'ts', 'php', 'py', 'go', 'java', 'sql', 'csv', 'sh', 'bat', 'cmd', 'ps1', 'svg', 'vue', 'jsx', 'tsx', 'toml', 'properties', 'lock', 'gitignore', 'htaccess'].includes(ext)
}
