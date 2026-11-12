import { useState, useEffect, useRef, useMemo } from "preact/hooks";
import { api } from "../lib/api.js";
import { useFetch } from "../lib/useFetch.js";
import { navigate } from "../lib/router.jsx";
import { Card } from "../components/Card.jsx";
import { MultiSelect } from "../components/MultiSelect.jsx";

const PAGE = 60;
const MENU_LIMIT = 300; // canais carregados no 2º menu por vez (o resto vem digitando na busca do menu)
const NONE = "Sem categoria"; // mesmo valor de CATEGORY_NONE no backend
const STORE = "mptv.livetv.filters";

// lembra os filtros ao ir pro player e voltar (só durante a sessão da aba)
function loadSaved() {
  try {
    return JSON.parse(sessionStorage.getItem(STORE) || "{}") || {};
  } catch {
    return {};
  }
}

function Chip({ children, onRemove, title }) {
  return (
    <span
      title={title}
      class="inline-flex items-center gap-1 max-w-[220px] rounded-full border border-border bg-card-hover pl-2.5 pr-1 py-0.5 text-[12px]"
    >
      <span class="truncate">{children}</span>
      <button
        type="button"
        onClick={onRemove}
        aria-label="Remover"
        class="grid place-items-center h-4 w-4 rounded-full text-muted hover:text-text hover:bg-border"
      >
        ×
      </button>
    </span>
  );
}

