import { createRouter, createWebHistory } from 'vue-router'
import Login from '../views/Login.vue'
import Dashboard from '../views/Dashboard.vue'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/',
      redirect: '/login'
    },
    {
      path: '/login',
      name: 'login',
      component: Login
    },
    {
      path: '/dashboard',
      name: 'dashboard',
      component: Dashboard,
      // 💡 加入 meta 欄位，標記這個頁面需要登入驗證
      meta: { requiresAuth: true }
    }
  ]
})

// 👮 路由守衛：每次網址切換前都會執行這個函式
router.beforeEach((to, from, next) => {
  // 1. 檢查 localStorage 是否有儲存帳號（判斷是否登入）
  const isAuthenticated = !!localStorage.getItem('userAccount')

  // 2. 如果目標頁面需要驗證 (requiresAuth)，且使用者尚未登入
  if (to.matched.some(record => record.meta.requiresAuth) && !isAuthenticated) {
    alert('此頁面需要登入，正在幫您導向登入頁面')
    next('/login') // 強制導向登入頁
  } 
  // 3. 如果使用者已經登入，卻還想去登入頁面 (/login)
  else if (to.path === '/login' && isAuthenticated) {
    next('/dashboard') // 自動幫他跳轉到後台，不讓他重複登入
  } 
  // 4. 其他情況一律正常放行
  else {
    next() 
  }
})

export default router
