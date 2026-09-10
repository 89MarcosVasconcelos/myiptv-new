<script setup>
import { ref, watch } from 'vue'
import axios from 'axios'
import { Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'

const props = defineProps({
  playlist: { type: Object, required: true },
})

const channels = ref([])
const meta = ref(null)
const loading = ref(true)
const q = ref('')
const page = ref(1)

async function load() {
  loading.value = true
  try {
    const { data } = await axios.get(`/api/v1/playlists/${props.playlist.id}/errors`, {
      params: { q: q.value || undefined, page: page.value },
    })
    channels.value = data.data
    meta.value = { current_page: data.current_page, last_page: data.last_page, total: data.total }
  } finally {
    loading.value = false
  }
}

async function recheck(channel) {
  channel._busy = true
  try {
    await axios.post(`/api/v1/channels/${channel.id}/recheck`)
    channel._justQueued = true
  } catch (e) {
    alert(e.response?.data?.message ?? 'Falha ao reenviar para verificação.')
  } finally {
    channel._busy = false
  }
}

function goToPage(p) {
  if (p < 1 || (meta.value && p > meta.value.last_page)) return
  page.value = p
  load()
}

let searchTimer = null
watch(q, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    page.value = 1
    load()
  }, 350)
})

load()
</script>

<template>
  <AuthenticatedLayout title="Log de erros">
    <div class="mx-auto max-w-5xl">
      <div class="mb-4 flex items-center justify-between">
        <div>
          <Link :href="route('listas.index')" class="text-xs text-zinc-500 hover:text-violet-400">&larr; Voltar para listas</Link>
          <h2 class="mt-1 text-lg font-semibold text-zinc-100">{{ playlist.name || playlist.url }}</h2>
          <p class="text-xs text-zinc-500">Canais com falha ou marcados como mortos, com o motivo da última verificação.</p>
        </div>
        <input
          v-model="q"
          type="text"
          placeholder="Buscar por nome ou URL..."
          class="w-64 rounded-md border border-zinc-700 bg-zinc-900 px-3 py-1.5 text-sm text-zinc-200 placeholder:text-zinc-600 focus:border-violet-500 focus:outline-none"
        />
      </div>

      <div class="overflow-x-auto rounded-xl border border-zinc-800 bg-zinc-900">
        <table class="w-full min-w-[860px] text-sm">
          <thead>
            <tr class="border-b border-zinc-800 text-left text-xs uppercase tracking-wider text-zinc-500">
              <th class="px-4 py-3">Nome</th>
              <th class="px-4 py-3">URL</th>
              <th class="px-4 py-3">HTTP</th>
              <th class="px-4 py-3">Content-Type</th>
              <th class="px-4 py-3">ffprobe</th>
              <th class="px-4 py-3">Motivo</th>
              <th class="px-4 py-3"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="c in channels" :key="c.id" class="border-b border-zinc-800/60 last:border-0 hover:bg-zinc-800/40 align-top">
              <td class="max-w-[160px] truncate px-4 py-3 text-zinc-200" :title="c.name">{{ c.name || '(sem nome)' }}</td>
              <td class="max-w-[220px] truncate px-4 py-3 text-zinc-400" :title="c.url">{{ c.url }}</td>
              <td class="px-4 py-3 text-zinc-300">{{ c.checks?.[0]?.http_status ?? '—' }}</td>
              <td class="px-4 py-3 text-zinc-300">{{ c.checks?.[0]?.detected_content_type ?? '—' }}</td>
              <td class="px-4 py-3">
                <span v-if="c.checks?.[0]?.ffprobe_ok === true" class="text-emerald-400">ok</span>
                <span v-else-if="c.checks?.[0]?.ffprobe_ok === false" class="text-red-400">falhou</span>
                <span v-else class="text-zinc-600">—</span>
              </td>
              <td class="max-w-[220px] px-4 py-3 text-zinc-400">{{ c.checks?.[0]?.message ?? '—' }}</td>
              <td class="px-4 py-3 text-right">
                <span v-if="c._justQueued" class="text-xs text-emerald-400">reenfileirado</span>
                <button
                  v-else
                  class="rounded-md border border-zinc-700 px-2.5 py-1 text-xs font-medium text-zinc-200 transition hover:border-violet-500 hover:text-violet-300 disabled:opacity-50"
                  :disabled="c._busy"
                  @click="recheck(c)"
                >Reverificar</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <p v-if="!loading && channels.length === 0" class="mt-6 text-center text-zinc-500">
        Nenhum canal com falha encontrado{{ q ? ' para essa busca' : '' }}.
      </p>

      <div v-if="meta && meta.last_page > 1" class="mt-4 flex items-center justify-center gap-3 text-sm text-zinc-400">
        <button class="rounded-md border border-zinc-700 px-2 py-1 disabled:opacity-40" :disabled="meta.current_page <= 1" @click="goToPage(meta.current_page - 1)">Anterior</button>
        <span>Página {{ meta.current_page }} de {{ meta.last_page }} ({{ meta.total }} canais)</span>
        <button class="rounded-md border border-zinc-700 px-2 py-1 disabled:opacity-40" :disabled="meta.current_page >= meta.last_page" @click="goToPage(meta.current_page + 1)">Próxima</button>
      </div>
    </div>
  </AuthenticatedLayout>
</template>
