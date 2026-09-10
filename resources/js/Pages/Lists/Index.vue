<script setup>
import { onMounted, onUnmounted, ref } from 'vue'
import axios from 'axios'
import { Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'

const playlists = ref([])
const loading = ref(true)
const resettingQueue = ref(false)
const resetMessage = ref('')
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

// Um so botao pra "iniciar" e "continuar/reprocessar": cobre a lista nova
// (nada verificado ainda), a que falhou tudo, e — o caso que ficava sem
// nenhum botao antes — a que foi cancelada no meio com uma mistura de
// canais ok/falhos/ainda pendentes.
function needsValidation(p) {
  return p.status !== 'processing' && p.total_count > 0 && (p.pending_count > 0 || p.failed_count > 0)
}

function validationLabel(p) {
  return p.ok_count === 0 && p.failed_count === 0 ? 'Iniciar verificação' : 'Continuar verificação'
}

// A IMPORTACAO em si (nao a verificacao) travou ou falhou: a lista nunca
// chegou a ganhar nenhum canal. Se deu erro explicito, sempre pode tentar de
// novo; se so esta "pendente"/"processando" sem nada acontecer, so oferece
// o botao depois de um tempo (evita aparecer num import que acabou de comecar).
function isStuckImport(p) {
  if (p.total_count > 0) return false
  if (p.status === 'failed') return true
  const ageMs = Date.now() - new Date(p.updated_at).getTime()
  return ageMs > 90_000
}

function isImporting(p) {
  return p.total_count === 0 && p.status !== 'failed' && !isStuckImport(p)
}

async function startValidation(playlist) {
  playlist._busy = true
  try {
    await axios.post(`/api/v1/playlists/${playlist.id}/validate`)
    await load()
  } catch (e) {
    alert(e.response?.data?.message ?? 'Falha ao iniciar verificação.')
  } finally {
    playlist._busy = false
  }
}

async function cancelValidation(playlist) {
  if (!confirm('Cancelar a verificação em andamento desta lista?')) return
  playlist._busy = true
  try {
    await axios.post(`/api/v1/playlists/${playlist.id}/cancel`)
    await load()
  } catch (e) {
    alert(e.response?.data?.message ?? 'Falha ao cancelar verificação.')
  } finally {
    playlist._busy = false
  }
}

async function reimportPlaylist(playlist) {
  playlist._busy = true
  try {
    await axios.post(`/api/v1/playlists/${playlist.id}/reimport`)
    await load()
  } catch (e) {
    alert(e.response?.data?.message ?? 'Falha ao reiniciar importação.')
  } finally {
    playlist._busy = false
  }
}

async function deletePlaylist(playlist) {
  if (!confirm(`Remover a lista "${playlist.name || playlist.url}"? Essa ação não pode ser desfeita.`)) return
  playlist._busy = true
  try {
    await axios.delete(`/api/v1/playlists/${playlist.id}`)
    await load()
  } catch (e) {
    alert(e.response?.data?.message ?? 'Falha ao remover lista.')
    playlist._busy = false
  }
}

// Botão de emergência: limpa jobs presos/duplicados da fila e destrava
// qualquer lista parada em "processing" — sem precisar abrir terminal.
async function resetQueue() {
  if (!confirm('Isso limpa a fila de processamento (jobs presos ou duplicados) e destrava listas paradas em "processando". Continuar?')) return
  resettingQueue.value = true
  resetMessage.value = ''
  try {
    const { data } = await axios.post('/api/v1/playlists/reset-queue')
    resetMessage.value = `Fila zerada: ${data.jobs_cleared} job(s) removido(s), ${data.failed_jobs_cleared} falha(s) antiga(s) limpa(s), ${data.playlists_unstuck} lista(s) destravada(s).`
    await load()
  } catch (e) {
    alert(e.response?.data?.message ?? 'Falha ao zerar a fila.')
  } finally {
    resettingQueue.value = false
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
    if (playlists.value.some((p) => isValidating(p) || isImporting(p))) load()
  }, 2500)
})

onUnmounted(() => clearInterval(pollTimer))
</script>

