<script setup>
import { onMounted, onUnmounted, ref, watch } from 'vue'
import axios from 'axios'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'

const props = defineProps({
  countries: Array,
  modes: Array,
  contentTypes: Array,
  genres: Array,
  languages: Array,
  subtitles: Array,
})

const channels = ref([])
const meta = ref(null)
const loading = ref(true)
const q = ref('')
const page = ref(1)
const sort = ref('name')
const dir = ref('asc')
const missingOnly = ref(false)

const columns = [
  { key: 'name', label: 'Nome' },
  { key: 'status', label: 'Status' },
  { key: 'country', label: 'País' },
  { key: 'mode', label: 'Modo' },
  { key: 'content_type', label: 'Tipo' },
  { key: 'genre', label: 'Gênero' },
  { key: 'language', label: 'Idioma' },
  { key: 'subtitle', label: 'Legenda' },
]

async function load() {
  loading.value = true
  try {
    const { data } = await axios.get('/api/v1/channels/admin', {
      params: {
        q: q.value || undefined,
        page: page.value,
        sort: sort.value,
        dir: dir.value,
        missing_metadata: missingOnly.value ? 1 : undefined,
      },
    })
    channels.value = data.data.map((c) => ({ ...c, _genreIds: (c.genres ?? []).map((g) => g.id) }))
    meta.value = { current_page: data.current_page, last_page: data.last_page, total: data.total }
  } finally {
    loading.value = false
  }
}

