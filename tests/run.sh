#!/usr/bin/env bash
# Интеграционные тесты: поднимает встроенный сервер PHP и проверяет send.php через curl.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PORT="${PORT:-8099}"
BASE="http://127.0.0.1:${PORT}"
TMP="$(mktemp -d)"
mkdir -p "$TMP/data" "$TMP/sessions"

export LEAD_FORM_CONFIG="$ROOT/tests/config.test.php"
export LEAD_FORM_DATA_DIR="$TMP/data"

php -d session.save_path="$TMP/sessions" -d display_errors=0 -S "127.0.0.1:${PORT}" -t "$ROOT" >"$TMP/server.log" 2>&1 &
SERVER_PID=$!

cleanup() {
    kill "$SERVER_PID" 2>/dev/null || true
    wait "$SERVER_PID" 2>/dev/null || true
    rm -rf "$TMP"
}
trap cleanup EXIT

for _ in $(seq 1 50); do
    if curl -s -o /dev/null "$BASE/token.php"; then break; fi
    sleep 0.1
done

PASS=0
FAIL=0
BODY="$TMP/body.json"

# request <cookie-jar> <curl args...>; печатает HTTP-код, тело в $BODY
request() {
    local jar="$1"; shift
    curl -s -o "$BODY" -w '%{http_code}' -b "$jar" -c "$jar" "$@"
}

check() {
    local name="$1" expected="$2" actual="$3"
    if [[ "$expected" == "$actual" ]]; then
        PASS=$((PASS + 1)); echo "  ok    $name"
    else
        FAIL=$((FAIL + 1)); echo "  FAIL  $name: ожидалось '$expected', получено '$actual'"
        echo "        тело: $(cat "$BODY" 2>/dev/null)"
    fi
}

# json_get <path через точку>: значение поля из $BODY
json_get() {
    php -r '$j = json_decode(file_get_contents($argv[1]), true); foreach (explode(".", $argv[2]) as $k) { $j = is_array($j) ? ($j[$k] ?? null) : null; } echo is_bool($j) ? ($j ? "true" : "false") : (is_scalar($j) ? $j : (is_null($j) ? "" : "set"));' "$BODY" "$1"
}

get_token() {
    local jar="$1"
    request "$jar" "$BASE/token.php" >/dev/null
    json_get token
}

leads_count() {
    if [[ -f "$TMP/data/leads.jsonl" ]]; then wc -l <"$TMP/data/leads.jsonl" | tr -d ' '; else echo 0; fi
}

VALID=(--data-urlencode "name=Тестовый Клиент" --data-urlencode "contact=+7 (900) 123-45-67"
       --data-urlencode "message=Нужен сайт-визитка" --data-urlencode "consent=1" --data-urlencode "website=")

echo "php-lead-form: интеграционные тесты на $BASE"

JAR="$TMP/jar-main"
JAR_EMPTY="$TMP/jar-empty"

code=$(request "$JAR" "$BASE/send.php")
check "GET send.php -> 405" 405 "$code"

code=$(request "$JAR_EMPTY" -X POST "${VALID[@]}" "$BASE/send.php")
check "POST без токена -> 403" 403 "$code"

TOKEN=$(get_token "$JAR")
check "token.php выдаёт токен" 64 "${#TOKEN}"

code=$(request "$JAR" -X POST --data-urlencode "csrf_token=wrong" "${VALID[@]}" "$BASE/send.php")
check "POST с чужим токеном -> 403" 403 "$code"

code=$(request "$JAR" -X POST --data-urlencode "csrf_token=$TOKEN" "${VALID[@]}" "$BASE/send.php")
check "слишком быстро -> 422" 422 "$code"
check "слишком быстро: error=too_fast" too_fast "$(json_get error)"

code=$(request "$JAR" -X POST --data-urlencode "csrf_token=$TOKEN" --data-urlencode "website=http://spam.example" \
       --data-urlencode "name=Бот" --data-urlencode "contact=bot@example.com" --data-urlencode "consent=1" "$BASE/send.php")
check "honeypot -> 200 для бота" 200 "$code"
check "honeypot: ok=true" true "$(json_get ok)"
check "honeypot: заявка не сохранена" 0 "$(leads_count)"

sleep 3.2

code=$(request "$JAR" -X POST --data-urlencode "csrf_token=$TOKEN" --data-urlencode "name=" \
       --data-urlencode "contact=" --data-urlencode "message=" "$BASE/send.php")
check "пустые поля -> 422" 422 "$code"
check "ошибка у поля name" set "$( [[ -n "$(json_get errors.name)" ]] && echo set )"
check "ошибка у поля contact" set "$( [[ -n "$(json_get errors.contact)" ]] && echo set )"
check "ошибка у поля consent" set "$( [[ -n "$(json_get errors.consent)" ]] && echo set )"

code=$(request "$JAR" -X POST --data-urlencode "csrf_token=$TOKEN" --data-urlencode "name=Клиент" \
       --data-urlencode "contact=не контакт" --data-urlencode "consent=1" "$BASE/send.php")
check "неверный контакт -> 422" 422 "$code"

code=$(request "$JAR" -X POST --data-urlencode "csrf_token=$TOKEN" "${VALID[@]}" "$BASE/send.php")
check "валидная заявка -> 200" 200 "$code"
check "запись в leads.jsonl" 1 "$(leads_count)"
check "в leads.jsonl есть имя" 1 "$(grep -c 'Тестовый Клиент' "$TMP/data/leads.jsonl" || true)"
check "сырой IP нигде не хранится" 0 "$(grep -rc '127.0.0.1' "$TMP/data" | awk -F: '{s+=$2} END {print s+0}')"

LONG=$(printf 'я%.0s' $(seq 1 2500))
code=$(request "$JAR" -X POST --data-urlencode "csrf_token=$TOKEN" "${VALID[@]}" --data-urlencode "message=$LONG" "$BASE/send.php")
check "длинное сообщение обрезается -> 200" 200 "$code"
check "сообщение обрезано до 2000 символов" 2000 "$(tail -n1 "$TMP/data/leads.jsonl" | php -r 'echo mb_strlen(json_decode(stream_get_contents(STDIN), true)["message"]);')"

for i in 3 4 5; do
    code=$(request "$JAR" -X POST --data-urlencode "csrf_token=$TOKEN" "${VALID[@]}" "$BASE/send.php")
    check "заявка $i -> 200" 200 "$code"
done

code=$(request "$JAR" -X POST --data-urlencode "csrf_token=$TOKEN" "${VALID[@]}" "$BASE/send.php")
check "6-я заявка за 10 минут -> 429" 429 "$code"
check "в файле ровно 5 заявок" 5 "$(leads_count)"

if [[ -s "$TMP/data/error.log" ]]; then
    echo "  error.log:"; sed 's/^/        /' "$TMP/data/error.log"
fi

echo
echo "Итого: $PASS пройдено, $FAIL с ошибкой"
[[ "$FAIL" -eq 0 ]]
