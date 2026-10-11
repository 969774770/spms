import { defineStore } from 'pinia'
import { uploadApi } from '../api/fs'
import { hashFile } from '../utils/sha256'
import { useFmStore } from './fm'

/**
 * 上传队列引擎：
 * 计算 SHA-256 → 秒传探测 → init/part(并发3)/finish；支持暂停/继续/重试/断点续传
 * 断点续传：会话元数据存 localStorage（spms_resumable，由 fm store 持久化），
 * 刷新后用户重新添加同名同大小文件即自动绑定并从已传分片继续
 */
const CHUNK = 5 * 1024 * 1024

let uidSeq = Date.now()

export const useUploadStore = defineStore('upload', {
  state: () => ({
    items: [], // {uid,name,relPath,dirPath,file,size,hash,uuid,chunkSize,totalParts,uploadedParts:[],status,progress,error,speed,_speedBase}
    dialogVisible: false,
    running: false,
    targetDir: '',
  }),
  getters: {
    activeCount: (s) => s.items.filter((i) => ['pending', 'hashing', 'uploading'].includes(i.status)).length,
    doneCount: (s) => s.items.filter((i) => i.status === 'done').length,
  },
  actions: {
    openDialog() {
      this.dialogVisible = true
    },
    /** 添加文件（folder 展平由调用方完成，relPath 形如 sub/dir/name.ext） */
    add(files) {
      const base = (this.targetDir || '').replace(/\/+$/, '')
      for (const f of files) {
        const relPath = f._relPath || f.name
        const derived = relPath.includes('/') ? relPath.slice(0, relPath.lastIndexOf('/')) : ''
        // 目标目录 = 当前所在目录 + 文件夹相对路径（在哪个目录上传就放到哪个目录）
        const dirPath = base ? `${base}/${derived}` : derived
        const rIdx = this.items.findIndex((i) => i.status === 'paused-resume' && i.name === f.name && i.size === f.size && i.relPath === relPath)
        const item = {
          uid: ++uidSeq,
          name: f.name,
          relPath,
          dirPath,
          file: f,
          size: f.size,
          hash: null,
          uuid: null,
          chunkSize: CHUNK,
          totalParts: 0,
          uploadedParts: [],
          status: 'pending',
          progress: 0,
          speed: 0,
          error: '',
          _speedBase: null,
        }
        if (rIdx >= 0) {
          const r = this.items[rIdx]
          if (r.dirPath === dirPath) {
            // 仅同目录才可复用断点会话：会话 uuid 绑定服务端 dir_path，跨目录复用会把文件传进旧目录
            item.uid = r.uid
            item.hash = r.hash
            item.uuid = r.uuid
            item.chunkSize = r.chunkSize
            item.totalParts = r.totalParts
            item.uploadedParts = r.uploadedParts
            item.progress = Math.round((r.uploadedParts.length / Math.max(1, r.totalParts)) * 100)
            item.status = 'paused'
          }
          // 不同目录：丢弃旧断点（hash 也可能属于不同内容），按全新上传处理
          this.items.splice(rIdx, 1, item)
          this.items.splice(this.items.indexOf(item), 1) // 移除原位置，push 到尾部
          this.items.push(item)
        } else {
          this.items.push(item)
        }
      }
      this.dialogVisible = true
      this._persist()
      this._pump()
    },
    restoreFromFm(resumable) {
      for (const r of resumable) {
        if (!this.items.some((i) => i.uuid === r.uuid)) {
          this.items.push({ ...r, file: null, status: 'paused-resume', progress: Math.round((r.uploadedParts.length / Math.max(1, r.totalParts)) * 100) })
        }
      }
    },
    _persist() {
      const data = this.items
        .filter((i) => ['uploading', 'paused', 'pending', 'hashing'].includes(i.status) && i.uuid)
        .map((i) => ({ uid: i.uid, name: i.name, relPath: i.relPath, size: i.size, hash: i.hash, uuid: i.uuid, chunkSize: i.chunkSize, totalParts: i.totalParts, uploadedParts: i.uploadedParts }))
      localStorage.setItem('spms_resumable', JSON.stringify(data))
    },
    async start(uid) {
      const item = this.items.find((i) => i.uid === uid)
      if (!item) return
      item.error = ''
      try {
        if (item.status === 'paused-resume') {
          await this._finishFlow(item)
          return
        }
        if (!item.hash) {
          item.status = 'hashing'
          item.hash = await hashFile(item.file, (p) => { item.progress = Math.round(p * 100) })
        }
        // 秒传探测
        const probe = await uploadApi.probe({
          software_id: this._sid(), dir_path: item.dirPath, file_name: item.name, size: item.size, hash: item.hash,
        })
        if (probe.instant) {
          item.status = 'done'
          item.progress = 100
          this._persist()
          this._notify()
          this._pump()
          return
        }
        // 会话（已有 uuid 则续传）
        if (!item.uuid) {
          const init = await uploadApi.init({
            software_id: this._sid(), dir_path: item.dirPath, file_name: item.name, size: item.size, hash: item.hash,
          })
          item.uuid = init.uuid
          item.chunkSize = init.chunk_size
          item.totalParts = init.total_parts
          item.uploadedParts = init.uploaded_parts || []
        } else {
          // 校验会话仍有效
          try {
            const st = await uploadApi.status(item.uuid)
            item.uploadedParts = st.uploaded_parts || []
            item.totalParts = st.total_parts
          } catch { item.uuid = null }
        }
        item.status = 'uploading'
        await this._uploadParts(item)
        await this._finishFlow(item)
      } catch (e) {
        if (item.status !== 'paused') {
          item.status = 'failed'
          item.error = e?.message || '上传失败'
        }
      } finally {
        this._persist()
        this._pump()
      }
    },
    async _finishFlow(item) {
      if (item.uuid && item.status !== 'done') {
        item.status = 'uploading'
        await uploadApi.finish(item.uuid)
        item.status = 'done'
        item.progress = 100
        this._notify()
      }
    },
    async _uploadParts(item) {
      const total = item.totalParts
      const done = new Set(item.uploadedParts)
      const pending = []
      for (let p = 1; p <= total; p++) if (!done.has(p)) pending.push(p)
      item._speedBase = { t: Date.now(), uploaded: item.uploadedParts.length * item.chunkSize }
      const workers = new Array(3).fill(0).map(async () => {
        while (pending.length) {
          if (item.status === 'paused') return
          const p = pending.shift()
          if (p === undefined) break
          const start = (p - 1) * item.chunkSize
          const blob = item.file.slice(start, Math.min(start + item.chunkSize, item.size))
          await uploadApi.part(item.uuid, p, blob)
          item.uploadedParts.push(p)
          item.progress = Math.round((item.uploadedParts.length / total) * 100)
          const base = item._speedBase
          if (base) {
            const secs = (Date.now() - base.t) / 1000
            item.speed = secs > 0.3 ? Math.round((item.uploadedParts.length * item.chunkSize - base.uploaded) / secs) : item.speed
          }
        }
      })
      await Promise.all(workers)
      if (item.status === 'paused') throw { paused: true }
    },
    pause(uid) {
      const item = this.items.find((i) => i.uid === uid)
      if (item && ['uploading', 'hashing', 'pending'].includes(item.status)) {
        item.status = 'paused'
      }
    },
    async removeItem(uid) {
      const idx = this.items.findIndex((i) => i.uid === uid)
      if (idx < 0) return
      const item = this.items[idx]
      if (item.uuid && ['uploading', 'paused', 'paused-resume', 'failed'].includes(item.status)) {
        try { await uploadApi.abort(item.uuid) } catch { /* 会话可能已过期 */ }
      }
      this.items.splice(idx, 1)
      this._persist()
      this._pump()
    },
    clearDone() {
      this.items = this.items.filter((i) => i.status !== 'done')
      this._persist()
    },
    async clearAll() {
      for (const item of this.items) {
        if (item.uuid && ['uploading', 'paused', 'paused-resume', 'failed'].includes(item.status)) {
          try { await uploadApi.abort(item.uuid) } catch { /* 会话可能已过期 */ }
        }
      }
      this.items = []
      this._persist()
    },
    retryAll() {
      this.items.filter((i) => i.status === 'failed' || i.status === 'paused').forEach((i) => { i.status = 'pending' })
      this._pump()
    },
    _sid() {
      return useFmStore().softwareId
    },
    _notify() {
      window.dispatchEvent(new CustomEvent('spms-uploaded'))
    },
    _pump() {
      if (this.running) return
      const next = this.items.find((i) => i.status === 'pending' || (i.status === 'paused' && i.hash))
      if (!next) return
      this.running = true
      this.start(next.uid).finally(() => {
        this.running = false
        this._pump()
      })
    },
  },
})
