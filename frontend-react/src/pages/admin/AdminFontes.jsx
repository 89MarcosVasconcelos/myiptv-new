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
  const [opts, setOpts] = useState({ validate_links: true, skip_adult: false });
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
        Uso pessoal: só você (admin) vê esta página. Aqui entram <b>listas de canais ao vivo</b>; filmes, séries e
        animes entram pelo painel Xtream (abaixo) ou por CSV, e ganham capa e sinopse pelo TMDB, lá embaixo.
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
      <XtreamImport />
      <ValidateSources />
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
        <div class="flex flex-wrap gap-2">
          <button type="button" class="btn" disabled={disabled}
            onClick={() => onSync("Free-TV", presets.free_tv.url)}>
            Todos os países ⬇
          </button>
          {(presets.free_tv.countries || []).map((i) => (
            <button type="button" class="btn" disabled={disabled}
              onClick={() => onSync(`Free-TV · ${i.name}`, i.url)}>
              {i.name} ⬇
            </button>
          ))}
        </div>
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
      <TmdbLoad configured={configured} />
      <div class="text-[12px] uppercase tracking-wide text-muted mt-5 mb-2">Corrigir um título à mão</div>
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
        Este produto usa a API do TMDB, mas não é endossado nem certificado pelo TMDB.
      </p>
    </Panel>
  );
}

// cargas em lote do TMDB: complementar (só o que falta) e geral (todos os títulos)
function TmdbLoad({ configured }) {
  const [stats, setStats] = useState(null);
  const [job, setJob] = useState(null); // {id, mode, status, phase, processed, total, ...}
  const [confirm, setConfirm] = useState(false);
  const [err, setErr] = useState("");
  const timer = useRef(null);

  const loadStats = () => adminApi.tmdbStats().then(setStats).catch(() => {});
  useEffect(() => {
    loadStats();
    return () => clearTimeout(timer.current);
  }, []);

  const running = job?.status === "running";

  async function poll(id, mode) {
    try {
      const j = await adminApi.fetchMetadataStatus(id);
      setJob({ ...j, id, mode });
      if (j.status === "running") timer.current = setTimeout(() => poll(id, mode), 1000);
      else loadStats();
    } catch (e) {
      setErr(e.message);
    }
  }

  async function start(mode) {
    setErr("");
    setConfirm(false);
    try {
      const { job_id } = await adminApi.startFetchMetadata(mode);
      setJob({ id: job_id, mode, status: "running", processed: 0, total: 0 });
      poll(job_id, mode);
    } catch (e) {
      setErr(e.message);
    }
  }

  const pct = job?.total ? Math.round((job.processed / job.total) * 100) : null;

  return (
    <div data-testid="tmdb-load">
      <p class="text-xs text-muted mb-3">
        Busca no TMDB capa, fundo, sinopse, ano, gênero e nota dos títulos do catálogo
        {stats ? ` (${stats.total} no total, ${stats.missing} com algum dado faltando)` : ""}.
      </p>
      {stats && stats.total === 0 && (
        <p class="text-xs text-warn mb-3" data-testid="tmdb-empty">
          O catálogo de <b>Filmes/Séries/Anime</b> está vazio, por isso não há nada para carregar. As cargas só atuam em
          títulos do catálogo (não em canais de TV ao vivo). Importe os títulos pelo painel Xtream (seção acima) ou por
          CSV e depois rode a carga complementar para completar capa, sinopse, nota e gênero.
        </p>
      )}
      <div class="flex flex-wrap gap-2">
        <button class="btn" disabled={!configured || running} onClick={() => start("incremental")}>
          Carga complementar{stats ? ` (${stats.missing})` : ""}
        </button>
        <button class="btn" disabled={!configured || running} onClick={() => setConfirm(true)}>
          Carga geral{stats ? ` (${stats.total})` : ""}
        </button>
      </div>
      <p class="text-[11px] text-muted mt-2">
        <b>Complementar:</b> só o que ainda falta; nunca sobrescreve o que já está preenchido.{" "}
        <b>Geral:</b> refaz todos os títulos e sobrescreve capa, sinopse, nota, ano e gênero com o que o TMDB devolve
        (gêneros Anime e Adulto são mantidos).
      </p>
      {confirm && (
        <div class="mt-3 border border-warn/40 rounded p-3 text-xs">
          <p class="mb-2">
            A carga geral consulta o TMDB para <b>todos os {stats?.total ?? ""} títulos</b> e pode demorar bastante.
            Dados já preenchidos serão sobrescritos. Continuar?
          </p>
          <div class="flex gap-2">
            <button class="btn" onClick={() => start("full")}>Sim, fazer a carga geral</button>
            <button class="btn" onClick={() => setConfirm(false)}>Cancelar</button>
          </div>
        </div>
      )}
      {err && <p class="mt-2 text-xs text-danger">{err}</p>}
      {job && (
        <div class="mt-3 text-xs" data-testid="tmdb-job">
          <div class="text-muted mb-1">{job.mode === "full" ? "Carga geral" : "Carga complementar"}</div>
          {job.status === "running" && (
            <>
              <p class="text-muted">
                {job.phase || "Buscando no TMDB…"} {pct != null ? `${pct}% (${job.processed}/${job.total})` : ""}
              </p>
              {pct != null && (
                <div class="h-1.5 rounded bg-black/30 mt-2 overflow-hidden">
                  <div class="h-full bg-accent" style={{ width: `${pct}%` }} />
                </div>
              )}
              <button class="btn mt-2" onClick={() => adminApi.cancelJob(job.id).catch(() => {})}>Cancelar carga</button>
            </>
          )}
          {job.status === "error" && <p class="text-danger">Erro: {job.error || "?"}</p>}
          {job.status === "done" && job.processed === 0 && (
            <p class="text-warn">
              Nada para carregar: {stats?.total ? "todos os títulos já estão completos." : "o catálogo de filmes/séries está vazio."}
            </p>
          )}
          {(job.status === "done" || job.status === "cancelled") && job.processed > 0 && (
            <p class={job.status === "done" ? "text-ok" : "text-warn"}>
              {job.status === "done" ? "Concluído" : "Cancelado"}: {job.updated ?? 0} título(s) atualizado(s) de {job.processed} verificado(s)
              {" "}({job.matched ?? 0} capa(s) nova(s), {job.genres ?? 0} gênero(s)
              {job.not_found ? `, ${job.not_found} não encontrado(s) no TMDB` : ""}).
            </p>
          )}
        </div>
      )}
    </div>
  );
}


