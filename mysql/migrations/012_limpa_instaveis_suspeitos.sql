-- O "Validar fluxo continuo" da primeira versao reprovou ~21 mil links de um mesmo painel Xtream
-- por 401/403/429 ("limite de conexoes"), timeout e erro de conexao -- sintomas de servidor que
-- limita conexoes simultaneas (inclusive as do proprio teste), nao de link morto. Libera esses links.
-- Ficam marcados so os que tiveram evidencia propria (404, pagina no lugar do video, segmento vazio...).
UPDATE streams SET flow_ok = NULL, flow_note = NULL WHERE flow_ok = 0 AND (flow_note LIKE '401%' OR flow_note LIKE '403%' OR flow_note LIKE '429%' OR flow_note LIKE 'demorou%' OR flow_note LIKE 'conex%' OR flow_note LIKE 'travou%' OR flow_note LIKE 'o servidor encerrou%' OR flow_note LIKE 'pausa%' OR flow_note LIKE 'HTTP 5%' OR flow_note LIKE 'abriu a conex%');
