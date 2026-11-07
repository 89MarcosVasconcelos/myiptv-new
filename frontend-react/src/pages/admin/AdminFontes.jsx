import { useState, useEffect, useRef } from "preact/hooks";
import { adminApi } from "../../lib/api.js";
import { Panel, inp } from "./CsvImport.jsx";

const PHASES = {
  baixando: "Baixando a lista…",
  lendo: "Lendo a lista…",
  validando: "Validando os links…",
  verificando: "Verificando os links…",
  gravando: "Gravando no banco…",
};

const when = (iso) => (iso ? new Date(iso + (iso.endsWith("Z") ? "" : "Z")).toLocaleString("pt-BR") : "nunca");

function summaryText(s) {
  if (!s) return "";
  if (s.erro) return `erro: ${s.erro}`;
  const parts = [`${s.canais_novos ?? 0} canal(is) novo(s)`, `${s.links_novos ?? 0} link(s) novo(s)`];
  if (s.links_mortos) parts.push(`${s.links_mortos} morto(s) descartado(s)`);
  if (s.adultos_ignorados) parts.push(`${s.adultos_ignorados} adulto(s) ignorado(s)`);
  return parts.join(" · ");
}

// importação de fontes de canais ao vivo + descoberta no GitHub + capas do TMDB.
// Página só do admin (a rota já é protegida no App e no servidor).
export function AdminFontes() {
  const [data, setData] = useState(null);
  const [opts, setOpts] = useState({ validate_links: true, skip_adult: true });
  const [job, setJob] = useState(null); // {id, label, status, phase, processed, total, stats, error}
  const [err, setErr] = useState("");
  const timer = useRef(null);

  const load = () => adminApi.sources().then(setData).catch((e) => setErr(e.message));
  useEffect(() => {
    load();
    return () => clearTimeout(timer.current);
  }, []);

  const running = job?.status === "running";

  async function poll(id, label) {
    try {
      const j = await adminApi.importM3uStatus(id);
      setJob({ ...j, id, label });
      if (j.status === "running") timer.current = setTimeout(() => poll(id, label), 1000);
      else load();
    } catch (e) {
      setErr(e.message);
    }
  }

  async function launch(label, call) {
    setErr("");
    try {
      const { job_id } = await call();
      setJob({ id: job_id, label, status: "running", phase: "baixando", total: 0, processed: 0 });
      poll(job_id, label);
    } catch (e) {
      setErr(e.message);
    }
  }

  const syncPreset = (name, url) => launch(name, () => adminApi.syncPreset({ name, url, ...opts }));
  const syncSaved = (s) => launch(s.name, () => adminApi.syncSource(s.id, opts));

  if (!data) return <div class="p-4 text-sm text-muted">{err || "Carregando…"}</div>;

  return (
    <div class="p-4 max-w-4xl">
      <p class="text-xs text-muted mb-4">
        Uso pessoal: só você (admin) vê esta página. Aqui entram <b>listas de canais ao vivo</b>; filmes e séries
        continuam sendo cadastrados à mão (CSV) e ganham capa e sinopse pelo TMDB, lá embaixo.
      </p>

      <Panel title="Opções da importação">
        <label class="text-xs text-muted flex items-center gap-2 mb-1">
          <input type="checkbox" checked={opts.validate_links} disabled={running}
            onChange={(e) => setOpts({ ...opts, validate_links: e.currentTarget.checked })} />
          Validar cada link antes de gravar (descarta só os mortos)
        </label>
        <label class="text-xs text-muted flex items-center gap-2">
          <input type="checkbox" checked={opts.skip_adult} disabled={running}
            onChange={(e) => setOpts({ ...opts, skip_adult: e.currentTarget.checked })} />
          Pular canais adultos
        </label>
      </Panel>

      {err && <p class="mb-3 text-xs text-danger">{err}</p>}
      {job && <JobBox job={job} />}

      <Presets presets={data.presets} onSync={syncPreset} disabled={running} />
      <MySources data={data} onChange={load} onSync={syncSaved} running={running} setErr={setErr} />
      <GitHubDiscover data={data} onAdded={load} sources={data.sources} />
      <TmdbTools configured={data.tmdb_configured} />
    </div>
  );
}