function toggleSort(key) {
  if (sort.value === key) {
    dir.value = dir.value === 'asc' ? 'desc' : 'asc'
  } else {
    sort.value = key
    dir.value = 'asc'
  }
  page.value = 1
  load()
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

watch(missingOnly, () => {
  page.value = 1
  load()
})

async function saveField(channel, field, value) {
  channel[field] = value ? Number(value) : null
  await axios.patch(`/api/v1/channels/${channel.id}`, { [field]: value || null })
}

// Gênero agora é multiselect: um canal pode ter vários. O menu abre por
// canal (openGenreMenu guarda o id do canal aberto no momento) e cada clique
// num checkbox já salva na hora — sem botão de "aplicar" separado.
const openGenreMenu = ref(null)

function toggleGenreMenu(channelId) {
  openGenreMenu.value = openGenreMenu.value === channelId ? null : channelId
}

function genreLabel(channel) {
  if (!channel._genreIds.length) return '—'
  const names = props.genres.filter((g) => channel._genreIds.includes(g.id)).map((g) => g.name)
  return names.join(', ')
}

async function toggleGenre(channel, genreId) {
  const idx = channel._genreIds.indexOf(genreId)
  if (idx === -1) {
    channel._genreIds.push(genreId)
  } else {
    channel._genreIds.splice(idx, 1)
  }
  await axios.patch(`/api/v1/channels/${channel.id}`, { genre_ids: channel._genreIds })
}

function onDocumentClick(event) {
  if (openGenreMenu.value !== null && !event.target.closest('[data-genre-menu]')) {
    openGenreMenu.value = null
  }
}

onMounted(() => document.addEventListener('click', onDocumentClick))
onUnmounted(() => document.removeEventListener('click', onDocumentClick))

// Descrição: texto livre, pode ser longo — em vez de mais uma coluna estreita
// na tabela, abre um modal simples com textarea. Preenchida a mão aqui, ou
// pelo botão "Preencher lacunas" abaixo (busca em APIs externas).
const descModal = ref(null) // { channel, text } | null

function openDescModal(channel) {
  descModal.value = { channel, text: channel.description || '' }
}

function closeDescModal() {
  descModal.value = null
}

async function saveDescription() {
  const { channel, text } = descModal.value
  const value = text.trim() || null
  await axios.patch(`/api/v1/channels/${channel.id}`, { description: value })
  channel.description = value
  closeDescModal()
}

function descriptionPreview(channel) {
  if (!channel.description) return 'Adicionar descrição'
  return channel.description.length > 60 ? channel.description.slice(0, 60) + '…' : channel.description
}

// "Preencher lacunas": dispara o job em lote que busca tipo/gênero/descrição
// em fontes externas (TMDb/OMDb/TVmaze/iptv-org). Roda numa fila própria
// ('enrichment', separada de 'validation') pra um catálogo grande nunca mais
// travar a verificação de listas — foi isso que causou uma trava real de
// horas antes desse ajuste.
//
// Dois modos:
// - parcial: só os canais com tipo, gênero ou descrição vazios (o normal).
// - total: todos os canais, mesmo os já preenchidos — útil pra tentar de
//   novo depois de configurar uma chave de API nova ou corrigir o
//   certificado SSL. Nunca sobrescreve um campo que já tem valor.
const fillingGaps = ref(false)
const fillGapsMessage = ref('')
const cancellingFillGaps = ref(false)
const enrichmentProgress = ref(null)
let enrichmentTimer = null

async function fillGaps(mode) {
  const confirmText = mode === 'total'
    ? 'Isso enfileira a busca de tipo/gênero/descrição (em fontes externas) para TODOS os canais, mesmo os já preenchidos (sem sobrescrever o que já existe). Pode demorar bem mais que o modo parcial. Continuar?'
    : 'Isso enfileira a busca de tipo/gênero/descrição (em fontes externas) para os canais com essas informações vazias. Pode levar um tempo dependendo do limite das APIs. Continuar?'
  if (!confirm(confirmText)) return
  fillingGaps.value = true
  fillGapsMessage.value = ''
  try {
    const { data } = await axios.post('/api/v1/channels/fill-gaps', { mode })
    fillGapsMessage.value = `${data.queued} canal(is) enfileirado(s) para enriquecimento (${mode === 'total' ? 'total' : 'parcial'}).`
    await loadEnrichmentProgress()
    startEnrichmentPolling()
  } catch (e) {
    alert(e.response?.data?.message ?? 'Falha ao iniciar preenchimento de lacunas.')
  } finally {
    fillingGaps.value = false
  }
}

// Cancela um lote de enriquecimento em andamento — útil se as chaves de API
// (TMDB_API_KEY/OMDB_API_KEY) ainda não foram configuradas, caso em que os
// jobs só vão falhar mesmo sem produzir nada, ou se foi engano num catálogo
// grande.
async function cancelFillGaps() {
  if (!confirm('Cancelar o preenchimento de lacunas? Isso remove da fila os jobs de enriquecimento ainda não processados.')) return
  cancellingFillGaps.value = true
  try {
    const { data } = await axios.post('/api/v1/channels/cancel-fill-gaps')
    fillGapsMessage.value = `${data.jobs_cleared} job(s) de enriquecimento cancelado(s).`
    await loadEnrichmentProgress()
  } catch (e) {
    alert(e.response?.data?.message ?? 'Falha ao cancelar preenchimento de lacunas.')
  } finally {
    cancellingFillGaps.value = false
  }
}

// Barra de progresso: "processado" é sempre o total do lote menos o que
// ainda está na fila 'enrichment' agora — nunca um contador que soma evento
// por evento (mesmo motivo do refreshCounters() das listas: sobrevive a
// job que roda de novo ou worker que reinicia no meio).
async function loadEnrichmentProgress() {
  try {
    const { data } = await axios.get('/api/v1/channels/enrichment-progress')
    enrichmentProgress.value = data
    if (!data.running && enrichmentTimer) {
      clearInterval(enrichmentTimer)
      enrichmentTimer = null
    }
  } catch (e) {
    // silencioso: o polling não pode quebrar a tela principal
  }
}

function startEnrichmentPolling() {
  if (enrichmentTimer) return
  enrichmentTimer = setInterval(loadEnrichmentProgress, 3000)
}

async function recheck(channel) {
  channel._rechecking = true
  await axios.post(`/api/v1/channels/${channel.id}/recheck`)
  channel._rechecking = false
}

const selectClass = 'w-full rounded-md border-zinc-700 bg-zinc-800 text-xs text-zinc-100 focus:border-violet-500 focus:ring-violet-500'

load()

// Se a tela for aberta com um lote de enriquecimento já em andamento (ex.:
// disparado antes, ou a página recarregada no meio), retoma o polling da
// barra de progresso sozinho, sem precisar clicar em nada de novo.
onMounted(async () => {
  await loadEnrichmentProgress()
  if (enrichmentProgress.value?.running) {
    startEnrichmentPolling()
  }
})

onUnmounted(() => {
  if (enrichmentTimer) clearInterval(enrichmentTimer)
})
</script>

<template>
  <AuthenticatedLayout title="Itens carregados">
    <div class="mx-auto max-w-6xl">
      <div class="mb-4 flex flex-col gap-3">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
          <p class="text-xs text-zinc-500">{{ meta ? `${meta.total} itens carregados` : '' }}</p>
          <input
            v-model="q"
            type="text"
            placeholder="Buscar por nome..."
            class="w-full rounded-md border border-zinc-700 bg-zinc-900 px-3 py-1.5 text-sm text-zinc-200 placeholder:text-zinc-600 focus:border-violet-500 focus:outline-none sm:w-64"
          />
        </div>
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
          <label class="flex items-center gap-2 text-xs text-zinc-400">
            <input
              v-model="missingOnly"
              type="checkbox"
              class="rounded border-zinc-600 bg-zinc-900 text-violet-500 focus:ring-violet-500"
            />
            Mostrar só quem tem tipo, gênero ou descrição vazios
          </label>
          <div class="flex flex-wrap items-center gap-2">
            <p v-if="fillGapsMessage" class="text-xs text-emerald-400">{{ fillGapsMessage }}</p>
            <button
              class="rounded-md border border-zinc-700 px-3 py-1.5 text-xs font-medium text-zinc-400 transition hover:border-red-500 hover:text-red-300 disabled:opacity-50"
              :disabled="cancellingFillGaps"
              title="Remove da fila os jobs de enriquecimento ainda não processados."
              @click="cancelFillGaps"
            >{{ cancellingFillGaps ? 'Cancelando...' : 'Cancelar enriquecimento' }}</button>
            <button
              class="rounded-md border border-zinc-700 px-3 py-1.5 text-xs font-medium text-zinc-300 transition hover:border-violet-500 hover:text-violet-300 disabled:opacity-50"
              :disabled="fillingGaps"
              title="Busca tipo, gênero e descrição em fontes externas para os canais que ainda não tem."
              @click="fillGaps('partial')"
            >{{ fillingGaps ? 'Enfileirando...' : 'Preencher lacunas (parcial)' }}</button>
            <button
              class="rounded-md border border-zinc-700 px-3 py-1.5 text-xs font-medium text-zinc-300 transition hover:border-amber-500 hover:text-amber-300 disabled:opacity-50"
              :disabled="fillingGaps"
              title="Tenta de novo em TODOS os canais, mesmo os já preenchidos — sem sobrescrever o que já existe. Útil depois de configurar uma chave de API nova."
              @click="fillGaps('total')"
            >{{ fillingGaps ? 'Enfileirando...' : 'Preencher lacunas (total)' }}</button>
          </div>
        </div>

        <div v-if="enrichmentProgress?.total" class="rounded-lg border border-zinc-800 bg-zinc-900 px-4 py-3">
          <div class="mb-1 flex items-center justify-between text-xs text-zinc-400">
            <span>
              Enriquecimento ({{ enrichmentProgress.mode === 'total' ? 'total' : 'parcial' }}):
              {{ enrichmentProgress.processed }}/{{ enrichmentProgress.total }} ({{ enrichmentProgress.percent }}%)
            </span>
            <span v-if="!enrichmentProgress.running" class="font-medium text-emerald-400">concluído</span>
          </div>
          <div class="h-2 w-full overflow-hidden rounded-full bg-zinc-800">
            <div
              class="h-full rounded-full transition-all"
              :class="enrichmentProgress.running ? 'bg-gradient-to-r from-amber-500 to-violet-500' : 'bg-emerald-500'"
              :style="{ width: enrichmentProgress.percent + '%' }"
            />
          </div>
        </div>
      </div>

      <!-- Desktop: tabela -->
      <div class="hidden overflow-x-auto rounded-xl border border-zinc-800 bg-zinc-900 md:block">
        <table class="w-full min-w-[980px] text-xs">
          <thead>
            <tr class="border-b border-zinc-800 text-left uppercase tracking-wider text-zinc-500">
              <th
                v-for="col in columns"
                :key="col.key"
                class="cursor-pointer select-none px-3 py-3 hover:text-zinc-300"
                @click="toggleSort(col.key)"
              >
                {{ col.label }}
                <span v-if="sort === col.key" class="ml-1 text-violet-400">{{ dir === 'asc' ? '▲' : '▼' }}</span>
              </th>
              <th class="px-3 py-3">Descrição</th>
              <th class="px-3 py-3"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="c in channels" :key="c.id" class="border-b border-zinc-800/60 last:border-0 hover:bg-zinc-800/40">
              <td class="px-3 py-2 text-zinc-200">{{ c.name }}</td>
              <td class="px-3 py-2 text-zinc-400">{{ c.status }}</td>
              <td class="px-3 py-2">
                <select :class="selectClass" :value="c.country_id" @change="saveField(c, 'country_id', $event.target.value)">
                  <option value="">—</option>
                  <option v-for="o in countries" :key="o.id" :value="o.id">{{ o.name }}</option>
                </select>
              </td>
              <td class="px-3 py-2">
                <select :class="selectClass" :value="c.mode_id" @change="saveField(c, 'mode_id', $event.target.value)">
                  <option value="">—</option>
                  <option v-for="o in modes" :key="o.id" :value="o.id">{{ o.name }}</option>
                </select>
              </td>
              <td class="px-3 py-2">
                <select :class="selectClass" :value="c.content_type_id" @change="saveField(c, 'content_type_id', $event.target.value)">
                  <option value="">—</option>
                  <option v-for="o in contentTypes" :key="o.id" :value="o.id">{{ o.name }}</option>
                </select>
              </td>
              <td class="relative px-3 py-2">
                <div data-genre-menu>
                  <button
                    type="button"
                    :class="selectClass"
                    class="truncate text-left"
                    @click.stop="toggleGenreMenu(c.id)"
                  >
                    {{ genreLabel(c) }}
                  </button>
                  <div
                    v-if="openGenreMenu === c.id"
                    class="absolute left-3 top-full z-10 mt-1 max-h-56 w-48 overflow-y-auto rounded-md border border-zinc-700 bg-zinc-800 p-2 shadow-xl"
                    @click.stop
                  >
                    <label v-for="g in genres" :key="g.id" class="flex items-center gap-2 rounded px-1.5 py-1 text-zinc-200 hover:bg-zinc-700/60">
                      <input
                        type="checkbox"
                        class="rounded border-zinc-600 bg-zinc-900 text-violet-500 focus:ring-violet-500"
                        :checked="c._genreIds.includes(g.id)"
                        @change="toggleGenre(c, g.id)"
                      />
                      {{ g.name }}
                    </label>
                    <p v-if="genres.length === 0" class="px-1.5 py-1 text-zinc-500">Nenhum gênero cadastrado.</p>
                  </div>
                </div>
              </td>
              <td class="px-3 py-2">
                <select :class="selectClass" :value="c.language_id" @change="saveField(c, 'language_id', $event.target.value)">
                  <option value="">—</option>
                  <option v-for="o in languages" :key="o.id" :value="o.id">{{ o.name }}</option>
                </select>
              </td>
              <td class="px-3 py-2">
                <select :class="selectClass" :value="c.subtitle_id" @change="saveField(c, 'subtitle_id', $event.target.value)">
                  <option value="">—</option>
                  <option v-for="o in subtitles" :key="o.id" :value="o.id">{{ o.name }}</option>
                </select>
              </td>
              <td class="max-w-[14rem] px-3 py-2">
                <button
                  type="button"
                  class="w-full truncate rounded-md border border-zinc-700 bg-zinc-800 px-2 py-1.5 text-left text-zinc-300 hover:border-violet-500 hover:text-violet-300"
                  :class="{ 'italic text-zinc-500': !c.description }"
                  @click="openDescModal(c)"
                >{{ descriptionPreview(c) }}</button>
              </td>
              <td class="px-3 py-2 text-right">
                <button class="rounded-md border border-zinc-700 px-2 py-1 text-xs text-zinc-200 hover:border-violet-500 hover:text-violet-300" :disabled="c._rechecking" @click="recheck(c)">
                  Reprocessar
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Mobile: cards -->
      <div class="space-y-3 md:hidden">
        <div v-for="c in channels" :key="c.id" class="rounded-xl border border-zinc-800 bg-zinc-900 p-4">
          <div class="flex items-center justify-between">
            <p class="font-medium text-white">{{ c.name }}</p>
            <span class="text-xs text-zinc-500">{{ c.status }}</span>
          </div>
          <div class="mt-3 grid grid-cols-2 gap-2">
            <select :class="selectClass" :value="c.country_id" @change="saveField(c, 'country_id', $event.target.value)">
              <option value="">País</option>
              <option v-for="o in countries" :key="o.id" :value="o.id">{{ o.name }}</option>
            </select>
            <select :class="selectClass" :value="c.mode_id" @change="saveField(c, 'mode_id', $event.target.value)">
              <option value="">Modo</option>
              <option v-for="o in modes" :key="o.id" :value="o.id">{{ o.name }}</option>
            </select>
            <select :class="selectClass" :value="c.content_type_id" @change="saveField(c, 'content_type_id', $event.target.value)">
              <option value="">Tipo</option>
              <option v-for="o in contentTypes" :key="o.id" :value="o.id">{{ o.name }}</option>
            </select>
            <div class="relative" data-genre-menu>
              <button type="button" :class="selectClass" class="truncate text-left" @click.stop="toggleGenreMenu(c.id)">
                {{ c._genreIds.length ? genreLabel(c) : 'Gênero' }}
              </button>
              <div
                v-if="openGenreMenu === c.id"
                class="absolute left-0 top-full z-10 mt-1 max-h-56 w-full overflow-y-auto rounded-md border border-zinc-700 bg-zinc-800 p-2 shadow-xl"
                @click.stop
              >
                <label v-for="g in genres" :key="g.id" class="flex items-center gap-2 rounded px-1.5 py-1 text-zinc-200 hover:bg-zinc-700/60">
                  <input
                    type="checkbox"
                    class="rounded border-zinc-600 bg-zinc-900 text-violet-500 focus:ring-violet-500"
                    :checked="c._genreIds.includes(g.id)"
                    @change="toggleGenre(c, g.id)"
                  />
                  {{ g.name }}
                </label>
              </div>
            </div>
            <select :class="selectClass" :value="c.language_id" @change="saveField(c, 'language_id', $event.target.value)">
              <option value="">Idioma</option>
              <option v-for="o in languages" :key="o.id" :value="o.id">{{ o.name }}</option>
            </select>
            <select :class="selectClass" :value="c.subtitle_id" @change="saveField(c, 'subtitle_id', $event.target.value)">
              <option value="">Legenda</option>
              <option v-for="o in subtitles" :key="o.id" :value="o.id">{{ o.name }}</option>
            </select>
          </div>
          <button
            type="button"
            class="mt-2 w-full truncate rounded-md border border-zinc-700 bg-zinc-800 px-2 py-1.5 text-left text-xs text-zinc-300"
            :class="{ 'italic text-zinc-500': !c.description }"
            @click="openDescModal(c)"
          >{{ descriptionPreview(c) }}</button>
          <button class="mt-3 w-full rounded-md border border-zinc-700 py-1.5 text-xs text-zinc-200" :disabled="c._rechecking" @click="recheck(c)">
            Reprocessar
          </button>
        </div>
      </div>

      <p v-if="!loading && channels.length === 0" class="mt-6 text-center text-zinc-500">
        Nenhum item carregado{{ q ? ' para essa busca' : ' ainda' }}.
      </p>

      <div v-if="meta && meta.last_page > 1" class="mt-4 flex items-center justify-center gap-3 text-sm text-zinc-400">
        <button class="rounded-md border border-zinc-700 px-2 py-1 disabled:opacity-40" :disabled="meta.current_page <= 1" @click="goToPage(meta.current_page - 1)">Anterior</button>
        <span>Página {{ meta.current_page }} de {{ meta.last_page }} ({{ meta.total }} itens)</span>
        <button class="rounded-md border border-zinc-700 px-2 py-1 disabled:opacity-40" :disabled="meta.current_page >= meta.last_page" @click="goToPage(meta.current_page + 1)">Próxima</button>
      </div>
    </div>

    <!-- Modal de descrição -->
    <div
      v-if="descModal"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
      @click.self="closeDescModal"
    >
      <div class="w-full max-w-lg rounded-xl border border-zinc-700 bg-zinc-900 p-5 shadow-2xl">
        <h3 class="mb-1 text-sm font-medium text-zinc-100">Descrição</h3>
        <p class="mb-3 truncate text-xs text-zinc-500">{{ descModal.channel.name }}</p>
        <textarea
          v-model="descModal.text"
          rows="6"
          placeholder="Sinopse ou descrição do canal/filme/série..."
          class="w-full rounded-md border border-zinc-700 bg-zinc-800 p-2 text-sm text-zinc-100 placeholder:text-zinc-600 focus:border-violet-500 focus:outline-none"
        ></textarea>
        <div class="mt-4 flex justify-end gap-2">
          <button class="rounded-md border border-zinc-700 px-3 py-1.5 text-xs text-zinc-300 hover:border-zinc-500" @click="closeDescModal">Cancelar</button>
          <button class="rounded-md bg-violet-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-violet-500" @click="saveDescription">Salvar</button>
        </div>
      </div>
    </div>
  </AuthenticatedLayout>
</template>
