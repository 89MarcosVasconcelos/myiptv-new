<script setup>
import { onMounted, onUnmounted, ref } from 'vue'
import axios from 'axios'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'

const data = ref(null)
const loading = ref(true)
const busy = ref(false)
const message = ref('')
let timer = null

async function load() {
  try {
    const res = await axios.get('/api/v1/queue/jobs?limit=100')
    data.value = res.data
  } catch (e) {
    // silencioso: o auto-refresh não pode quebrar a tela
  } finally {
    loading.value = false
  }
}

async function resetQueue() {
  if (!confirm('Isso limpa a fila de verificação (jobs presos ou duplicados) e destrava listas paradas em "processando". Continuar?')) return
  busy.value = true
  message.value = ''
  try {
    const { data: r } = await axios.post('/api/v1/playlists/reset-queue')
    message.value = `Fila zerada: ${r.jobs_cleared} job(s) removido(s), ${r.playlists_unstuck} lista(s) destravada(s).`
    await load()
  } catch (e) {
    alert(e.response?.data?.message ?? 'Falha ao zerar a fila.')
  } finally {
    busy.value = false
  }
}

async function cancelEnrichment() {
  if (!confirm('Cancelar o preenchimento de lacunas? Isso remove da fila os jobs de enriquecimento ainda não processados.')) return
  busy.value = true
  message.value = ''
  try {
    const { data: r } = await axios.post('/api/v1/channels/cancel-fill-gaps')
    message.value = `${r.jobs_cleared} job(s) de enriquecimento cancelado(s).`
    await load()
  } catch (e) {
    alert(e.response?.data?.message ?? 'Falha ao cancelar enriquecimento.')
  } finally {
    busy.value = false
  }
}

function formatDuration(seconds) {
  if (seconds === null || seconds === undefined) return '-'
  if (seconds < 60) return `${seconds}s`
  const m = Math.floor(seconds / 60)
  const s = seconds % 60
  if (m < 60) return `${m}min ${s}s`
  const h = Math.floor(m / 60)
  return `${h}h ${m % 60}min`
}

// Cada fila tem um papel diferente, e a ordem de prioridade no worker é
// imports > validation > enrichment — justamente pra importar uma lista
// (ação interativa, o usuário está olhando a tela) nunca ficar esperando
// atrás de milhares de jobs de verificação/enriquecimento em lote.
function queueLabel(queue) {
  return {
    imports: 'Importação (prioridade alta)',
    validation: 'Verificação de canais',
    enrichment: 'Enriquecimento (prioridade baixa)',
  }[queue] ?? queue
}

function queueBadge(queue) {
  return {
    imports: 'bg-violet-500/15 text-violet-300',
    validation: 'bg-sky-500/15 text-sky-300',
    enrichment: 'bg-zinc-600/30 text-zinc-400',
  }[queue] ?? 'bg-zinc-700 text-zinc-300'
}

onMounted(() => {
  load()
  timer = setInterval(load, 3000)
})

onUnmounted(() => clearInterval(timer))
</script>

