<script setup>
import { onMounted, ref } from 'vue'
import axios from 'axios'

const playlists = ref([])
const loading = ref(true)

async function load() {
  loading.value = true
  const { data } = await axios.get('/api/v1/playlists')
  playlists.value = data.data
  loading.value = false
}

async function recheck(playlist) {
  playlist._rechecking = true
  await axios.post(`/api/v1/playlists/${playlist.id}/recheck`)
  await load()
}

onMounted(load)
</script>

<template>
  <div class="max-w-5xl mx-auto p-6">
    <h1 class="text-xl font-semibold mb-4">Listas carregadas</h1>

    <table class="w-full text-sm border-collapse">
      <thead>
        <tr class="text-left border-b">
          <th class="py-2">Nome / URL</th>
          <th>Status</th>
          <th>Total</th>
          <th>OK</th>
          <th>Falhou</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="p in playlists" :key="p.id" class="border-b">
          <td class="py-2">{{ p.name || p.url }}</td>
          <td>
            <span
              class="px-2 py-0.5 rounded text-xs"
              :class="{
                'bg-green-100 text-green-700': p.status === 'ok',
                'bg-red-100 text-red-700': p.status === 'failed',
                'bg-yellow-100 text-yellow-700': ['pending', 'processing'].includes(p.status),
              }"
            >{{ p.status }}</span>
          </td>
          <td>{{ p.total_count }}</td>
          <td>{{ p.ok_count }}</td>
          <td>{{ p.failed_count }}</td>
          <td>
            <button
              class="text-blue-600 underline disabled:opacity-50"
              :disabled="p._rechecking"
              @click="recheck(p)"
            >Reprocessar</button>
          </td>
        </tr>
      </tbody>
    </table>

    <p v-if="!loading && playlists.length === 0" class="text-gray-500 mt-4">
      Nenhuma lista carregada ainda.
    </p>
  </div>
</template>
