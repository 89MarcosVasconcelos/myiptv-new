<script setup>
import { onMounted, onUnmounted, ref } from 'vue'
import axios from 'axios'
import { Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'

const playlists = ref([])
const loading = ref(true)
const resettingQueue = ref(false)
const resetMessage = ref('')
const queueHealth = ref(null)
let pollTimer = null
let healthTimer = null

async function load() {
  const { data } = await axios.get('/api/v1/playlists')
  playlists.value = data.data
  loading.value = false
}

// Diagnostico proativo: se o worker travar de novo por qualquer motivo nao
// previsto, isso aparece aqui sozinho (job reservado ha muito tempo sem
// terminar) em vez de ficar invisivel ate alguem notar horas depois.
async function checkQueueHealth() {
  try {
    const { data } = await axios.get('/api/v1/queue/health')
    queueHealth.value = data
  } catch (e) {
    // silencioso: isso e so um aviso extra, nao pode quebrar a tela principal
  }
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

// "Continuar verificação" é só pra quando a verificação foi de fato
// PAUSADA no meio (ainda sobra pending_count > 0 depois de já ter ok/falha).
// Quando o lote inteiro já rodou (pending_count chegou a 0, mesmo com
// falhas), o botão vira "Recarregar lista" — a ação é a mesma (reprocessa
// os que falharam), só o nome muda pra não sugerir que tem algo "no meio".
function validationLabel(p) {
  if (p.ok_count === 0 && p.failed_count === 0) return 'Iniciar verificação'
  if (p.pending_count === 0) return 'Recarregar lista'
  return 'Continuar verificação'
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
// IMPORTANTE: isso so limpa as LINHAS da fila no banco. Se o PROCESSO do
// worker (o "php artisan queue:work" rodando como serviço do Windows) ficou
// travado de verdade (preso num job que nunca termina), zerar a fila não
// acorda esse processo — por isso o aviso abaixo, quando detectamos isso,
// sempre vai indicar o "Restart-Service IptvQueueWorker" como o passo real.
async function resetQueue() {
  if (!confirm('Isso limpa a fila de processamento (jobs presos ou duplicados) e destrava listas paradas em "processando". Continuar?')) return
  resettingQueue.value = true
  resetMessage.value = ''
  try {
    const { data } = await axios.post('/api/v1/playlists/reset-queue')
    resetMessage.value = `Fila zerada: ${data.jobs_cleared} job(s) removido(s), ${data.failed_jobs_cleared} falha(s) antiga(s) limpa(s), ${data.playlists_unstuck} lista(s) destravada(s).`
    if (data.worker_was_stuck) {
      resetMessage.value += ' O worker parecia travado — se as listas não voltarem a andar em alguns segundos, rode "Restart-Service IptvQueueWorker" no PowerShell (como administrador).'
    }
    await load()
    await checkQueueHealth()
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

function formatDuration(seconds) {
  if (!seconds) return '0s'
  const m = Math.floor(seconds / 60)
  const s = seconds % 60
  return m > 0 ? `${m}min ${s}s` : `${s}s`
}

onMounted(() => {
  load()
  checkQueueHealth()
  pollTimer = setInterval(() => {
    if (playlists.value.some((p) => isValidating(p) || isImporting(p))) load()
  }, 2500)
  // O check de saude da fila roda sempre, mesmo sem nenhuma lista visivelmente
  // "processando" na tela — e justamente quando o worker trava que os status
  // param de avançar, entao não dá pra depender só do polling condicional acima.
  healthTimer = setInterval(checkQueueHealth, 10_000)
})

onUnmounted(() => {
  clearInterval(pollTimer)
  clearInterval(healthTimer)
})
</script>

<template>
  <AuthenticatedLayout title="Listas carregadas">
    <div class="mx-auto max-w-5xl">
      <div
        v-if="queueHealth?.stuck"
        class="mb-4 rounded-lg border border-red-500/40 bg-red-500/10 px-4 py-3 text-sm text-red-300"
      >
        <p class="font-medium">
          O worker de processamento parece travado há {{ formatDuration(queueHealth.stuck_for_seconds) }}
          ({{ queueHealth.queued_jobs }} job(s) na fila aguardando).
        </p>
        <p class="mt-1 text-xs text-red-300/80">
          "Zerar filas" limpa a fila no banco, mas não acorda um processo travado. No servidor, abra o PowerShell
          como administrador e rode: <code class="rounded bg-black/30 px-1 py-0.5">Restart-Service IptvQueueWorker</code>
        </p>
      </div>

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