// Filmes / séries / animes direto da API de um painel Xtream Codes (player_api.php).
// Usuário e senha só trafegam nesta chamada; ficam apenas dentro dos links gerados (que precisam deles pra tocar).
function XtreamImport() {
  const [f, setF] = useState({ url: "", username: "", password: "", movies: true, series: true, skip_adult: false, max_series: "" });
  const [info, setInfo] = useState(null);
  const [job, setJob] = useState(null);
  const [err, setErr] = useState("");
  const [busy, setBusy] = useState(false);
  const [asChannels, setAsChannels] = useState(null);
  const [confirmDel, setConfirmDel] = useState(false);
  const [msg, setMsg] = useState("");
  const timer = useRef(null);

  const loadAsChannels = () => adminApi.vodAsChannels().then((r) => setAsChannels(r.channels)).catch(() => {});
  useEffect(() => {
    loadAsChannels();
    return () => clearTimeout(timer.current);
  }, []);

  const running = job?.status === "running";
  const set = (k) => (e) => setF({ ...f, [k]: e.currentTarget.type === "checkbox" ? e.currentTarget.checked : e.currentTarget.value });
  const body = () => ({
    url: f.url, username: f.username || undefined, password: f.password || undefined,
    movies: f.movies, series: f.series, skip_adult: f.skip_adult, max_series: parseInt(f.max_series, 10) || 0,
  });

  async function test() {
    setErr(""); setInfo(null); setBusy(true);
    try {
      setInfo(await adminApi.xtreamTest(body()));
    } catch (e) {
      setErr(e.message);
    } finally {
      setBusy(false);
    }
  }
  async function poll(id) {
    try {
      const j = await adminApi.xtreamStatus(id);
      setJob((cur) => ({ ...(cur || {}), ...j, id }));
      if (j.status === "running") timer.current = setTimeout(() => poll(id), 1500);
      else loadAsChannels();
    } catch (e) {
      setErr(e.message);
    }
  }
  async function start() {
    setErr(""); setMsg(""); setBusy(true);
    try {
      const { job_id } = await adminApi.xtreamImport(body());
      setJob({ id: job_id, status: "running", phase: "iniciando", total: 0, processed: 0 });
      poll(job_id);
    } catch (e) {
      setErr(e.message);
    } finally {
      setBusy(false);
    }
  }
  async function removeAsChannels() {
    setErr(""); setConfirmDel(false);
    try {
      const r = await adminApi.deleteVodAsChannels();
      setMsg(`${r.channels} canal(is) que eram filmes/séries foram removidos da TV ao vivo.`);
      loadAsChannels();
    } catch (e) {
      setErr(e.message);
    }
  }

  const pct = job?.total ? Math.round((job.processed / job.total) * 100) : null;
  const canGo = f.url.trim() && (f.movies || f.series) && !running && !busy;

  return (
    <Panel title="Importar filmes, séries e animes do painel Xtream">
      <p class="text-xs text-muted mb-3">
        Lê a API do painel (categorias, filmes, séries e todos os episódios) e grava no catálogo das telas
        <b> Filmes / Séries / Anime</b>, com capa, ano e nota quando o painel informa. Pode rodar de novo à vontade:
        não duplica, só atualiza e acrescenta o que for novo. O gênero vem da categoria do painel.
      </p>
      <div class="grid gap-2 sm:grid-cols-3">
        <input class={inp} placeholder="http://host:porta ou link da lista" value={f.url} onInput={set("url")}
          disabled={running} data-testid="xt-url" />
        <input class={inp} placeholder="Usuário (se não estiver no link)" value={f.username} onInput={set("username")}
          disabled={running} autocomplete="off" data-testid="xt-user" />
        <input class={inp} type="password" placeholder="Senha (se não estiver no link)" value={f.password}
          onInput={set("password")} disabled={running} autocomplete="new-password" data-testid="xt-pass" />
      </div>
      <div class="flex items-center gap-4 flex-wrap mt-2 text-xs text-muted">
        <label class="flex items-center gap-1.5"><input type="checkbox" checked={f.movies} disabled={running} onChange={set("movies")} /> Filmes</label>
        <label class="flex items-center gap-1.5"><input type="checkbox" checked={f.series} disabled={running} onChange={set("series")} /> Séries e animes</label>
        <label class="flex items-center gap-1.5"><input type="checkbox" checked={f.skip_adult} disabled={running} onChange={set("skip_adult")} /> Pular adultos</label>
        <label class="flex items-center gap-1.5">
          Limite de séries
          <input class={inp + " !w-20"} placeholder="todas" value={f.max_series} disabled={running}
            onInput={(e) => setF({ ...f, max_series: e.currentTarget.value.replace(/\D/g, "") })} />
        </label>
      </div>
      <div class="flex items-center gap-2 flex-wrap mt-3">
        <button class="btn" disabled={!f.url.trim() || running || busy} onClick={test} data-testid="xt-test">Testar conexão</button>
        <button class="btn" disabled={!canGo} onClick={start} data-testid="xt-import">Importar para o catálogo</button>
        {running && <button class="btn" onClick={() => adminApi.cancelJob(job.id).catch(() => {})}>Cancelar</button>}
      </div>
      {err && <p class="mt-2 text-xs text-danger">{err}</p>}
      {info && (
        <p class="mt-2 text-xs text-ok" data-testid="xt-info">
          Conexão ok — conta {info.status || "?"}, expira em {info.expira_em || "?"}, {info.conexoes_max ?? "?"} conexão(ões) simultânea(s),
          {" "}{info.categorias_filmes} categoria(s) de filmes e {info.categorias_series} de séries.
        </p>
      )}
      {job && (
        <div class="mt-3 text-xs" data-testid="xt-job">
          {running && (
            <>
              <p class="text-muted">
                {job.phase || "Processando…"} {pct != null ? `${pct}% (${job.processed}/${job.total})` : ""} — {job.titulos_novos} título(s) novo(s), {job.itens_novos} item(ns) novo(s)
              </p>
              <div class="h-2 rounded bg-black/30 mt-2 overflow-hidden">
                <div class="h-full bg-gradient-to-r from-accent2 to-accent transition-[width]" style={{ width: `${pct || 0}%` }} />
              </div>
            </>
          )}
          {job.status === "error" && <p class="text-danger">Erro: {job.error || "?"}</p>}
          {job.status === "cancelled" && <p class="text-warn">Importação cancelada em {job.processed} de {job.total}. O que já entrou ficou no catálogo.</p>}
          {job.status === "done" && (
            <p class="text-ok">
              Concluído: {job.titulos_novos} título(s) novo(s) e {job.titulos_atualizados} atualizado(s); {job.itens_novos} item(ns) novo(s) (filmes/episódios)
              {job.adultos_ignorados ? `; ${job.adultos_ignorados} adulto(s) ignorado(s)` : ""}
              {job.series_falhas ? `; ${job.series_falhas} série(s) não puderam ser lidas (rode de novo para tentar outra vez)` : ""}.
            </p>
          )}
        </div>
      )}

      {asChannels > 0 && (
        <div class="mt-5 border-t border-white/10 pt-3 text-xs" data-testid="xt-as-channels">
          <p class="mb-2">
            Há <b>{asChannels}</b> "canal(is)" na TV ao vivo que na verdade são filmes/séries (entraram pela lista M3U do painel).
            Depois de importar pelo painel Xtream, eles ficam duplicados e podem sair da TV ao vivo.
          </p>
          {confirmDel ? (
            <div class="border border-warn/40 rounded p-3">
              <p class="mb-2">Remover <b>{asChannels} canal(is)</b> da TV ao vivo? Só entram nisso os que têm <b>todos</b> os links em /movie/ ou /series/. Não dá para desfazer.</p>
              <button class="btn mr-2" onClick={removeAsChannels}>Sim, remover</button>
              <button class="btn" onClick={() => setConfirmDel(false)}>Cancelar</button>
            </div>
          ) : (
            <button class="btn" disabled={running} onClick={() => setConfirmDel(true)}>Remover da TV ao vivo ({asChannels})</button>
          )}
        </div>
      )}
      {msg && <p class="mt-2 text-xs text-ok">{msg}</p>}
    </Panel>
  );
}