function JobBox({ job }) {
  const st = job.stats || {};
  const pct = job.total ? Math.round((job.processed / job.total) * 100) : null;
  return (
    <div class="panel mb-4 text-xs" data-testid="job-box">
      <div class="text-muted mb-1">Importação: <b>{job.label}</b></div>
      {job.status === "running" && (
        <>
          <p class="text-muted">
            {PHASES[job.phase] || "Processando…"} {pct != null ? `${pct}% (${job.processed}/${job.total})` : ""}
          </p>
          {pct != null && (
            <div class="h-1.5 rounded bg-black/30 mt-2 overflow-hidden">
              <div class="h-full bg-accent" style={{ width: `${pct}%` }} />
            </div>
          )}
        </>
      )}
      {job.status === "error" && <p class="text-danger">Erro: {job.error || "?"}</p>}
      {job.status === "cancelled" && <p class="text-warn">Importação cancelada.</p>}
      {job.status === "done" && (
        <p class="text-ok">
          Concluído: {st.canais_novos ?? 0} canal(is) novo(s), {st.links_novos ?? 0} link(s) novo(s) ({st.total_lidos ?? 0} lidos
          {st.links_mortos ? `, ${st.links_mortos} mortos descartados` : ""}
          {st.adultos_ignorados ? `, ${st.adultos_ignorados} adultos ignorados` : ""}).
        </p>
      )}
    </div>
  );
}

function Presets({ presets, onSync, disabled }) {
  const [group, setGroup] = useState("countries");
  const org = presets.iptv_org;
  const g = org.groups.find((x) => x.id === group) || org.groups[0];
  return (
    <Panel title="Fontes prontas">
      <div class="mb-4">
        <div class="text-sm font-semibold">{org.title}</div>
        <p class="text-xs text-muted mb-2">{org.note}</p>
        <div class="flex gap-2 mb-2">
          {org.groups.map((x) => (
            <button type="button" class={"chip " + (x.id === g.id ? "active" : "")} onClick={() => setGroup(x.id)}>
              {x.label}
            </button>
          ))}
        </div>
        <div class="flex flex-wrap gap-2">
          {g.items.map((i) => (
            <button type="button" class="btn" disabled={disabled}
              onClick={() => onSync(`iptv-org · ${i.name}`, i.url)}>
              {i.name} ⬇
            </button>
          ))}
        </div>
      </div>
      <div>
        <div class="text-sm font-semibold">{presets.free_tv.title}</div>
        <p class="text-xs text-muted mb-2">{presets.free_tv.note}</p>
        <button type="button" class="btn" disabled={disabled}
          onClick={() => onSync("Free-TV", presets.free_tv.url)}>
          Importar Free-TV ⬇
        </button>
      </div>
    </Panel>
  );
}

function MySources({ data, onChange, onSync, running, setErr }) {
  const [url, setUrl] = useState("");
  const [name, setName] = useState("");

  async function add(e) {
    e.preventDefault();
    setErr("");
    try {
      await adminApi.addSource({ url: url.trim(), name: name.trim() || null, origin: "custom" });
      setUrl("");
      setName("");
      onChange();
    } catch (e2) {
      setErr(e2.message);
    }
  }
  const act = (fn) => async () => {
    setErr("");
    try {
      await fn();
      onChange();
    } catch (e) {
      setErr(e.message);
    }
  };

  return (
    <Panel title="Minhas fontes">
      <form onSubmit={add} class="grid grid-cols-3 gap-2 mb-3">
        <input class={inp + " col-span-2"} placeholder="URL da lista (https://…/lista.m3u)" value={url}
          onInput={(e) => setUrl(e.currentTarget.value)} />
        <input class={inp} placeholder="Nome (opcional)" value={name} onInput={(e) => setName(e.currentTarget.value)} />
        <button class="btn col-span-3 justify-center" disabled={!url.trim()}>Salvar fonte</button>
      </form>
      {data.sources.length === 0 && <p class="text-xs text-muted">Nenhuma fonte salva ainda.</p>}
      <ul class="space-y-2">
        {data.sources.map((s) => (
          <li class="text-xs border border-white/5 rounded p-2" data-testid="source-row">
            <div class="flex items-center gap-2">
              <span class="font-semibold truncate flex-1" title={s.url}>{s.name}</span>
              {s.status === "pending" && <span class="chip active">pendente</span>}
              <span class="text-muted">{s.origin}</span>
            </div>
            <div class="text-muted truncate" title={s.url}>{s.url}</div>
            <div class="text-muted mt-1">
              Última sincronização: {when(s.last_sync_at)}
              {s.last_status === "error" ? " (falhou)" : ""} {s.last_summary ? "— " + summaryText(s.last_summary) : ""}
            </div>
            <div class="flex gap-2 mt-2">
              {s.status === "pending" ? (
                <button class="btn" onClick={act(() => adminApi.approveSource(s.id))}>Aprovar</button>
              ) : (
                <button class="btn" disabled={running} onClick={() => onSync(s)}>Sincronizar</button>
              )}
              <button class="btn" onClick={act(() => adminApi.deleteSource(s.id))}>Remover</button>
            </div>
          </li>
        ))}
      </ul>
      <p class="text-[11px] text-muted mt-2">
        Remover a fonte não apaga os canais já importados; só tira a fonte desta lista.
      </p>
    </Panel>
  );
}