<template>
  <AuthenticatedLayout title="Listas carregadas">
    <div class="mx-auto max-w-5xl">
      <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <p v-if="resetMessage" class="text-xs text-emerald-400">{{ resetMessage }}</p>
        <p v-else class="text-xs text-zinc-500">Lista travada ou fila emperrada? Zere a fila abaixo — não precisa de terminal.</p>
        <button
          class="rounded-md border border-zinc-700 px-3 py-1.5 text-xs font-medium text-zinc-300 transition hover:border-red-500 hover:text-red-300 disabled:opacity-50"
          :disabled="resettingQueue"
          title="Limpa jobs presos/duplicados da fila de processamento e destrava listas paradas em 'processando'."
          @click="resetQueue"
        >{{ resettingQueue ? 'Zerando...' : 'Zerar filas' }}</button>
      </div>

      <div class="overflow-x-auto rounded-xl border border-zinc-800 bg-zinc-900">
        <table class="w-full min-w-[820px] text-sm">
          <thead>
            <tr class="border-b border-zinc-800 text-left text-xs uppercase tracking-wider text-zinc-500">
              <th class="px-4 py-3">Nome / URL</th>
              <th class="px-4 py-3">Status</th>
              <th class="px-4 py-3 w-56">Progresso</th>
              <th class="px-4 py-3">OK</th>
              <th class="px-4 py-3">Falhou</th>
              <th class="px-4 py-3"></th>
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
                <div v-if="p.total_count === 0">
                  <span v-if="isStuckImport(p)" class="text-xs text-red-400" :title="p.error_message">
                    {{ p.error_message || 'Importação travada — nenhum canal foi criado ainda.' }}
                  </span>
                  <span v-else class="text-xs text-zinc-500">importando...</span>
                </div>
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
              <td class="px-4 py-3">
                <Link
                  v-if="p.failed_count > 0"
                  :href="route('listas.erros', p.id)"
                  class="text-red-400 underline decoration-red-400/40 underline-offset-2 hover:text-red-300"
                  title="Ver log de erros"
                >{{ p.failed_count }}</Link>
                <span v-else class="text-zinc-500">0</span>
              </td>
              <td class="px-4 py-3 text-right">
                <button
                  v-if="isStuckImport(p)"
                  class="rounded-md bg-violet-600 px-3 py-1 text-xs font-medium text-white transition hover:bg-violet-500 disabled:opacity-50"
                  :disabled="p._busy"
                  title="A importação não chegou a criar nenhum canal — reenfileira do zero."
                  @click="reimportPlaylist(p)"
                >Reiniciar importação</button>
                <button
                  v-else-if="needsValidation(p)"
                  class="rounded-md bg-violet-600 px-3 py-1 text-xs font-medium text-white transition hover:bg-violet-500 disabled:opacity-50"
                  :disabled="p._busy"
                  @click="startValidation(p)"
                >{{ validationLabel(p) }}</button>
                <button
                  v-else-if="isValidating(p)"
                  class="rounded-md border border-zinc-700 px-3 py-1 text-xs font-medium text-zinc-400 transition hover:border-red-500 hover:text-red-300 disabled:opacity-50"
                  :disabled="p._busy"
                  @click="cancelValidation(p)"
                  title="Se a lista ficou travada em 'processing' sem avançar, isso libera para tentar de novo."
                >Cancelar verificação</button>
              </td>
              <td class="px-4 py-3 text-right">
                <button
                  class="rounded-md p-1.5 text-zinc-500 transition hover:bg-red-500/10 hover:text-red-400 disabled:opacity-50"
                  :disabled="p._busy"
                  title="Remover lista"
                  @click="deletePlaylist(p)"
                >
                  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4">
                    <path fill-rule="evenodd" d="M8.75 1A2.75 2.75 0 0 0 6 3.75v.443c-.795.077-1.584.176-2.365.298a.75.75 0 1 0 .23 1.482l.149-.022.841 10.518A2.75 2.75 0 0 0 7.596 19h4.807a2.75 2.75 0 0 0 2.742-2.53l.841-10.52.149.023a.75.75 0 0 0 .23-1.482A41.03 41.03 0 0 0 14 4.193V3.75A2.75 2.75 0 0 0 11.25 1h-2.5ZM10 4c.84 0 1.673.025 2.5.075V3.75c0-.69-.56-1.25-1.25-1.25h-2.5c-.69 0-1.25.56-1.25 1.25v.325C8.327 4.025 9.16 4 10 4ZM8.58 7.72a.75.75 0 0 0-1.5.06l.3 7.5a.75.75 0 1 0 1.5-.06l-.3-7.5Zm4.34.06a.75.75 0 1 0-1.5-.06l-.3 7.5a.75.75 0 1 0 1.5.06l.3-7.5Z" clip-rule="evenodd" />
                  </svg>
                </button>
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