// Validação em 2 etapas dos links de canais cadastrados:
//  1) "Validar fontes": o link responde e os primeiros bytes parecem vídeo; os MORTOS
//     (404/410, servidor inexistente) entram na lista de exclusão;
//  2) "Validar fluxo contínuo": lê o stream de verdade (alguns segundos de dados, ou os
//     segmentos do HLS) e lista os INSTÁVEIS — os que dão certo no teste rápido mas travam
//     ou param de enviar na hora de assistir.
const age = (sec) => (sec == null ? "nunca testado" : sec < 3600 ? `${Math.round(sec / 60)} min atrás` : sec < 86400 ? `${Math.round(sec / 3600)} h atrás` : `${Math.round(sec / 86400)} d atrás`);

function ValidateSources() {
  const [job, setJob] = useState(null);
  const [err, setErr] = useState("");
  const [retestAll, setRetestAll] = useState(false);
  const [refreshKey, setRefreshKey] = useState(0);
  const timer = useRef(null);

  useEffect(() => () => clearTimeout(timer.current), []);

  const running = job?.status === "running";

  async function poll(id) {
    try {
      const j = await adminApi.validateStreamsStatus(id);
      setJob((cur) => ({ ...(cur || {}), ...j, id }));
      if (j.status === "running") timer.current = setTimeout(() => poll(id), 1000);
      else setRefreshKey((k) => k + 1);
    } catch (e) {
      setErr(e.message);
    }
  }
  async function start(kind) {
    setErr("");
    try {
      const { job_id } =
        kind === "flow" ? await adminApi.validateFlow(retestAll) : await adminApi.validateStreams();
      setJob({ id: job_id, kind, status: "running", processed: 0, total: 0, ok: 0, dead: 0, unstable: 0, unknown: 0 });
      poll(job_id);
    } catch (e) {
      setErr(e.message);
    }
  }

  const isFlow = job?.kind === "flow";
  const pct = job?.total ? Math.round((job.processed / job.total) * 100) : null;

  return (
    <Panel title="Validar fontes">
      <p class="text-xs text-muted mb-3">
        <b>Etapa 1 — Validar fontes:</b> testa se cada link responde. Os mortos (404/410 ou servidor que não existe mais)
        vão para a lista abaixo. Bloqueio ou demora ficam como desconhecidos e não entram na lista.
        <br />
        <b>Etapa 2 — Validar fluxo contínuo:</b> lê o stream de verdade (alguns segundos de dados, ou os últimos
        segmentos de uma lista HLS). Pega o canal que abre mas trava, para de enviar ou dá erro minutos depois; os
        instáveis somem para os usuários e vão para a segunda lista. Dura mais que a etapa 1 e é feito um provedor de
        cada vez, com poucas conexões, para não ser bloqueado.
      </p>
      <div class="flex items-center gap-2 flex-wrap">
        <button class="btn" disabled={running} onClick={() => start("sources")} data-testid="btn-validate-sources">
          {running && !isFlow ? "Validando…" : "Validar fontes"}
        </button>
        <button class="btn" disabled={running} onClick={() => start("flow")} data-testid="btn-validate-flow">
          {running && isFlow ? "Lendo o fluxo…" : "Validar fluxo contínuo"}
        </button>
        {running && <button class="btn" onClick={() => adminApi.cancelJob(job.id).catch(() => {})}>Cancelar</button>}
        <label class="text-xs text-muted flex items-center gap-1.5">
          <input type="checkbox" checked={retestAll} disabled={running}
            onChange={(e) => setRetestAll(e.currentTarget.checked)} />
          Retestar também os links já aprovados no fluxo
        </label>
      </div>
      {err && <p class="mt-2 text-xs text-danger">{err}</p>}
      {job && (
        <div class="mt-3 text-xs" data-testid="validate-job">
          {running && (
            <>
              <p class="text-muted">
                {isFlow ? "Lendo o fluxo dos links…" : "Validando links…"} {pct != null ? `${pct}% (${job.processed}/${job.total})` : ""} —{" "}
                {isFlow
                  ? `${job.ok} com fluxo · ${job.unstable ?? 0} instáveis · ${job.unknown} inconclusivos`
                  : `${job.ok} ok · ${job.dead} mortos · ${job.unknown} desconhecidos`}
              </p>
              <div class="h-2 rounded bg-black/30 mt-2 overflow-hidden">
                <div class="h-full bg-gradient-to-r from-accent2 to-accent transition-[width]" style={{ width: `${pct || 0}%` }} />
              </div>
            </>
          )}
          {job.status === "error" && <p class="text-danger">Erro: {job.error || "?"}</p>}
          {job.status === "cancelled" && <p class="text-warn">Validação cancelada em {job.processed} de {job.total}.</p>}
          {job.status === "done" && (
            <p class="text-ok">
              {isFlow
                ? `Concluído: ${job.total} link(s) lido(s) — ${job.ok} com fluxo contínuo, ${job.unstable ?? 0} instável(is), ${job.unknown} inconclusivo(s).`
                : `Concluído: ${job.total} link(s) testado(s) — ${job.ok} ok, ${job.dead} morto(s), ${job.unknown} desconhecido(s).`}
            </p>
          )}
          {job.limited_hosts?.length > 0 && (
            <p class="text-warn mt-1">
              {job.limited_hosts.length} servidor(es) limitam conexões simultâneas ({job.limited_hosts.slice(0, 3).join(", ")}
              {job.limited_hosts.length > 3 ? "…" : ""}): os links deles não foram reprovados nem testados a fundo, ficam como inconclusivos.
            </p>
          )}
        </div>
      )}

      <ProblemList
        kind="dead" title="Links com problema (mortos)" running={running} refreshKey={refreshKey}
        list={adminApi.brokenStreams} del={adminApi.deleteBroken} delAll={adminApi.deleteAllBroken}
        emptyText="Nenhum link morto. Rode a etapa 1 para conferir."
        confirmText="mortos"
      />
      <ProblemList
        kind="flow" title="Links instáveis (sem fluxo contínuo)" running={running} refreshKey={refreshKey}
        list={adminApi.unstableStreams} del={adminApi.deleteUnstable} delAll={adminApi.deleteAllUnstable}
        clearAll={adminApi.clearUnstable}
        emptyText="Nenhum link instável. Rode a etapa 2 para conferir."
        confirmText="instáveis"
      />
    </Panel>
  );
}

