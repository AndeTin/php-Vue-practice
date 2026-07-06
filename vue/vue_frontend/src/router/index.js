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
  // 改為檢查 localStorage 是否有 Token
  const isAuthenticated = !!localStorage.getItem('userToken')

  if (to.matched.some(record => record.meta.requiresAuth) && !isAuthenticated) {
    alert('此頁面需要登入，正在幫您導向登入頁面')
    next('/login')
  } else if (to.path === '/login' && isAuthenticated) {
    next('/dashboard')
  } else {
    next() 
  }
})

export default router
