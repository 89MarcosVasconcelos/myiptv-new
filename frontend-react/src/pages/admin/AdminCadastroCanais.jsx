import { useState, useEffect, useRef } from "preact/hooks";
import { adminApi } from "../../lib/api.js";
import { CsvImport, Panel, inp } from "./CsvImport.jsx";

export function AdminCadastroCanais() {
  return (
    <div class="p-4 md:p-8 max-w-3xl">
      <h1 class="text-lg text-accent font-display mb-4">Cadastrar Canais</h1>
      <ChannelForm />
      <M3uImport />
      <CsvImport
        title="Importar CSV (canais ao vivo)"
        accept=".csv,text/csv"
        upload={adminApi.importChannelsCsv}
        status={adminApi.importChannelsCsvStatus}
        formatDone={(st) =>
          `Importado: ${st.canais_novos ?? 0} canal(is) novo(s), ${st.canais_atualizados ?? 0} atualizado(s), ${st.links_novos ?? 0} link(s) novo(s).` +
          (st.linhas_lixo?.length ? ` ${st.linhas_lixo.length} linha(s) barrada(s) por parecer lixo (separador/aviso da fonte, não canal de verdade).` : "")
        }
      />
      <p class="text-xs text-muted">
        Pra ver, editar ou apagar o que já foi cadastrado, use a aba{" "}
        <a href="#/admin/canais" class="text-accent hover:underline">
          Canais
        </a>
        .
      </p>
    </div>
  );
}

function ChannelForm() {
  const [f, setF] = useState({});
  const [msg, setMsg] = useState("");
  const up = (k) => (e) => setF({ ...f, [k]: e.currentTarget.value });
  async function submit(e) {
    e.preventDefault();
    if (!f.tvg_id || !f.name || !f.url) return setMsg("ID, nome e link são obrigatórios.");
    try {
      const r = await adminApi.addChannel({
        tvg_id: f.tvg_id, name: f.name, category: f.category || null, logo_url: f.logo || null,
        is_broadcast_tv: !!f.broadcast, stream_url: f.url,
      });
      setMsg(r.new_channel ? `Canal "${f.name}" criado.` : r.stream_added ? "Link adicionado como mirror." : "Link já existia.");
      setF({});
    } catch (err) {
      setMsg(err.message);
    }
  }
  return (
    <Panel title="Adicionar canal de TV ao vivo">
      <form onSubmit={submit} class="grid grid-cols-2 gap-2">
        <input class={inp} placeholder="tvg-id *" value={f.tvg_id || ""} onInput={up("tvg_id")} />
        <input class={inp} placeholder="Nome *" value={f.name || ""} onInput={up("name")} />
        <input class={inp} placeholder="Categoria" value={f.category || ""} onInput={up("category")} />
        <input class={inp} placeholder="Logo URL" value={f.logo || ""} onInput={up("logo")} />
        <input class={inp + " col-span-2"} placeholder="Link .m3u8 *" value={f.url || ""} onInput={up("url")} />
        <label class="text-xs text-muted flex items-center gap-2 col-span-2">
          <input type="checkbox" checked={!!f.broadcast} onChange={(e) => setF({ ...f, broadcast: e.currentTarget.checked })} /> É TV aberta
        </label>
        <button class="btn col-span-2 justify-center">Adicionar canal</button>
      </form>
      {msg && <p class="text-xs text-ok mt-2">{msg}</p>}
    </Panel>
  );
}

const PHASES = {
  baixando: "Baixando a lista…",
  lendo: "Lendo a lista…",
  validando: "Validando os links…",
  verificando: "Verificando os links…",
  gravando: "Gravando no banco…",
};

// importa uma lista M3U/M3U8 por URL ou texto colado. Cada link é validado
// (OK / inconclusivo entram; só os MORTOS são descartados) e os headers
// #EXTVLCOPT (referrer / user-agent) são preservados por link.
function M3uImport() {
  const [url, setUrl] = useState("");
  const [text, setText] = useState("");
  const [validate, setValidate] = useState(true);
  const [job, setJob] = useState(null);
  const [err, setErr] = useState("");
  const timer = useRef(null);

  useEffect(() => () => clearTimeout(timer.current), []);

  const running = job?.status === "running";

  async function poll(id) {
    try {
      const j = await adminApi.importM3uStatus(id);
      setJob(j);
      if (j.status === "running") timer.current = setTimeout(() => poll(id), 1000);
    } catch (e) {
      setErr(e.message);
    }
  }

  async function submit(e) {
    e.preventDefault();
    setErr("");
    if (!url.trim() && !text.trim()) return setErr("Informe a URL da lista ou cole o conteúdo.");
    try {
      const { job_id } = await adminApi.importM3u({
        url: url.trim() || null,
        text: url.trim() ? null : text,
        validate_links: validate,
      });
      setJob({ status: "running", phase: url.trim() ? "baixando" : "lendo", total: 0, processed: 0 });
      poll(job_id);
    } catch (e2) {
      setErr(e2.message);
    }
  }

  const st = job?.stats || {};
  const pct = job?.total ? Math.round((job.processed / job.total) * 100) : null;

  return (
    <Panel title="Importar lista M3U / M3U8">
      <form onSubmit={submit} class="space-y-2">
        <input class={inp} placeholder="URL da lista (https://…/lista.m3u)" value={url} onInput={(e) => setUrl(e.currentTarget.value)} disabled={running} />
        <textarea
          class={inp + " h-24 font-mono text-xs"}
          placeholder="…ou cole aqui o conteúdo (#EXTM3U …)"
          value={text}
          onInput={(e) => setText(e.currentTarget.value)}
          disabled={running || !!url.trim()}
        />
        <label class="text-xs text-muted flex items-center gap-2">
          <input type="checkbox" checked={validate} onChange={(e) => setValidate(e.currentTarget.checked)} disabled={running} />
          Validar cada link antes de gravar (descarta só os mortos)
        </label>
        <button class="btn" disabled={running}>{running ? "Importando…" : "Importar lista"}</button>
      </form>
      {err && <p class="mt-2 text-xs text-danger">{err}</p>}
      {job && (
        <div class="mt-3 text-xs">
          {job.status === "running" && (
            <p class="text-muted">
              {PHASES[job.phase] || "Processando…"} {pct != null ? `${pct}% (${job.processed}/${job.total})` : ""}
            </p>
          )}
          {job.status === "error" && <p class="text-danger">Erro: {job.error || "?"}</p>}
          {job.status === "cancelled" && <p class="text-warn">Importação cancelada.</p>}
          {job.status === "done" && (
            <p class="text-ok">
              Importado: {st.canais_novos ?? 0} canal(is) novo(s), {st.links_novos ?? 0} link(s) novo(s)
              {st.validado && st.links_ok != null ? ` — ${st.links_ok} OK` : ""}
              {st.links_inconclusivos ? `, ${st.links_inconclusivos} inconclusivo(s)` : ""}
              {st.links_mortos ? `, ${st.links_mortos} morto(s) descartado(s)` : ""}
              {st.links_bloqueados ? `, ${st.links_bloqueados} bloqueado(s)` : ""}.
            </p>
          )}
        </div>
      )}
    </Panel>
  );
}
