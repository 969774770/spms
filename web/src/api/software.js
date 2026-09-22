import { api, post } from './request'

/** 软件域接口 */
export const softwareApi = {
  list: (params) => api('software.list', params),
  get: (id) => api('software.get', { id }),
  save: (data) => post('software.save', data),
  remove: (id) => post('software.delete', { id }),
  publish: (id) => post('software.publish', { id }),
  publishAll: () => post('software.publishAll'),
  reorder: (ids) => post('software.reorder', { ids }),
}
