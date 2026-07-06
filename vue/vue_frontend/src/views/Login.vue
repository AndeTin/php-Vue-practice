<template>
  <div class="login-container">
    <h2>{{ isLoginMode ? '會員登入' : '會員註冊' }}</h2>
    
    <form @submit.prevent="submitForm">
      <div>
        <label>帳號：</label>
        <input type="text" v-model="form.account" required>
      </div>
      
      <div>
        <label>密碼：</label>
        <input type="password" v-model="form.password" required>
      </div>

      <template v-if="!isLoginMode">
        <div>
          <label>姓名：</label>
          <input type="text" v-model="form.name" required>
        </div>
        <div>
          <label>信箱：</label>
          <input type="email" v-model="form.email" required>
        </div>
      </template>

      <button type="submit">{{ isLoginMode ? '登入' : '註冊' }}</button>
    </form>

    <p class="toggle-text">
      {{ isLoginMode ? '還沒有帳號？' : '已經有帳號了？' }}
      <a href="#" @click.prevent="isLoginMode = !isLoginMode">
        切換到{{ isLoginMode ? '註冊' : '登入' }}
      </a>
    </p>

    <p v-if="message" class="msg">{{ message }}</p>
  </div>
</template>

<script setup>
import { ref, reactive } from 'vue'
import { useRouter } from 'vue-router'
import axios from 'axios'

const router = useRouter()
const isLoginMode = ref(true)
const message = ref('')

// 表單資料綁定
const form = reactive({
  account: '',
  password: '',
  name: '',
  email: ''
})

// 定義後端 API 的基礎網址
const API_BASE = 'http://localhost:8000'

const submitForm = async () => {
  try {
    if (isLoginMode.value) {
      // 執行登入請求
      const response = await axios.post(`${API_BASE}/login.php`, {
        account: form.account,
        password: form.password
      })
      
      if (response.data.success) {
        message.value = '登入成功！'
        // 將登入的帳號存入 localStorage 模擬維持登入狀態
        localStorage.setItem('userAccount', response.data.user.account)
        // 跳轉至後台
        router.push('/dashboard')
      } else {
        message.value = response.data.message
      }
    } else {
      // 執行註冊請求
      const response = await axios.post(`${API_BASE}/register.php`, form)
      message.value = response.data.message
      if (response.data.success) {
        // 註冊成功後自動切換回登入模式
        isLoginMode.value = true
        form.password = '' // 清空密碼欄位
      }
    }
  } catch (error) {
    message.value = 'API 請求失敗，請檢查後端伺服器是否啟動'
    console.error(error)
  }
}
</script>

<style scoped>
/* 簡單的美化，你可以依喜好調整 */
.login-container { border: 1px solid #ccc; padding: 20px; border-radius: 8px; }
form div { margin-bottom: 10px; }
.toggle-text a { color: blue; cursor: pointer; }
.msg { color: red; font-weight: bold; }
</style>
