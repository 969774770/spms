import { defineStore } from 'pinia'

/**
 * 文件管理器状态：当前软件/路径、多选、剪贴板、视图模式、搜索态
 */
export const useFmStore = defineStore('fm', {
  state: () => ({
    softwareId: '',
    dirPath: '',
    dirs: [],       // 当前目录子目录 [{id,path,name}]
    files: [],      // 当前目录文件
    stats: { dir_count: 0, file_count: 0, total_size: 0 },
    loading: false,
    // 多选
    selected: new Set(), // 形如 'f:3' / 'd:1'
    lastPicked: null,    // shift 区间选择锚点
    // 剪贴板 { op: 'copy'|'cut', files: [], dirs: [] }
    clipboard: null,
    // 视图（记忆到 localStorage）
    view: localStorage.getItem('fm_view') || 'list',
    // 排序 { key: name|size|mtime, dir: asc|desc }
    sort: { key: 'name', dir: 'asc' },
    // 筛选：全部 / blocked / ok
    filter: 'all',
    // 搜索态（非空时展示搜索结果视图）
    search: null, // { keyword, mode, scope, items, total, scanned }
    // 恢复绑定会话（断点续传）
    resumable: JSON.parse(localStorage.getItem('spms_resumable') || '[]'),
  }),
  getters: {
    selectedFiles: (s) => [...s.selected].filter((k) => k.startsWith('f:')).map((k) => Number(k.slice(2))),
    selectedDirs: (s) => [...s.selected].filter((k) => k.startsWith('d:')).map((k) => Number(k.slice(2))),
    hasSelection: (s) => s.selected.size > 0,
  },
  actions: {
    setView(v) {
      this.view = v
      localStorage.setItem('fm_view', v)
    },
    clearSelection() {
      this.selected = new Set()
      this.lastPicked = null
    },
    toggle(kind, id, additive = false) {
      if (!additive) this.selected = new Set()
      const key = `${kind}:${id}`
      const next = new Set(this.selected)
      if (next.has(key)) next.delete(key)
      else next.add(key)
      this.selected = next
      this.lastPicked = key
    },
    selectRange(keys) {
      const next = new Set(this.selected)
      keys.forEach((k) => next.add(k))
      this.selected = next
    },
    selectAll() {
      const next = new Set()
      this.visibleDirs.forEach((d) => next.add(`d:${d.id}`))
      this.visibleFiles.forEach((f) => next.add(`f:${f.id}`))
      this.selected = next
    },
    setClipboard(op) {
      this.clipboard = { op, files: this.selectedFiles, dirs: this.selectedDirs }
    },
    persistResumable() {
      localStorage.setItem('spms_resumable', JSON.stringify(this.resumable.slice(0, 50)))
    },
  },
})
