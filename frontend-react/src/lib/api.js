// a API e o front vivem no mesmo domínio (raiz): /admin/*, /p/<token>/*
const BASE = "/";

let _token = null;
export function setToken(t) {
  _token = t;
}
export function getToken() {
  return _token;
}

// senha do conteúdo adulto: fica SÓ na memória da aba (recarregar a página apaga,
// então ela é pedida de novo toda vez). O servidor confere em cada requisição.
let _adultPin = null;
export function setAdultPin(p) {
  _adultPin = p || null;
}
export function hasAdultPin() {
  return !!_adultPin;
}

// querystring que aceita arrays (parâmetro repetido) e ignora vazios
function qs(params = {}) {
  const u = new URLSearchParams();
  for (const [k, v] of Object.entries(params)) {
    if (Array.isArray(v)) v.forEach((x) => u.append(k, x));
    else if (v !== undefined && v !== null && v !== "") u.append(k, v);
  }
  return u;
}

async function req(path, opts = {}) {
  const headers = { ...(opts.headers || {}) };
  if (_adultPin) headers["X-Adult-Pin"] = _adultPin;
  const resp = await fetch(BASE + path, { credentials: "include", ...opts, headers });
  if (!resp.ok) {
    const body = await resp.json().catch(() => ({}));
    const err = new Error(body.detail || `HTTP ${resp.status}`);
    err.status = resp.status;
    throw err;
  }
  return resp.json();
}

// --- público (precisa de token no path) ---
export const api = {
  adultStatus: () => req(`p/${encodeURIComponent(_token)}/adult/status`),
  adultUnlock: (pin) =>
    req(`p/${encodeURIComponent(_token)}/adult/unlock`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ pin }),
    }),
  // params aceita arrays (ex.: categories: [...], tvg_ids: [...]) -> parâmetro repetido
  channels: (params = {}) =>
    req(`p/${encodeURIComponent(_token)}/channels.json?${qs(params)}`),
  // lista leve dos canais de 1+ segmentos (2º menu da TV ao vivo)
  channelNames: (params = {}) =>
    req(`p/${encodeURIComponent(_token)}/channels/names?${qs(params)}`),
  channelCategories: () => req(`p/${encodeURIComponent(_token)}/channels/categories`),
  channelLanguages: () => req(`p/${encodeURIComponent(_token)}/channels/languages`),
  channelDetail: (tvgId) => req(`p/${encodeURIComponent(_token)}/channels/${encodeURIComponent(tvgId)}`),
  channelsHome: (perCategory = 20) =>
    req(`p/${encodeURIComponent(_token)}/channels/home?per_category=${perCategory}`),
  vod: (params = {}) =>
    req(`p/${encodeURIComponent(_token)}/vod?${new URLSearchParams(params)}`),
  vodHome: (perGenre = 15) =>
    req(`p/${encodeURIComponent(_token)}/vod/home?per_genre=${perGenre}`),
  vodGenres: (type) =>
    req(`p/${encodeURIComponent(_token)}/vod/genres${type ? `?type=${type}` : ""}`),
  vodLanguages: (type) =>
    req(`p/${encodeURIComponent(_token)}/vod/languages${type ? `?type=${type}` : ""}`),
  vodDetail: (id) => req(`p/${encodeURIComponent(_token)}/vod/${id}`),
  resolve: (tvgId, lang) =>
    req(
      `p/${encodeURIComponent(_token)}/channels/${encodeURIComponent(tvgId)}/resolve` +
        (lang ? `?lang=${encodeURIComponent(lang)}` : "")
    ),
  epg: (tvgId) =>
    req(`p/${encodeURIComponent(_token)}/channels/${encodeURIComponent(tvgId)}/epg`),

  // "Continuar assistindo"
  continueWatching: () => req(`p/${encodeURIComponent(_token)}/continue-watching`),
  saveProgress: (body) =>
    fetch(BASE + `p/${encodeURIComponent(_token)}/progress`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      keepalive: true, // sobrevive ao fechar a aba
      body: JSON.stringify(body),
    }).catch(() => {}),
  removeProgress: (titleId) =>
    fetch(BASE + `p/${encodeURIComponent(_token)}/progress/${titleId}`, {
      method: "DELETE",
    }).catch(() => {}),

  reportFailure: (tvgId, url) =>
    fetch(
      BASE + `p/${encodeURIComponent(_token)}/channels/${encodeURIComponent(tvgId)}/report-failure`,
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ url }),
      }
    ).catch(() => {}),

  vodResolve: (itemId) => req(`p/${encodeURIComponent(_token)}/vod/items/${itemId}/resolve`),
  vodReportFailure: (itemId, url) =>
    fetch(
      BASE + `p/${encodeURIComponent(_token)}/vod/items/${itemId}/report-failure`,
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ url }),
      }
    ).catch(() => {}),
};