<template>
  <AuthenticatedLayout title="Fila de processamento">
    <div class="mx-auto max-w-5xl">
      <div
        v-if="data?.health?.stuck"
        class="mb-4 rounded-lg border border-red-500/40 bg-red-500/10 px-4 py-3 text-sm text-red-300"
      >
        <p class="font-medium">
          O worker parece travado há {{ formatDuration(data.health.stuck_for_seconds) }} no job que está em execução.
        </p>
        <p class="mt-1 text-xs text-red-300/80">
          Zerar a fila limpa o que está na fila, mas não acorda um processo travado. No servidor, abra o PowerShell como
          administrador e rode:
          <code class="rounded bg-black/30 px-1 py-0.5">Restart-Service IptvQueueWorker</code>
        </p>
      </div>

      <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
        <div class="rounded-xl border border-zinc-800 bg-zinc-900 px-4 py-3">
          <p class="text-xs uppercase tracking-wider text-zinc-500">Na fila</p>
          <p class="mt-1 text-2xl font-semibold text-zinc-100">{{ data?.total_pending ?? '-' }}</p>
        </div>
        <div class="rounded-xl border border-zinc-800 bg-zinc-900 px-4 py-3">
          <p class="text-xs uppercase tracking-wider text-zinc-500">Importação</p>
          <p class="mt-1 text-2xl font-semibold text-violet-300">{{ data?.health?.import_queued_jobs ?? '-' }}</p>
        </div>
        <div class="rounded-xl border border-zinc-800 bg-zinc-900 px-4 py-3">
          <p class="text-xs uppercase tracking-wider text-zinc-500">Verificação</p>
          <p class="mt-1 text-2xl font-semibold text-sky-300">{{ data?.health?.queued_jobs ?? '-' }}</p>
        </div>
        <div class="rounded-xl border border-zinc-800 bg-zinc-900 px-4 py-3">
          <p class="text-xs uppercase tracking-wider text-zinc-500">Falhas</p>
          <p class="mt-1 text-2xl font-semibold" :class="data?.total_failed ? 'text-red-400' : 'text-zinc-100'">
            {{ data?.total_failed ?? '-' }}
          </p>
        </div>
      </div>

      <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <p v-if="message" class="text-xs text-emerald-400">{{ message }}</p>
        <p v-else class="text-xs text-zinc-500">Atualiza sozinho a cada 3 segundos.</p>
        <div class="flex gap-2">
          <button
            class="rounded-md border border-zinc-700 px-3 py-1.5 text-xs font-medium text-zinc-400 transition hover:border-amber-500 hover:text-amber-300 disabled:opacity-50"
            :disabled="busy"
            title="Remove da fila os jobs de enriquecimento (Preencher lacunas) ainda não processados."
            @click="cancelEnrichment"
          >Cancelar enriquecimento</button>
          <button
            class="rounded-md border border-zinc-700 px-3 py-1.5 text-xs font-medium text-zinc-300 transition hover:border-red-500 hover:text-red-300 disabled:opacity-50"
            :disabled="busy"
            title="Limpa a fila de verificação e destrava listas paradas em 'processando'."
            @click="resetQueue"
          >Zerar filas</button>
        </div>
      </div>

      <div v-if="data?.by_type && Object.keys(data.by_type).length" class="mb-4 flex flex-wrap gap-2">
        <span
          v-for="(count, type) in data.by_type"
          :key="type"
          class="rounded-full bg-zinc-800 px-3 py-1 text-xs text-zinc-300"
        >{{ type }}: <strong class="text-zinc-100">{{ count }}</strong></span>
      </div>

      <div class="overflow-x-auto rounded-xl border border-zinc-800 bg-zinc-900">
        <table class="w-full min-w-[720px] text-sm">
          <thead>
            <tr class="border-b border-zinc-800 text-left text-xs uppercase tracking-wider text-zinc-500">
              <th class="px-4 py-3">#</th>
              <th class="px-4 py-3">Job</th>
              <th class="px-4 py-3">Fila</th>
              <th class="px-4 py-3">Situação</th>
              <th class="px-4 py-3">Esperando há</th>
              <th class="px-4 py-3">Tentativas</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="job in data?.jobs ?? []"
              :key="job.id"
              class="border-b border-zinc-800/60 last:border-0"
              :class="job.running ? 'bg-emerald-500/5' : 'hover:bg-zinc-800/40'"
            >
              <td class="px-4 py-3 text-zinc-500">{{ job.id }}</td>
              <td class="px-4 py-3 text-zinc-200">{{ job.job }}</td>
              <td class="px-4 py-3">
                <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="queueBadge(job.queue)">
                  {{ queueLabel(job.queue) }}
                </span>
              </td>
              <td class="px-4 py-3">
                <span v-if="job.running" class="text-emerald-400">
                  processando ({{ formatDuration(job.running_for_seconds) }})
                </span>
                <span v-else class="text-zinc-500">aguardando a vez</span>
              </td>
              <td class="px-4 py-3 text-zinc-400">{{ formatDuration(job.waiting_for_seconds) }}</td>
              <td class="px-4 py-3 text-zinc-400">{{ job.attempts }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <p v-if="!loading && !(data?.jobs ?? []).length" class="mt-6 text-center text-zinc-500">
        Nada na fila — tudo processado.
      </p>

      <div v-if="(data?.failed ?? []).length" class="mt-8">
        <h2 class="mb-3 text-sm font-medium text-zinc-300">Últimas falhas</h2>
        <div class="overflow-x-auto rounded-xl border border-zinc-800 bg-zinc-900">
          <table class="w-full min-w-[720px] text-sm">
            <thead>
              <tr class="border-b border-zinc-800 text-left text-xs uppercase tracking-wider text-zinc-500">
                <th class="px-4 py-3">Job</th>
                <th class="px-4 py-3">Quando</th>
                <th class="px-4 py-3">Erro</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="f in data.failed" :key="f.id" class="border-b border-zinc-800/60 last:border-0">
                <td class="px-4 py-3 text-zinc-200">{{ f.job }}</td>
                <td class="whitespace-nowrap px-4 py-3 text-zinc-500">{{ f.failed_at }}</td>
                <td class="px-4 py-3 text-xs text-red-400">{{ f.error }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </AuthenticatedLayout>
</template>
