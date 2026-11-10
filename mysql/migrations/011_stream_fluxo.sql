-- "Validar fluxo continuo": resultado do teste que le o stream por alguns
-- segundos (TS bruto) ou baixa segmentos de verdade (HLS). Fica separado do
-- is_healthy do health-check, que so confere os primeiros bytes.
--   flow_ok: 1 = fluxo confirmado, 0 = instavel/sem fluxo, NULL = nao testado ou inconclusivo
-- Se a coluna ja existir (banco criado por create_all), o erro "duplicada" e ignorado pelo migrador.
ALTER TABLE streams ADD COLUMN flow_ok TINYINT(1) NULL;
ALTER TABLE streams ADD COLUMN flow_checked_at DATETIME NULL;
ALTER TABLE streams ADD COLUMN flow_note VARCHAR(160) NULL;