export function LiveTV() {
  const saved = useRef(loadSaved()).current;
  const [q, setQ] = useState(saved.q || "");
  const [cats, setCats] = useState(saved.cats || []); // 1º menu: segmentos
  const [chans, setChans] = useState(saved.chans || []); // 2º menu: tvg_ids
  const [chanInfo, setChanInfo] = useState(saved.chanInfo || {}); // tvg_id -> {name, category}
  const [lang, setLang] = useState(saved.lang || null);
  const [page, setPage] = useState(0);
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);

  // 2º menu
  const [menuQ, setMenuQ] = useState("");
  const [menuQd, setMenuQd] = useState("");
  const [menu, setMenu] = useState({ channels: [], count: 0 });
  const [menuLoading, setMenuLoading] = useState(false);

  const categories = useFetch("channel-categories", () => api.channelCategories());
  const languages = useFetch("channel-languages", () => api.channelLanguages());

  const catOptions = useMemo(
    () => (categories.data?.categories || []).map((c) => ({ value: c.category, label: c.label, count: c.count })),
    [categories.data]
  );
  const catLabel = useMemo(() => {
    const m = {};
    catOptions.forEach((c) => (m[c.value] = c.label));
    return m;
  }, [catOptions]);

  // persiste filtros
  useEffect(() => {
    try {
      sessionStorage.setItem(STORE, JSON.stringify({ q, cats, chans, chanInfo, lang }));
    } catch {}
  }, [q, cats, chans, chanInfo, lang]);

  // ---- 1º menu mudou: descarta canais que não pertencem mais aos segmentos escolhidos
  useEffect(() => {
    if (chans.length === 0) return;
    const keep = chans.filter((id) => cats.includes(chanInfo[id]?.category));
    if (keep.length !== chans.length) setChans(keep);
  }, [cats]);

  // ---- 2º menu: carrega os canais dos segmentos escolhidos (busca no servidor)
  useEffect(() => {
    const id = setTimeout(() => setMenuQd(menuQ), 250);
    return () => clearTimeout(id);
  }, [menuQ]);

  const menuReq = useRef(0);
  useEffect(() => {
    if (cats.length === 0) {
      setMenu({ channels: [], count: 0 });
      return;
    }
    const my = ++menuReq.current;
    setMenuLoading(true);
    api
      .channelNames({ categories: cats, language: lang, q: menuQd.trim(), limit: MENU_LIMIT })
      .then((d) => {
        if (my !== menuReq.current) return;
        setMenu(d);
        setChanInfo((old) => {
          const next = { ...old };
          d.channels.forEach((c) => (next[c.tvg_id] = { name: c.name, category: c.category ?? NONE }));
          return next;
        });
      })
      .catch(() => {})
      .finally(() => my === menuReq.current && setMenuLoading(false));
  }, [cats, lang, menuQd]);

  const chanOptions = useMemo(
    () =>
      menu.channels.map((c) => ({
        value: c.tvg_id,
        label: c.name,
        // mostra o segmento quando há mais de um selecionado, pra distinguir homônimos
        count: cats.length > 1 ? c.category_label : null,
      })),
    [menu.channels, cats.length]
  );
  // itens já marcados que não estão na página atual do menu continuam aparecendo no topo
  const chanOptionsWithSelected = useMemo(() => {
    const present = new Set(chanOptions.map((o) => o.value));
    const extra = chans
      .filter((id) => !present.has(id) && !menuQd.trim())
      .map((id) => ({ value: id, label: chanInfo[id]?.name || id, count: null }));
    return [...extra, ...chanOptions];
  }, [chanOptions, chans, chanInfo, menuQd]);

  // ---- resultados (grade)
  const reqId = useRef(0);
  function fetchPage(p, filters) {
    const my = ++reqId.current;
    setLoading(true);
    const params = { limit: PAGE, offset: p * PAGE };
    if (filters.chans.length) params.tvg_ids = filters.chans;
    else if (filters.cats.length) params.categories = filters.cats;
    if (filters.lang) params.language = filters.lang;
    if (filters.q.trim()) params.q = filters.q.trim();
    api
      .channels(params)
      .then((d) => my === reqId.current && (setData(d), setLoading(false)))
      .catch(() => my === reqId.current && setLoading(false));
  }

  useEffect(() => {
    const id = setTimeout(() => {
      setPage(0);
      fetchPage(0, { q, cats, chans, lang });
    }, 250);
    return () => clearTimeout(id);
  }, [q, cats, chans, lang]);

  const go = (p) => {
    setPage(p);
    fetchPage(p, { q, cats, chans, lang });
    window.scrollTo({ top: 0 });
  };

  const clearAll = () => {
    setQ("");
    setCats([]);
    setChans([]);
    setLang(null);
  };

  const total = data?.count || 0;
  const pages = Math.max(1, Math.ceil(total / PAGE));
  const list = data?.channels || [];
  const hasFilter = q || cats.length || chans.length || lang;
  const scope =
    chans.length > 0
      ? `${chans.length} canal(is) escolhido(s)`
      : cats.length > 0
      ? `${cats.length} segmento(s)`
      : "todos os canais";
  const langLabel = languages.data?.languages?.find((l) => l.language === lang)?.label || lang;

  return (
    <div class="pb-16">
      <div class="px-4 md:px-8 pt-4">
        <div class="flex flex-wrap items-center gap-3 mb-3">
          <h1 class="text-lg text-accent font-display mr-2">TV ao vivo</h1>
          <div class="relative flex-1 min-w-[200px]">
            <input
              value={q}
              onInput={(e) => setQ(e.currentTarget.value)}
              placeholder={
                cats.length || chans.length ? "Buscar nos canais selecionados…" : "Buscar canal em todo o catálogo…"
              }
              aria-label="Buscar canal"
              class="w-full bg-card-hover border border-border rounded px-3 py-2 pr-8 text-sm focus:border-accent focus:outline-none"
            />
            {q && (
              <button
                type="button"
                onClick={() => setQ("")}
                aria-label="Limpar busca"
                class="absolute right-2 top-1/2 -translate-y-1/2 text-muted hover:text-text"
              >
                ×
              </button>
            )}
          </div>
          {(languages.data?.languages?.length || 0) > 1 && (
            <select
              value={lang || ""}
              onChange={(e) => setLang(e.currentTarget.value || null)}
              class="bg-card-hover border border-border rounded px-2 py-2 text-sm text-muted"
            >
              <option value="">Todos os idiomas</option>
              {languages.data.languages.map((l) => (
                <option value={l.language}>
                  {l.label} ({l.count})
                </option>
              ))}
            </select>
          )}
        </div>

        <div class="flex flex-wrap items-start gap-3">
          <MultiSelect
            label="1 · Segmento"
            icon="🗂"
            options={catOptions}
            selected={cats}
            onChange={setCats}
            loading={categories.loading}
            placeholder={`Todos os segmentos (${catOptions.length})`}
            searchPlaceholder="Buscar segmento…"
          />
          <MultiSelect
            label="2 · Canais do segmento"
            icon="📡"
            options={chanOptionsWithSelected}
            selected={chans}
            onChange={setChans}
            onSearch={setMenuQ}
            total={menu.count}
            loading={menuLoading}
            disabled={cats.length === 0}
            disabledHint="Escolha um segmento primeiro"
            placeholder={menu.count ? `Todos os canais (${menu.count})` : "Todos os canais"}
            searchPlaceholder="Buscar canal nos segmentos escolhidos…"
          />
        </div>

        {hasFilter && (
          <div class="flex flex-wrap items-center gap-1.5 mt-3">
            {cats.map((c) => (
              <Chip key={"c" + c} title="Segmento" onRemove={() => setCats(cats.filter((x) => x !== c))}>
                🗂 {catLabel[c] || c}
              </Chip>
            ))}
            {chans.map((id) => (
              <Chip key={"h" + id} title="Canal" onRemove={() => setChans(chans.filter((x) => x !== id))}>
                📡 {chanInfo[id]?.name || id}
              </Chip>
            ))}
            {lang && <Chip onRemove={() => setLang(null)}>🌐 {langLabel}</Chip>}
            {q && <Chip onRemove={() => setQ("")}>🔎 {q}</Chip>}
            <button type="button" onClick={clearAll} class="text-[12px] text-accent hover:underline ml-1">
              Limpar tudo
            </button>
          </div>
        )}
      </div>

      {loading && !data && <div class="text-muted text-sm px-8 py-10">Carregando…</div>}

      {data && (
        <>
          <div class="text-[11px] text-muted px-4 md:px-8 mt-4 mb-2">
            {total} canal(is) em {scope}
            {q.trim() ? ` · busca “${q.trim()}”` : ""}
            {loading ? " · atualizando…" : ""}
          </div>
          {list.length === 0 ? (
            <div class="text-muted text-sm px-8 py-10">
              Nenhum canal encontrado.{" "}
              {hasFilter && (
                <button type="button" class="text-accent hover:underline" onClick={clearAll}>
                  Limpar filtros
                </button>
              )}
            </div>
          ) : (
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3 md:gap-4 px-4 md:px-8">
              {list.map((c) => (
                <Card
                  fill
                  kind="channel"
                  title={c.name}
                  image={c.logo_url}
                  subtitle={c.now_playing?.title}
                  onClick={() => navigate(`/assistir/canal/${encodeURIComponent(c.tvg_id)}`)}
                />
              ))}
            </div>
          )}

          {pages > 1 && (
            <div class="flex items-center justify-center gap-4 mt-6 text-sm">
              <button class="btn-ghost rounded px-3 py-1.5 border border-border disabled:opacity-40" disabled={page <= 0} onClick={() => go(page - 1)}>
                ← Anterior
              </button>
              <span class="text-muted">
                {page + 1} / {pages}
              </span>
              <button class="btn-ghost rounded px-3 py-1.5 border border-border disabled:opacity-40" disabled={page + 1 >= pages} onClick={() => go(page + 1)}>
                Próxima →
              </button>
            </div>
          )}
        </>
      )}
    </div>
  );
}
