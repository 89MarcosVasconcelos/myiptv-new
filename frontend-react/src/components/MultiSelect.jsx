import { useState, useEffect, useRef, useMemo } from "preact/hooks";

// remove acento e caixa pra filtrar o menu ("camara" acha "Câmara")
export function norm(s) {
  return (s || "").normalize("NFD").replace(/[̀-ͯ]/g, "").toLowerCase();
}

const MAX_RENDER = 250; // o menu nunca desenha mais que isso de uma vez (900+ segmentos)

/**
 * Menu suspenso com seleção múltipla e busca.
 *  options:  [{ value, label, count? }]
 *  selected: array de values
 *  onChange(nextArray)
 *  onSearch(text)?  -> se informado, a busca é feita pelo pai (servidor) e as
 *                      opções chegam já filtradas; senão filtra aqui mesmo.
 *  total?    -> total real no servidor (mostra "X de Y" quando a lista é cortada)
 */
export function MultiSelect({
  label,
  icon,
  options,
  selected,
  onChange,
  onSearch,
  total,
  loading,
  disabled,
  disabledHint,
  placeholder = "Selecionar…",
  searchPlaceholder = "Buscar…",
}) {
  const [open, setOpen] = useState(false);
  const [text, setText] = useState("");
  const rootRef = useRef(null);
  const inputRef = useRef(null);

  useEffect(() => {
    if (!open) return;
    const away = (e) => rootRef.current && !rootRef.current.contains(e.target) && setOpen(false);
    const esc = (e) => e.key === "Escape" && setOpen(false);
    document.addEventListener("mousedown", away);
    document.addEventListener("touchstart", away, { passive: true });
    document.addEventListener("keydown", esc);
    setTimeout(() => inputRef.current && inputRef.current.focus(), 0);
    return () => {
      document.removeEventListener("mousedown", away);
      document.removeEventListener("touchstart", away);
      document.removeEventListener("keydown", esc);
    };
  }, [open]);

  useEffect(() => {
    if (!open) {
      setText("");
      onSearch && onSearch("");
    }
  }, [open]);

  const sel = useMemo(() => new Set(selected), [selected]);

  const filtered = useMemo(() => {
    if (onSearch || !text.trim()) return options;
    const words = norm(text).split(/\s+/).filter(Boolean);
    return options.filter((o) => {
      const l = norm(o.label);
      return words.every((w) => l.includes(w));
    });
  }, [options, text, onSearch]);

  const shown = filtered.slice(0, MAX_RENDER);
  const allShownSelected = shown.length > 0 && shown.every((o) => sel.has(o.value));

  const toggle = (v) => onChange(sel.has(v) ? selected.filter((x) => x !== v) : [...selected, v]);
  const selectShown = () => {
    const add = shown.map((o) => o.value).filter((v) => !sel.has(v));
    onChange([...selected, ...add]);
  };
  const clearShown = () => {
    const rm = new Set(shown.map((o) => o.value));
    onChange(selected.filter((v) => !rm.has(v)));
  };

  const summary =
    selected.length === 0 ? placeholder : selected.length === 1 ? "1 selecionado" : `${selected.length} selecionados`;

  return (
    <div ref={rootRef} class="relative min-w-0 flex-1 basis-[240px]">
      <div class="text-[10px] uppercase tracking-wider text-muted mb-1">{label}</div>
      <button
        type="button"
        disabled={disabled}
        onClick={() => setOpen(!open)}
        aria-haspopup="listbox"
        aria-expanded={open}
        title={disabled ? disabledHint : undefined}
        class={
          "w-full flex items-center gap-2 rounded border bg-card-hover px-3 py-2 text-sm text-left " +
          "disabled:opacity-50 disabled:cursor-not-allowed " +
          (open ? "border-accent" : "border-border")
        }
      >
        {icon && <span class="opacity-70">{icon}</span>}
        <span class={"flex-1 truncate " + (selected.length ? "text-text" : "text-muted")}>
          {disabled && disabledHint ? disabledHint : summary}
        </span>
        {selected.length > 0 && (
          <span class="text-[11px] bg-accent/15 text-accent rounded-full px-2 py-0.5">{selected.length}</span>
        )}
        <span class="text-muted text-xs">{open ? "▲" : "▼"}</span>
      </button>

      {open && (
        <div class="absolute z-40 left-0 right-0 mt-1 rounded-md border border-border bg-card shadow-xl">
          <div class="p-2 border-b border-border">
            <input
              ref={inputRef}
              value={text}
              onInput={(e) => {
                setText(e.currentTarget.value);
                onSearch && onSearch(e.currentTarget.value);
              }}
              placeholder={searchPlaceholder}
              class="w-full bg-card-hover border border-border rounded px-2.5 py-1.5 text-sm focus:border-accent focus:outline-none"
            />
            <div class="flex items-center gap-3 mt-2 text-[11px]">
              <button
                type="button"
                class="text-accent hover:underline disabled:opacity-40"
                disabled={shown.length === 0}
                onClick={allShownSelected ? clearShown : selectShown}
              >
                {allShownSelected ? "Desmarcar exibidos" : `Marcar exibidos (${shown.length})`}
              </button>
              <button
                type="button"
                class="text-muted hover:text-text hover:underline disabled:opacity-40"
                disabled={selected.length === 0}
                onClick={() => onChange([])}
              >
                Limpar tudo
              </button>
              {loading && <span class="ml-auto text-muted">carregando…</span>}
            </div>
          </div>

          <ul role="listbox" aria-multiselectable="true" class="max-h-72 overflow-y-auto py-1">
            {shown.length === 0 && (
              <li class="px-3 py-4 text-center text-xs text-muted">{loading ? "Carregando…" : "Nada encontrado."}</li>
            )}
            {shown.map((o) => {
              const on = sel.has(o.value);
              return (
                <li
                  key={o.value}
                  role="option"
                  aria-selected={on}
                  onClick={() => toggle(o.value)}
                  class={
                    "flex items-center gap-2 px-3 py-1.5 text-sm cursor-pointer hover:bg-card-hover " +
                    (on ? "text-accent" : "")
                  }
                >
                  <span
                    class={
                      "grid place-items-center h-4 w-4 shrink-0 rounded border text-[10px] leading-none " +
                      (on ? "bg-accent border-accent text-bg" : "border-border")
                    }
                  >
                    {on ? "✓" : ""}
                  </span>
                  <span class="flex-1 truncate">{o.label}</span>
                  {o.count != null && <span class="text-[11px] text-muted tabular-nums">{o.count}</span>}
                </li>
              );
            })}
          </ul>

          {(filtered.length > shown.length || (total != null && total > options.length)) && (
            <div class="px-3 py-1.5 border-t border-border text-[11px] text-muted">
              Mostrando {shown.length} de {total != null ? total : filtered.length}
              {" — "}digite para refinar.
            </div>
          )}
        </div>
      )}
    </div>
  );
}
