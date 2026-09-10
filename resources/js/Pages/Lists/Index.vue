<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import axios from 'axios'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'

const playlists = ref([])
const loading = ref(true)
let pollTimer = null

async function load() {
  const { data } = await axios.get('/api/v1/playlists')
  playlists.value = data.data
  loading.value = false
}

function progressPercent(p) {
  if (!p.total_count) return 0
  return Math.round(((p.ok_count + p.failed_count) / p.total_count) * 100)
}

function isValidating(p) {
  return p.status === 'processing' && p.total_count > 0
}

function needsFirstValidation(p) {
  return p.status !== 'processing' && p.total_count > 0 && p.ok_count === 0 && p.failed_count === 0
}

function canRetry(p) {
  return !isValidating(p) && p.total_count > 0 && p.failed_count > 0 && (p.ok_count + p.failed_count) >= p.total_count
}

async function startValidation(playlist) {
  playlist._starting = true
  try {
    await axios.post(`/api/v1/playlists/${playlist.id}/validate`)
    await load()
  } catch (e) {
    alert(e.response?.data?.message ?? 'Falha ao iniciar verificação.')
  } finally {
    playlist._starting = false
  }
}

function statusBadge(status) {
  return {
    ok: 'bg-emerald-500/15 text-emerald-400',
    failed: 'bg-red-500/15 text-red-400',
    pending: 'bg-amber-500/15 text-amber-400',
    processing: 'bg-violet-500/15 text-violet-400',
  }[status] ?? 'bg-zinc-700 text-zinc-300'
}

onMounted(() => {
  load()
  pollTimer = setInterval(() => {
    if (playlists.value.some(isValidating)) load()
  }, 2500)
})

onUnmounted(() => clearInterval(pollTimer))
</script>

<template>
  <AuthenticatedLayout title="Listas carregadas">
    <div class="mx-auto max-w-5xl">
      <div class="overflow-x-auto rounded-xl border border-zinc-800 bg-zinc-900">
        <table class="w-full min-w-[720px] text-sm">
          <thead>
            <tr class="border-b border-zinc-800 text-left text-xs uppercase tracking-wider text-zinc-500">
              <th class="px-4 py-3">Nome / URL</th>
              <th class="px-4 py-3">Status</th>
              <th class="px-4 py-3 w-56">Progresso</th>
              <th class="px-4 py-3">OK</th>
              <th class="px-4 py-3">Falhou</th>
              <th class="px-4 py-3"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="p in playlists" :key="p.id" class="border-b border-zinc-800/60 last:border-0 hover:bg-zinc-800/40">
              <td class="max-w-xs truncate px-4 py-3 text-zinc-200">{{ p.name || p.url }}</td>
              <td class="px-4 py-3">
                <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="statusBadge(p.status)">{{ p.status }}</span>
              </td>
              <td class="px-4 py-3">
                <div v-if="p.total_count === 0" class="text-xs text-zinc-500">importando...</div>
                <div v-else>
                  <div class="h-2 w-full overflow-hidden rounded-full bg-zinc-800">
                    <div
                      class="h-full rounded-full bg-gradient-to-r from-violet-500 to-fuchsia-500 transition-all"
                      :style="{ width: progressPercent(p) + '%' }"
                    />
                  </div>
                  <p class="mt-1 text-xs text-zinc-500">
                    {{ p.ok_count + p.failed_count }}/{{ p.total_count }} ({{ progressPercent(p) }}%)
                  </p>
                </div>
              </td>
              <td class="px-4 py-3 text-emerald-400">{{ p.ok_count }}</td>
              <td class="px-4 py-3 text-red-400">{{ p.failed_count }}</td>
              <td class="px-4 py-3 text-right">
                <button
                  v-if="needsFirstValidation(p)"
                  class="rounded-md bg-violet-600 px-3 py-1 text-xs font-medium text-white transition hover:bg-violet-500 disabled:opacity-50"
                  :disabled="p._starting"
                  @click="startValidation(p)"
                >Iniciar verificação</button>
                <button
                  v-else-if="canRetry(p)"
                  class="rounded-md border border-zinc-700 px-3 py-1 text-xs font-medium text-zinc-200 transition hover:border-violet-500 hover:text-violet-300 disabled:opacity-50"
                  :disabled="p._starting"
                  @click="startValidation(p)"
                >Reprocessar falhas</button>
                <span v-else-if="isValidating(p)" class="text-xs text-zinc-500">verificando...</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <p v-if="!loading && playlists.length === 0" class="mt-6 text-center text-zinc-500">
        Nenhuma lista carregada ainda.
      </p>
    </div>
  </AuthenticatedLayout>
</template>