function ProblemList({ kind, title, running, refreshKey, list: fetchList, del, delAll, clearAll, emptyText, confirmText }) {
  const [list, setList] = useState({ count: 0, items: [] });
  const [q, setQ] = useState("");
  const [confirmAll, setConfirmAll] = useState(false);
  const [msg, setMsg] = useState("");
  const [err, setErr] = useState("");
  const [loading, setLoading] = useState(false);
  const PAGE = 50;

  async function load(reset = true, query = q) {
    setLoading(true);
    try {
      const offset = reset ? 0 : list.items.length;
      const r = await fetchList({ limit: PAGE, offset, ...(query.trim() ? { q: query.trim() } : {}) });
      setList((cur) => ({ count: r.count, items: reset ? r.items : [...cur.items, ...r.items] }));
    } catch (e) {
      setErr(e.message);
    } finally {
      setLoading(false);
    }
  }
  useEffect(() => {
    load(true);
  }, [refreshKey]);

  async function removeOne(item) {
    setErr("");
    try {
      const r = await del(item.id);
      setList((cur) => ({ count: Math.max(0, cur.count - 1), items: cur.items.filter((i) => i.id !== item.id) }));
      setMsg(`Excluído: ${item.channel_name}${r.channels ? " (canal removido por ficar sem links)" : ""}.`);
    } catch (e) {
      setErr(e.message);
      load(true);
    }
  }
  async function removeAll() {
    setErr("");
    setConfirmAll(false);
    try {
      const r = await delAll();
      setMsg(`${r.links} link(s) excluído(s) e ${r.channels} canal(is) sem links removido(s).`);
      load(true);
    } catch (e) {
      setErr(e.message);
    }
  }

  async function clearMarks() {
    setErr("");
    try {
      const r = await clearAll();
      setMsg(`${r.links} link(s) liberado(s): voltaram a aparecer para os usuários (nada foi excluído).`);
      load(true);
    } catch (e) {
      setErr(e.message);
    }
  }

  return (
    <div class="mt-5" data-testid={kind === "flow" ? "unstable-list" : "broken-list"}>
      <div class="flex items-center gap-2 flex-wrap mb-2">
        <div class="text-[12px] uppercase tracking-wide text-muted flex-1">{title} ({list.count})</div>
        <input class={inp + " !w-48"} placeholder="Filtrar por canal ou link" value={q}
          onInput={(e) => setQ(e.currentTarget.value)}
          onKeyDown={(e) => e.key === "Enter" && load(true, e.currentTarget.value)} />
        {clearAll && (
          <button class="btn" disabled={list.count === 0 || running} onClick={clearMarks}
            title="Tira a marcação de instável de todos; nada é excluído">
            Limpar marcações ({list.count})
          </button>
        )}
        <button class="btn" disabled={list.count === 0 || running} onClick={() => setConfirmAll(true)}>
          Excluir todos ({list.count})
        </button>
      </div>
      {confirmAll && (
        <div class="mb-3 border border-warn/40 rounded p-3 text-xs">
          <p class="mb-2">
            Excluir <b>{list.count} link(s)</b> {confirmText} de uma vez? Canais que ficarem sem nenhum link também serão removidos. Não dá para desfazer.
          </p>
          <div class="flex gap-2">
            <button class="btn" onClick={removeAll}>Sim, excluir todos</button>
            <button class="btn" onClick={() => setConfirmAll(false)}>Cancelar</button>
          </div>
        </div>
      )}
      {msg && <p class="mb-2 text-xs text-ok">{msg}</p>}
      {err && <p class="mb-2 text-xs text-danger">{err}</p>}
      {list.items.length === 0 && !loading && <p class="text-xs text-muted">{emptyText}</p>}
      <ul class="space-y-1 max-h-96 overflow-y-auto thin-scroll">
        {list.items.map((i) => (
          <li class="text-xs flex items-center gap-2 border border-white/5 rounded px-2 py-1.5" data-testid="broken-row">
            <div class="flex-1 min-w-0">
              <div class="font-semibold truncate">{i.channel_name}</div>
              <div class="text-muted truncate" title={i.url}>{i.url}</div>
              <div class="text-muted">
                {kind === "flow" ? (i.note || "sem fluxo contínuo") : `${i.failures} falha(s)`} · testado {age(i.checked_age_seconds)}
              </div>
            </div>
            <button class="btn" disabled={running} onClick={() => removeOne(i)}>Excluir</button>
          </li>
        ))}
      </ul>
      {list.items.length < list.count && (
        <button class="btn mt-2" disabled={loading} onClick={() => load(false)}>Carregar mais</button>
      )}
    </div>
  );
}
