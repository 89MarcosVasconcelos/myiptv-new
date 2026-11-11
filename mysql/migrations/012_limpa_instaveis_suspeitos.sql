-- O "Validar fluxo continuo" da primeira versao reprovou ~21 mil links de um mesmo painel Xtream
-- por 401/403/429 ("limite de conexoes"), timeout e erro de conexao -- sintomas de servidor que
-- limita conexoes simultaneas (inclusive as do proprio teste), nao de link morto. Libera esses links.
-- Ficam marcados so os que tiveram evidencia propria (404, pagina no lugar do video, segmento vazio...).
UPDATE streams SET flow_ok = NULL, flow_note = NULL WHERE flow_ok = 0 AND (
  LEFT(flow_note, 3) IN ('401', '403', '429')
  OR LEFT(flow_note, 7) = 'demorou'
  OR LEFT(flow_note, 5) = 'conex'
  OR LEFT(flow_note, 6) = 'travou'
  OR LEFT(flow_note, 19) = 'o servidor encerrou'
  OR LEFT(flow_note, 5) = 'pausa'
  OR LEFT(flow_note, 6) = 'HTTP 5'
  OR LEFT(flow_note, 13) = 'abriu a conex'
);