function GitHubDiscover({ data, onAdded, sources }) {
  const [repo, setRepo] = useState("");
  const [query, setQuery] = useState("");
  const [res, setRes] = useState(null);
  const [msg, setMsg] = useState("");
  const [busy, setBusy] = useState(false);
  const known = new Set(sources.map((s) => s.url));

  async function run(fn) {
    setMsg("");
    setBusy(true);
    try {
      setRes(await fn());
    } catch (e) {
      setRes(null);
      setMsg(e.message);
    } finally {
      setBusy(false);
    }
  }
  async function queue(c) {
    try {
      await adminApi.addSource({ url: c.url, name: c.name, origin: "github" });
      onAdded();
    } catch (e) {
      setMsg(e.message);
    }
  }

  return (
    <Panel title="Descobrir listas no GitHub">
      <p class="text-xs text-muted mb-2">
        O que você achar aqui vai para <b>Minhas fontes</b> como <b>pendente</b>. Nada é baixado nem importado até você aprovar.
      </p>
      <form class="flex gap-2 mb-2" onSubmit={(e) => { e.preventDefault(); run(() => adminApi.githubScan(repo)); }}>
        <input class={inp + " flex-1"} placeholder="Varrer um repositório: dono/nome (ex.: Free-TV/IPTV)" value={repo}
          onInput={(e) => setRepo(e.currentTarget.value)} />
        <button class="btn" disabled={busy || !repo.trim()}>Varrer</button>
      </form>
      <form class="flex gap-2" onSubmit={(e) => { e.preventDefault(); run(() => adminApi.githubSearch(query)); }}>
        <input class={inp + " flex-1"} placeholder={data.github_search_enabled ? "Buscar por código no GitHub (ex.: brasil)" : "Busca por código desligada (falta GITHUB_TOKEN no servidor)"}
          value={query} disabled={!data.github_search_enabled} onInput={(e) => setQuery(e.currentTarget.value)} />
        <button class="btn" disabled={busy || !data.github_search_enabled || !query.trim()}>Buscar</button>
      </form>
      {msg && <p class="mt-2 text-xs text-danger">{msg}</p>}
      {res && (
        <div class="mt-3">
          <p class="text-xs text-muted mb-1">
            {res.candidates.length} lista(s) encontrada(s){res.repo ? ` em ${res.repo}` : ""}{res.truncated ? " (resultado cortado)" : ""}.
          </p>
          <ul class="space-y-1 max-h-72 overflow-y-auto thin-scroll">
            {res.candidates.map((c) => (
              <li class="text-xs flex items-center gap-2">
                <span class="truncate flex-1" title={c.url}>{c.name}</span>
                <button class="btn" disabled={known.has(c.url)} onClick={() => queue(c)}>
                  {known.has(c.url) ? "Na fila" : "Adicionar à fila"}
                </button>
              </li>
            ))}
          </ul>
        </div>
      )}
    </Panel>
  );
}

