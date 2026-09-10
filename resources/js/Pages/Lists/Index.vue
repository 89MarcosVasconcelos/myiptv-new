<script setup>
import { onMounted, ref } from 'vue'
import axios from 'axios'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'

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

function statusBadge(status) {
  return {
    ok: 'bg-emerald-500/15 text-emerald-400',
    failed: 'bg-red-500/15 text-red-400',
    pending: 'bg-amber-500/15 text-amber-400',
    processing: 'bg-amber-500/15 text-amber-400',
  }[status] ?? 'bg-zinc-700 text-zinc-300'
}

onMounted(load)
</script>

<template>
  <AuthenticatedLayout title="Listas carregadas">
    <div class="mx-auto max-w-5xl">
      <div class="overflow-x-auto rounded-xl border border-zinc-800 bg-zinc-900">
        <table class="w-full min-w-[640px] text-sm">
          <thead>
            <tr class="border-b border-zinc-800 text-left text-xs uppercase tracking-wider text-zinc-500">
              <th class="px-4 py-3">Nome / URL</th>
              <th class="px-4 py-3">Status</th>
              <th class="px-4 py-3">Total</th>
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
              <td class="px-4 py-3 text-zinc-300">{{ p.total_count }}</td>
              <td class="px-4 py-3 text-emerald-400">{{ p.ok_count }}</td>
              <td class="px-4 py-3 text-red-400">{{ p.failed_count }}</td>
              <td class="px-4 py-3 text-right">
                <button
                  class="rounded-md border border-zinc-700 px-3 py-1 text-xs font-medium text-zinc-200 transition hover:border-violet-500 hover:text-violet-300 disabled:opacity-50"
                  :disabled="p._rechecking"
                  @click="recheck(p)"
                >Reprocessar</button>
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
