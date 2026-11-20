#!/usr/bin/env bash
# Testes de segurança e de horário duplicado. Rode com o servidor de pé e banco recém-criado:
#   php bin/setup.php --reset && php -S 127.0.0.1:8000 -t public &
#   bash bin/testar-seguranca.sh http://127.0.0.1:8000
# Só rode contra o SEU servidor local.
set -u
B="${1:-http://127.0.0.1:8000}"
T=$(mktemp -d); trap 'rm -rf "$T"' EXIT
ok=0; falha=0
passou() { echo "  OK   $1"; ok=$((ok+1)); }
falhou() { echo "  FALHA $1"; falha=$((falha+1)); }
cod() { curl -s -o /dev/null -w '%{http_code}' "$@"; }

echo "== Cabeçalhos"
H=$(curl -sI "$B/")
for h in "Content-Security-Policy: default-src 'self'" "X-Content-Type-Options: nosniff" "X-Frame-Options: DENY" "Referrer-Policy" "Permissions-Policy"; do
  grep -qi "$h" <<<"$H" && passou "$h" || falhou "falta $h"
done
grep -qi "X-Powered-By" <<<"$H" && falhou "X-Powered-By exposto" || passou "sem X-Powered-By"
grep -qi "set-cookie:.*httponly" <<<"$H" && grep -qi "samesite=strict" <<<"$H" && passou "cookie HttpOnly + SameSite=Strict" || falhou "cookie de sessão sem flags"

echo "== Arquivos fora do alcance"
[ "$(cod "$B/../data/agenda.sqlite")" != 200 ] && passou "banco não é servido" || falhou "banco acessível!"
[ "$(cod "$B/../app/config.php")" != 200 ] && passou "app/ não é servido" || falhou "app/ acessível!"