// escolhe, à mão, o resultado certo do TMDB para um título do catálogo
function TmdbTools({ configured }) {
  const [q, setQ] = useState("");
  const [titles, setTitles] = useState(null);
  const [sel, setSel] = useState(null);
  const [cands, setCands] = useState(null);
  const [msg, setMsg] = useState("");

  async function findTitles(e) {
    e.preventDefault();
    setMsg("");
    setSel(null);
    setCands(null);
    try {
      setTitles((await adminApi.vodList({ q, limit: 20 })).titles);
    } catch (e2) {
      setMsg(e2.message);
    }
  }
  async function pick(t) {
    setSel(t);
    setCands(null);
    setMsg("");
    try {
      const p = { title: t.title, type: t.type };
      if (t.year) p.year = t.year;
      setCands((await adminApi.tmdbSearch(p)).results);
    } catch (e) {
      setMsg(e.message);
    }
  }
  async function apply(c) {
    try {
      const r = await adminApi.applyTmdb(sel.id, c.tmdb_id);
      setMsg(`Dados de "${c.title}" aplicados a "${sel.title}".`);
      setTitles((ts) => ts.map((t) => (t.id === sel.id ? { ...t, poster_url: r.title.poster_url, year: r.title.year } : t)));
    } catch (e) {
      setMsg(e.message);
    }
  }

  return (
    <Panel title="Capas e dados (TMDB)">
      {!configured && (
        <p class="text-xs text-warn mb-2">TMDB_API_KEY não está configurada no servidor — configure para usar esta seção.</p>
      )}
      <form class="flex gap-2 mb-2" onSubmit={findTitles}>
        <input class={inp + " flex-1"} placeholder="Buscar título do catálogo (ex.: Vingadores)" value={q}
          onInput={(e) => setQ(e.currentTarget.value)} />
        <button class="btn" disabled={!q.trim()}>Buscar</button>
      </form>
      {msg && <p class="text-xs text-ok mb-2">{msg}</p>}
      {titles && !sel && (
        <ul class="space-y-1">
          {titles.length === 0 && <li class="text-xs text-muted">Nada encontrado no catálogo.</li>}
          {titles.map((t) => (
            <li class="text-xs flex items-center gap-2">
              <span class="flex-1 truncate">{t.title} {t.year ? `(${t.year})` : ""} · {t.type === "series" ? "série" : "filme"}</span>
              <button class="btn" disabled={!configured} onClick={() => pick(t)}>Escolher no TMDB</button>
            </li>
          ))}
        </ul>
      )}
      {sel && (
        <div>
          <p class="text-xs text-muted mb-2">
            Resultados do TMDB para <b>{sel.title}</b>{" "}
            <button class="text-accent hover:underline" onClick={() => { setSel(null); setCands(null); }}>(trocar título)</button>
          </p>
          {!cands && <p class="text-xs text-muted">Buscando…</p>}
          <ul class="space-y-2">
            {(cands || []).map((c) => (
              <li class="flex gap-3 text-xs border border-white/5 rounded p-2" data-testid="tmdb-candidate">
                {c.poster_url ? <img src={c.poster_url} alt="" class="w-12 h-[72px] object-cover rounded" /> : <div class="w-12 h-[72px] bg-black/30 rounded" />}
                <div class="flex-1 min-w-0">
                  <div class="font-semibold">{c.title} {c.year ? `(${c.year})` : ""} {c.rating ? `· ★ ${c.rating}` : ""}</div>
                  <div class="text-muted line-clamp-2">{c.overview || "Sem sinopse."}</div>
                </div>
                <button class="btn self-center" onClick={() => apply(c)}>Usar este</button>
              </li>
            ))}
            {cands && cands.length === 0 && <li class="text-xs text-muted">O TMDB não achou nada com esse título.</li>}
          </ul>
        </div>
      )}
      <p class="text-[11px] text-muted mt-3">
        Este produto usa a API do TMDB, mas não é endossado nem certificado pelo TMDB. Para preencher em lote os
        títulos sem capa, use o botão de metadados no Dashboard.
      </p>
    </Panel>
  );
}
