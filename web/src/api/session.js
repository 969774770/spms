/**
 * 会话级可变状态桥：避免 request.js 与 store 循环依赖
 * csrf 由后端 session 签发，登录/whoami 响应刷新
 */
export const session = {
  csrf: '',
  onUnauthorized: null,
}
