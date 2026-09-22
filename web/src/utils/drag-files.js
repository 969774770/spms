/**
 * 从拖放事件收集文件（支持文件夹递归，保留相对路径）
 * 用法：collectFiles(ev.dataTransfer).then((files) => ...)
 */
export function collectFiles(dt) {
  const out = []
  const items = dt.items ? [...dt.items] : []
  const entries = items.map((i) => (i.webkitGetAsEntry ? i.webkitGetAsEntry() : null)).filter(Boolean)
  if (!entries.length) return Promise.resolve([...dt.files])
  const readEntry = (entry, base) => new Promise((resolve) => {
    if (entry.isFile) {
      entry.file((f) => { f._relPath = base + f.name; out.push(f); resolve() }, resolve)
    } else if (entry.isDirectory) {
      const reader = entry.createReader()
      const all = []
      const readBatch = () => reader.readEntries((batch) => {
        if (!batch.length) {
          Promise.all(all).then(() => resolve())
          return
        }
        batch.forEach((e) => all.push(readEntry(e, base + entry.name + '/')))
        readBatch()
      }, resolve)
      readBatch()
    } else resolve()
  })
  return Promise.all(entries.map((e) => readEntry(e, ''))).then(() => out)
}