// --- admin (sessão por cookie) ---
const jpost = (path, body) =>
  req(path, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(body || {}) });
const jpatch = (path, body) =>
  req(path, { method: "PATCH", headers: { "Content-Type": "application/json" }, body: JSON.stringify(body || {}) });
const jdel = (path) => req(path, { method: "DELETE" });

export const adminApi = {
  me: () => req("admin/me"),
  tokens: () => req("admin/tokens"),
  playTicket: () => jpost("admin/play-ticket"),
  playLogin: (ticket) => jpost("admin/play-login", { ticket }),
  login: (username, password) => jpost("admin/login", { username, password }),
  logout: () => fetch(BASE + "admin/logout", { method: "POST", credentials: "include" }).catch(() => {}),

  dashboard: () => req("admin/dashboard"),
  streams: (healthy) => req(`admin/streams${healthy == null ? "" : "?healthy=" + healthy}`),

  // jobs (todos: POST inicia -> {job_id}; GET status)
  startClassifyImdb: () => jpost("admin/vod/classify-genres-imdb"),
  classifyImdbStatus: (id) => req(`admin/vod/classify-genres-imdb/${id}/status`),
  startFetchMetadata: (mode = "incremental") => jpost(`admin/vod/fetch-metadata?mode=${mode}`),
  fetchMetadataStatus: (id) => req(`admin/vod/fetch-metadata/${id}/status`),
  startHealthcheck: () => jpost("admin/healthcheck"),
  healthcheckStatus: (id) => req(`admin/healthcheck/${id}/status`),
  startClassifyChannels: () => jpost("admin/channels/classify-categories"),
  classifyChannelsStatus: (id) => req(`admin/channels/classify-categories/${id}/status`),

  // sistema (processos + consumo)
  systemJobs: () => req("admin/system/jobs"),
  cancelJob: (id) => jpost(`admin/system/jobs/${id}/cancel`),
  systemResources: () => req("admin/system/resources"),
  systemOsProcesses: () => req("admin/system/os-processes"),

  // banco: consulta (leitura) e execução (UPDATE/DELETE, roda como job)
  dbTables: () => req("admin/db/tables"),
  dbTable: (name) => req(`admin/db/tables/${encodeURIComponent(name)}`),
  dbQuery: (sql) => jpost("admin/db/query", { sql }),
  dbExecute: (sql, confirmFullTable) =>
    jpost("admin/db/execute", { sql, confirm_full_table: !!confirmFullTable }),
  dbExecuteStatus: (jobId) => req(`admin/db/execute/${jobId}/status`),

  // usuários do site (login admin/usuário)
  users: () => req("admin/users"),
  createUser: (b) => jpost("admin/users", b),
  deleteUser: (id) => jdel(`admin/users/${id}`),
  setUserContentTypes: (id, types) => jpatch(`admin/users/${id}/content-types`, { content_types: types }),
  setUserAdultContent: (id, sees) => jpatch(`admin/users/${id}/adult-content`, { sees_adult_content: sees }),

  // gêneros manuais
  titlesWithoutGenre: (type, limit, offset) =>
    req(`admin/vod/titles-without-genre?type=${type}&limit=${limit}&offset=${offset}`),
  setTitleGenre: (id, genre) => jpatch(`admin/vod/titles/${id}/genre`, { genre }),
  setTitleLanguage: (id, language) => jpatch(`admin/vod/titles/${id}/language`, { language }),
  languages: () => req("admin/languages"),

  // genre-keywords
  genreKeywords: () => req("admin/genre-keywords"),
  addGenreKeyword: (genre, keyword) => jpost("admin/genre-keywords", { genre, keyword }),
  delGenreKeyword: (id) => jdel(`admin/genre-keywords/${id}`),
  delGenre: (genre) => jdel(`admin/genre-keywords/genre/${encodeURIComponent(genre)}`),

  // catálogo / cadastro
  vodList: (params = {}) => req(`admin/vod?${new URLSearchParams(params)}`),
  vodGenres: (type) => req(`admin/vod/genres${type ? `?type=${type}` : ""}`),
  vodAdminDetail: (id) => req(`admin/vod/${id}`),
  addMovie: (b) => jpost("admin/vod/movie", b),
  addSeries: (b) => jpost("admin/vod/series", b),
  addEpisode: (titleId, b) => jpost(`admin/vod/${titleId}/episodes`, b),
  updateItem: (id, b) => jpatch(`admin/vod/items/${id}`, b),
  delTitle: (id) => jdel(`admin/vod/titles/${id}`),
  delItem: (id) => jdel(`admin/vod/items/${id}`),
  addChannel: (b) => jpost("admin/channels", b),

  // catálogo de canais
  channelCategories: () => req("admin/channels/categories"),
  channelList: (params = {}) => req(`admin/channels?${new URLSearchParams(params)}`),
  channelDetail: (id) => req(`admin/channels/${id}`),
  updateChannel: (id, b) => jpatch(`admin/channels/${id}`, b),
  delChannel: (id) => jdel(`admin/channels/${id}`),
  updateStream: (id, b) => jpatch(`admin/streams/${id}`, b),
  delStream: (id) => jdel(`admin/streams/${id}`),

  // importar fontes (só admin)
  sources: () => req("admin/sources"),
  addSource: (b) => jpost("admin/sources", b),
  approveSource: (id) => jpost(`admin/sources/${id}/approve`),
  deleteSource: (id) => jdel(`admin/sources/${id}`),
  syncSource: (id, b) => jpost(`admin/sources/${id}/sync`, b),
  syncPreset: (b) => jpost("admin/sources/sync-preset", b),
  githubScan: (repo) => jpost("admin/sources/github/scan", { repo }),
  githubSearch: (query) => jpost("admin/sources/github/search", { query }),
  validateStreams: () => jpost("admin/sources/validate"),
  validateStreamsStatus: (id) => req(`admin/sources/validate/${id}/status`),
  brokenStreams: (params = {}) => req(`admin/sources/broken?${new URLSearchParams(params)}`),
  deleteBroken: (id) => jdel(`admin/sources/broken/${id}`),
  deleteAllBroken: () => jpost("admin/sources/broken/delete-all"),
  validateFlow: (retest_all = false) => jpost("admin/sources/flow", { retest_all }),
  unstableStreams: (params = {}) => req(`admin/sources/unstable?${new URLSearchParams(params)}`),
  deleteUnstable: (id) => jdel(`admin/sources/unstable/${id}`),
  deleteAllUnstable: () => jpost("admin/sources/unstable/delete-all"),
  clearUnstable: () => jpost("admin/sources/unstable/clear"),
  xtreamTest: (b) => jpost("admin/sources/xtream/test", b),
  xtreamImport: (b) => jpost("admin/sources/xtream/import", b),
  xtreamStatus: (id) => req(`admin/sources/xtream/${id}/status`),
  vodAsChannels: () => req("admin/sources/vod-as-channels"),
  deleteVodAsChannels: () => jpost("admin/sources/vod-as-channels/delete"),
  tmdbStats: () => req("admin/tmdb/stats"),
  tmdbSearch: (params) => req(`admin/tmdb/search?${new URLSearchParams(params)}`),
  applyTmdb: (titleId, tmdbId) => jpost(`admin/vod/${titleId}/apply-tmdb`, { tmdb_id: tmdbId }),

  importM3u: (b) => jpost("admin/channels/import-m3u", b),
  importM3uStatus: (id) => req(`admin/channels/import-m3u/${id}/status`),
  importCsvStatus: (id) => req(`admin/vod/import-csv/${id}/status`),
  importChannelsCsvStatus: (id) => req(`admin/channels/import-csv/${id}/status`),
  importChannelsCsv: (file) => {
    const fd = new FormData();
    fd.append("file", file);
    return fetch(BASE + "admin/channels/import-csv", { method: "POST", credentials: "include", body: fd }).then(async (r) => {
      const j = await r.json().catch(() => ({}));
      if (!r.ok) throw new Error(j.detail || `HTTP ${r.status}`);
      return j;
    });
  },
  importCsv: (file) => {
    const fd = new FormData();
    fd.append("file", file);
    return fetch(BASE + "admin/vod/import-csv", { method: "POST", credentials: "include", body: fd }).then(async (r) => {
      const j = await r.json().catch(() => ({}));
      if (!r.ok) throw new Error(j.detail || `HTTP ${r.status}`);
      return j;
    });
  },
};
