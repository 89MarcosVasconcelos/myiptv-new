<script setup>
import { onMounted, ref } from 'vue'
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
const loading = ref(true)

async function load() {
  loading.value = true
  const { data } = await axios.get('/api/v1/channels')
  channels.value = data.channels.data
  loading.value = false
}

async function saveField(channel, field, value) {
  channel[field] = value ? Number(value) : null
  await axios.patch(`/api/v1/channels/${channel.id}`, { [field]: value || null })
}

async function recheck(channel) {
  channel._rechecking = true
  await axios.post(`/api/v1/channels/${channel.id}/recheck`)
  channel._rechecking = false
}

const selectClass = 'w-full rounded-md border-zinc-700 bg-zinc-800 text-xs text-zinc-100 focus:border-violet-500 focus:ring-violet-500'

onMounted(load)
</script>

<template>
  <AuthenticatedLayout title="Itens carregados">
    <div class="mx-auto max-w-6xl">
      <!-- Desktop: tabela -->
      <div class="hidden overflow-x-auto rounded-xl border border-zinc-800 bg-zinc-900 md:block">
        <table class="w-full min-w-[900px] text-xs">
          <thead>
            <tr class="border-b border-zinc-800 text-left uppercase tracking-wider text-zinc-500">
              <th class="px-3 py-3">Nome</th>
              <th class="px-3 py-3">Status</th>
              <th class="px-3 py-3">País</th>
              <th class="px-3 py-3">Modo</th>
              <th class="px-3 py-3">Tipo</th>
              <th class="px-3 py-3">Gênero</th>
              <th class="px-3 py-3">Idioma</th>
              <th class="px-3 py-3">Legenda</th>
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
              <td class="px-3 py-2">
                <select :class="selectClass" :value="c.genre_id" @change="saveField(c, 'genre_id', $event.target.value)">
                  <option value="">—</option>
                  <option v-for="o in genres" :key="o.id" :value="o.id">{{ o.name }}</option>
                </select>
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
            <select :class="selectClass" :value="c.genre_id" @change="saveField(c, 'genre_id', $event.target.value)">
              <option value="">Gênero</option>
              <option v-for="o in genres" :key="o.id" :value="o.id">{{ o.name }}</option>
            </select>
            <select :class="selectClass" :value="c.language_id" @change="saveField(c, 'language_id', $event.target.value)">
              <option value="">Idioma</option>
              <option v-for="o in languages" :key="o.id" :value="o.id">{{ o.name }}</option>
            </select>
            <select :class="selectClass" :value="c.subtitle_id" @change="saveField(c, 'subtitle_id', $event.target.value)">
              <option value="">Legenda</option>
              <option v-for="o in subtitles" :key="o.id" :value="o.id">{{ o.name }}</option>
            </select>
          </div>
          <button class="mt-3 w-full rounded-md border border-zinc-700 py-1.5 text-xs text-zinc-200" :disabled="c._rechecking" @click="recheck(c)">
            Reprocessar
          </button>
        </div>
      </div>

      <p v-if="!loading && channels.length === 0" class="mt-6 text-center text-zinc-500">
        Nenhum item carregado ainda.
      </p>
    </div>
  </AuthenticatedLayout>
</template>
