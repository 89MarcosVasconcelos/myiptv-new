<script setup>
import { ref, watch } from 'vue'
import axios from 'axios'
import Hls from 'hls.js'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'

const props = defineProps({
  countries: Array,
  modes: Array,
  contentTypes: Array,
  genres: Array,
})

const filters = ref({ country_id: '', mode_id: '', content_type_id: '', genre_id: '', q: '' })
const channels = ref([])
const selected = ref(null)
const videoEl = ref(null)
let hls = null

const selectClass = 'w-full rounded-md border-zinc-700 bg-zinc-800 text-sm text-zinc-200 focus:border-violet-500 focus:ring-violet-500'

async function search() {
  const params = { ...filters.value }
  Object.keys(params).forEach((k) => { if (!params[k]) delete params[k] })

  const { data } = await axios.get('/api/v1/channels', { params })
  channels.value = data.channels.data
}

function selectChannel(channel) {
  selected.value = channel

  if (hls) {
    hls.destroy()
    hls = null
  }

  if (channel.stream_type === 'youtube') return

  const video = videoEl.value
  if (!video) return

  if (channel.stream_type === 'hls' && Hls.isSupported()) {
    hls = new Hls()
    hls.loadSource(channel.url)
    hls.attachMedia(video)
  } else {
    video.src = channel.url
    video.play()
  }
}

function initials(name) {
  return (name || '?').trim().slice(0, 2).toUpperCase()
}

watch(filters, search, { deep: true })
search()
</script>

<template>
  <AuthenticatedLayout title="Exibição">
    <div class="mx-auto max-w-6xl space-y-6">
      <!-- Player -->
      <div class="overflow-hidden rounded-xl border border-zinc-800 bg-black">
        <div class="aspect-video">
          <video v-if="selected && selected.stream_type !== 'youtube'" ref="videoEl" controls autoplay class="h-full w-full" />
          <iframe
            v-else-if="selected && selected.stream_type === 'youtube'"
            :src="selected.url.replace('watch?v=', 'embed/')"
            class="h-full w-full"
            allowfullscreen
          />
          <div v-else class="flex h-full flex-col items-center justify-center gap-2 text-zinc-600">
            <svg class="h-12 w-12" viewBox="0 0 20 20" fill="currentColor"><path d="M6.3 2.84A1.5 1.5 0 004 4.11v11.78a1.5 1.5 0 002.3 1.27l9.34-5.89a1.5 1.5 0 000-2.54L6.3 2.84z" /></svg>
            <p class="text-sm">Selecione um canal abaixo para iniciar</p>
          </div>
        </div>
        <div v-if="selected" class="border-t border-zinc-800 bg-zinc-900 px-4 py-3">
          <p class="font-medium text-white">{{ selected.name }}</p>
          <p class="text-xs text-zinc-500">{{ selected.country?.name }} · {{ selected.mode?.name }} · {{ selected.content_type?.name }}</p>
        </div>
      </div>

      <!-- Busca e filtros encadeados -->
      <div class="space-y-3">
        <input
          v-model="filters.q"
          placeholder="Buscar canal..."
          class="w-full rounded-md border-zinc-700 bg-zinc-900 text-sm text-zinc-100 placeholder-zinc-500 focus:border-violet-500 focus:ring-violet-500"
        />
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
          <select v-model="filters.country_id" :class="selectClass">
            <option value="">País (todos)</option>
            <option v-for="o in countries" :key="o.id" :value="o.id">{{ o.name }}</option>
          </select>
          <select v-model="filters.mode_id" :class="selectClass">
            <option value="">Modo (todos)</option>
            <option v-for="o in modes" :key="o.id" :value="o.id">{{ o.name }}</option>
          </select>
          <select v-model="filters.content_type_id" :class="selectClass">
            <option value="">Tipo (todos)</option>
            <option v-for="o in contentTypes" :key="o.id" :value="o.id">{{ o.name }}</option>
          </select>
          <select v-model="filters.genre_id" :class="selectClass">
            <option value="">Gênero (todos)</option>
            <option v-for="o in genres" :key="o.id" :value="o.id">{{ o.name }}</option>
          </select>
        </div>
      </div>

      <!-- Grade de canais -->
      <div>
        <p class="mb-3 text-sm text-zinc-500">{{ channels.length }} canal(is) encontrados</p>
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6">
          <button
            v-for="c in channels"
            :key="c.id"
            class="group flex flex-col items-center gap-2 rounded-lg border p-3 text-center transition"
            :class="selected?.id === c.id ? 'border-violet-500 bg-violet-600/10' : 'border-zinc-800 bg-zinc-900 hover:border-zinc-600'"
            @click="selectChannel(c)"
          >
            <span class="flex h-12 w-12 items-center justify-center rounded-full bg-gradient-to-br from-violet-500 to-fuchsia-600 text-sm font-semibold text-white">
              {{ initials(c.name) }}
            </span>
            <span class="line-clamp-2 text-xs text-zinc-300 group-hover:text-white">{{ c.name }}</span>
          </button>
        </div>
        <p v-if="channels.length === 0" class="py-10 text-center text-sm text-zinc-500">
          Nenhum canal encontrado com esses filtros.
        </p>
      </div>
    </div>
  </AuthenticatedLayout>
</template>
