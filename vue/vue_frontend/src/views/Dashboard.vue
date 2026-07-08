<template>
  <div class="dashboard-container">
    <div class="header">
      <h2>會員管理後台</h2>
      <button class="logout-btn" @click="handleLogout">登出</button>
    </div>
    
    <div v-if="userInfo" class="user-card">
      <div class="welcome">
        <p>歡迎回來，{{ userInfo.name }}</p>
      </div>
      <button class="toggle-btn" @click="showUserList = !showUserList">
        {{ showUserList ? '切換回個人資料' : '切換至帳號列表' }}
      </button>
    </div>

    <div v-if="userInfo && !showUserList" class="profile-details">
      <p><strong>帳號：</strong> {{ userInfo.account }}</p>
      <p><strong>姓名：</strong> {{ userInfo.name }}</p>
      <p><strong>信箱：</strong> {{ userInfo.email }}</p>
      <p><strong>加入時間：</strong> {{ userInfo.created_at }}</p>
    </div>

    <hr />

    <div v-if="showUserList" class="list-section">
      
      <UserList 
        :page="inputPage" 
        :limit="inputLimit" 
        @update-pagination="handlePaginationUpdate"
      />

      <div class="control-panel bottom-panel">
        
        <div class="pagination">
          <button 
            class="page-btn" 
            :disabled="inputPage <= 1" 
            @click="inputPage--"
          >
            ❮ 上一頁
          </button>
          
          <span class="page-info">第 {{ inputPage }} / {{ totalPages }} 頁</span>
          
          <button 
            class="page-btn" 
            :disabled="inputPage >= totalPages" 
            @click="inputPage++"
          >
            下一頁 ❯
          </button>
        </div>

        <div class="limit-setting">
          <label>
            每頁顯示：
            <select v-model.number="inputLimit">
              <option value="3">3 筆</option>
              <option value="5">5 筆</option>
              <option value="10">10 筆</option>
              <option value="20">20 筆</option>
            </select>
          </label>
        </div>

      </div>
    </div>

  </div>
</template>

<script setup>
import { ref, watch, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import axios from 'axios'
import UserList from '@/components/UserList.vue'

const router = useRouter()
const userInfo = ref(null)
const showUserList = ref(false)

// 分頁控制變數
const inputPage = ref(1)
const inputLimit = ref(5)
const totalPages = ref(1)

// 當 UserList 撈完資料後，會觸發這個方法更新總頁數
const handlePaginationUpdate = (paginationData) => {
  totalPages.value = paginationData.total_pages || 1
}

// 貼心 UX：如果使用者修改了「每頁顯示筆數」，自動幫他跳回第一頁
watch(inputLimit, () => {
  inputPage.value = 1
})

onMounted(async () => {
  const token = localStorage.getItem('userToken')
  if (!token) {
    router.push('/login')
    return
  }

  try {
    const response = await axios.get('http://localhost:8000/profile.php', {
      headers: { 'Authorization': `Bearer ${token}` }
    })
    if (response.data.success) {
      userInfo.value = response.data.user
    } else {
      handleLogout()
    }
  } catch (error) {
    handleLogout()
  }
})

const handleLogout = () => {
  localStorage.removeItem('userToken')
  router.push('/login')
}
</script>

<style scoped>
.dashboard-container { border: 1px solid #4CAF50; padding: 20px; border-radius: 8px; }
.header { display: flex; justify-content: space-between; align-items: center; }
.logout-btn { background-color: #f44336; color: white; border: none; padding: 8px 16px; cursor: pointer; border-radius: 4px;}
.logout-btn:hover { background-color: #d32f2f; }

.user-card { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 10px; }
.toggle-btn { background-color: #2196F3; color: white; border: none; padding: 6px 12px; cursor: pointer; border-radius: 4px; font-size: 0.9em; transition: background-color 0.2s; }
.toggle-btn:hover { background-color: #1976D2; }

.welcome { opacity: 0.9; margin-bottom: 0;}
.profile-details { margin-top: 15px; padding: 15px; background-color: rgba(128, 128, 128, 0.1); border-radius: 8px; line-height: 1.6; }
.profile-details p { margin: 8px 0; }
hr { border: 0; border-top: 1px solid #555; margin: 20px 0;}

/* 下方控制面板的 Flex 排版 */
.bottom-panel { 
  background-color: rgba(76, 175, 80, 0.15); 
  border: 1px solid rgba(76, 175, 80, 0.3);
  padding: 12px 15px; 
  border-radius: 8px; 
  margin-top: 15px; 
  display: flex;
  justify-content: space-between; 
  align-items: center;
}

/* 分頁按鈕區塊 */
.pagination { display: flex; align-items: center; gap: 15px; }
.page-info { font-weight: bold; }

/* 左右按鈕美化 */
.page-btn {
  background-color: rgba(255, 255, 255, 0.1);
  border: 1px solid #888;
  color: inherit;
  padding: 6px 12px;
  cursor: pointer;
  border-radius: 4px;
  transition: all 0.2s;
}
.page-btn:hover:not(:disabled) { background-color: rgba(255, 255, 255, 0.2); }
.page-btn:disabled { opacity: 0.3; cursor: not-allowed; } 

/* 右側下拉選單樣式 */
select { 
  padding: 6px 10px; 
  border-radius: 4px;
  border: 1px solid #888;
  background-color: rgba(255, 255, 255, 0.1); 
  color: inherit; 
}
select option { background-color: #333; color: white; }
</style>