echo "== Pega sessão e token CSRF"
curl -s -c "$T/c" "$B/" > "$T/home.html"
CSRF=$(grep -o 'data-csrf="[a-f0-9]*"' "$T/home.html" | head -1 | cut -d'"' -f2)
[ ${#CSRF} -eq 64 ] && passou "token CSRF de 64 hex" || falhou "token CSRF ausente"

post() { curl -s -b "$T/c" -o "$T/r" -w '%{http_code}' -H 'Content-Type: application/json' "$@" ; }

echo "== CSRF e método"
[ "$(post -X POST "$B/api/agendar.php" -d '{}')" = 403 ] && passou "POST sem token = 403" || falhou "POST sem token aceito"
[ "$(post -X POST -H 'X-CSRF-Token: errado' "$B/api/agendar.php" -d '{}')" = 403 ] && passou "token errado = 403" || falhou "token errado aceito"
[ "$(post -X POST -H "X-CSRF-Token: $CSRF" -H 'Origin: https://site-malicioso.example' "$B/api/agendar.php" -d '{}')" = 403 ] && passou "Origin de outro site = 403" || falhou "Origin estranho aceito"
[ "$(cod "$B/api/agendar.php")" = 405 ] && passou "GET em /api/agendar = 405" || falhou "GET aceito"

echo "== Entrada maliciosa"
[ "$(cod "$B/api/dias.php?p=1%20OR%201=1&d=0")" = 422 ] && passou "SQLi em parâmetro = 422" || falhou "SQLi não barrado"
[ "$(cod "$B/api/horarios.php?p=1&d=0&data=2026-02-31")" = 422 ] && passou "data impossível = 422" || falhou "data inválida aceita"
DIA=$(curl -s "$B/api/dias.php?p=1&d=0" -b "$T/c" | python3 -c 'import sys,json; print(next(d["data"] for d in json.load(sys.stdin)["dias"] if d["livres"]>0))')
HORA=$(curl -s "$B/api/horarios.php?p=1&d=0&data=$DIA" -b "$T/c" | python3 -c 'import sys,json; print(json.load(sys.stdin)["horarios"][-1]["hora"])')
CORPO() { printf '{"procedimento":1,"dentista":0,"data":"%s","hora":"%s","nome":"%s","whatsapp":"%s","consentimento":true%s}' "$DIA" "$HORA" "$1" "$2" "${3:-}"; }
[ "$(post -X POST -H "X-CSRF-Token: $CSRF" "$B/api/agendar.php" -d "$(CORPO '<script>alert(1)</script>' 17999990001)")" = 422 ] && passou "nome com <script> recusado" || falhou "nome com HTML aceito"
[ "$(post -X POST -H "X-CSRF-Token: $CSRF" "$B/api/agendar.php" -d "$(CORPO 'Teste Exemplo' 123)")" = 422 ] && passou "WhatsApp inválido recusado" || falhou "WhatsApp inválido aceito"
[ "$(post -X POST -H "X-CSRF-Token: $CSRF" "$B/api/agendar.php" -d '{"procedimento":1,"dentista":0,"data":"'$DIA'","hora":"'$HORA'","nome":"Sem Consent","whatsapp":"17999990002"}')" = 422 ] && passou "sem consentimento recusado" || falhou "aceitou sem consentimento"
post -X POST -H "X-CSRF-Token: $CSRF" "$B/api/agendar.php" -d "$(CORPO 'Robo Exemplo' 17999990003 ',"site":"http://spam"')" >/dev/null
grep -q '"isca":true' "$T/r" && passou "robô no campo isca não grava nada" || falhou "isca não funcionou"
python3 - "$B" <<'PY' && passou "front-end sem innerHTML/eval" || falhou "innerHTML ou eval no JS"
import sys, re, pathlib
js = ''.join(p.read_text() for p in pathlib.Path('public/assets/js').glob('*.js'))
sys.exit(1 if re.search(r'\.(inner|outer)HTML\s*=|insertAdjacentHTML|document\.write|eval\(|new Function', js) else 0)
PY

echo "== Horário duplicado (10 pedidos ao mesmo tempo para o MESMO horário)"
for i in $(seq 10 19); do
  ( post -X POST -H "X-CSRF-Token: $CSRF" "$B/api/agendar.php" -d "$(CORPO "Corrida Exemplo" 179999900$i)" > "$T/corrida$i"; cp "$T/r" "$T/corpo$i" 2>/dev/null ) &
done
wait
SUC=$(cat "$T"/corrida* | grep -o 200 | wc -l)
echo "  códigos: $(for f in "$T"/corrida*; do cat "$f"; echo; done | sort | uniq -c | tr -s " \n" " ")"
[ "$SUC" -le 1 ] && passou "no máximo 1 reserva aceita ($SUC); o resto recebeu 409/429" || falhou "$SUC reservas no mesmo horário!"
N=$(php -r 'require "app/agenda.php"; $s=db()->prepare("SELECT COUNT(*) FROM agendamentos WHERE inicio=? AND status IN (\"pendente\",\"confirmado\") AND paciente_nome=\"Corrida Exemplo\""); $s->execute([$argv[1]." ".$argv[2]]); echo $s->fetchColumn();' "$DIA" "$HORA")
[ "$N" -le 1 ] && passou "banco tem $N consulta(s) da corrida nesse horário" || falhou "banco tem $N consultas no mesmo horário"
DUP=$(php -r 'require "app/agenda.php"; echo db()->query("SELECT COUNT(*) FROM (SELECT dentista_id, slot FROM agenda_slots GROUP BY 1,2 HAVING COUNT(*)>1)")->fetchColumn();')
[ "$DUP" = 0 ] && passou "nenhum pedaço de agenda ocupado duas vezes" || falhou "slots duplicados: $DUP"

echo "== Rate limit do agendamento"
for i in 1 2 3 4 5 6; do post -X POST -H "X-CSRF-Token: $CSRF" "$B/api/agendar.php" -d "$(CORPO 'Limite Exemplo' 1799999000$i)" > "$T/lim"; done
[ "$(cat "$T/lim")" = 429 ] && passou "excesso de agendamentos = 429" || falhou "sem limite de agendamentos ($(cat "$T/lim"))"

echo "== Link do paciente"
[ "$(post -X POST -H "X-CSRF-Token: $CSRF" "$B/api/cancelar.php" -d '{"codigo":"AAAAAA","token":"'$(printf 'a%.0s' {1..48})'"}')" = 404 ] && passou "token falso não cancela nada" || falhou "cancelamento com token falso"

echo "== Painel"
[ "$(cod "$B/admin/api/agenda.php")" = 401 ] && passou "API do painel sem login = 401" || falhou "API do painel aberta"
[ "$(cod "$B/admin/")" = 302 ] && passou "painel sem login redireciona" || falhou "painel aberto"
curl -s -c "$T/l" "$B/admin/login.php" > "$T/login.html"
LC=$(grep -o 'name="csrf" value="[a-f0-9]*"' "$T/login.html" | cut -d'"' -f4)
for i in 1 2 3 4 5 6; do curl -s -b "$T/l" -c "$T/l" "$B/admin/login.php" --data-urlencode "csrf=$LC" --data-urlencode "usuario=recepcao" --data-urlencode "senha=errada$i" > "$T/lr"; done
grep -q "Muitas tentativas" "$T/lr" && passou "6ª senha errada bloqueada por 15 min" || falhou "login sem limite de tentativas"
curl -s -b "$T/l" "$B/admin/login.php" --data-urlencode "csrf=errado" --data-urlencode "usuario=x" --data-urlencode "senha=y" | grep -q "ficou aberta" && passou "login sem CSRF válido recusado" || falhou "login aceita CSRF inválido"

echo
echo "Resultado: $ok ok, $falha falha(s)"
[ "$falha" = 0 ]
