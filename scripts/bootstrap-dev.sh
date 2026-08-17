#!/usr/bin/env bash

set -Eeuo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_DIR"

fail() {
    printf 'Errore: %s\n' "$1" >&2
    exit 1
}

require_command() {
    command -v "$1" >/dev/null 2>&1 || fail "comando richiesto non trovato: $1"
}

env_value() {
    local key="$1"
    local value

    value="$(sed -n "s/^${key}=//p" .env | tail -n 1)"
    value="${value%\"}"
    value="${value#\"}"
    printf '%s' "$value"
}

write_env_value() {
    local key="$1"
    local value="$2"
    local temporary

    temporary="$(mktemp "${PROJECT_DIR}/.env.tmp.XXXXXX")"
    awk -v key="$key" -v value="$value" '
        BEGIN { found = 0 }
        $0 ~ "^" key "=" {
            if (! found) {
                print key "=" value
                found = 1
            }
            next
        }
        { print }
        END {
            if (! found) {
                print key "=" value
            }
        }
    ' .env > "$temporary"
    chmod --reference=.env "$temporary"
    mv "$temporary" .env
}

[[ "$(uname -s)" == "Linux" ]] || fail 'il bootstrap S0 supporta host Linux'
require_command docker
require_command id
require_command awk
require_command sed

docker info >/dev/null 2>&1 || fail 'Docker non è raggiungibile per l’utente corrente'
docker compose version >/dev/null 2>&1 || fail 'Docker Compose v2 non è disponibile'

if [[ ! -f .env ]]; then
    [[ -f .env.example ]] || fail '.env.example non trovato'
    cp .env.example .env
    chmod 600 .env
    printf 'Creato .env da .env.example.\n'
fi

FILE_APP_ENV="$(env_value APP_ENV)"
[[ "$FILE_APP_ENV" != 'production' && "${APP_ENV:-}" != 'production' ]] || \
    fail 'bootstrap rifiutato in ambiente production'

HOST_UID="$(id -u)"
HOST_GID="$(id -g)"
write_env_value WWWUSER "$HOST_UID"
write_env_value WWWGROUP "$HOST_GID"
export WWWUSER="$HOST_UID"
export WWWGROUP="$HOST_GID"

if [[ "$HOST_UID" == '0' ]]; then
    export APP_USER=root
    export SUPERVISOR_PHP_USER=root
else
    export APP_USER=sail
    export SUPERVISOR_PHP_USER=sail
fi

write_env_value APP_USER "$APP_USER"
write_env_value SUPERVISOR_PHP_USER "$SUPERVISOR_PHP_USER"

if [[ ! -x vendor/bin/sail ]]; then
    printf 'Installazione dipendenze Composer nel container PHP 8.3...\n'
    docker run --rm \
        --user "${HOST_UID}:${HOST_GID}" \
        --volume "${PROJECT_DIR}:/var/www/html" \
        --workdir /var/www/html \
        laravelsail/php83-composer:latest \
        composer install --no-interaction --prefer-dist --ignore-platform-req=ext-intl
fi

[[ -x vendor/bin/sail ]] || fail 'Laravel Sail non è disponibile dopo composer install'

printf 'Avvio dei servizi MP2...\n'
./vendor/bin/sail up -d

DB_USERNAME_VALUE="$(env_value DB_USERNAME)"
DB_PASSWORD_VALUE="$(env_value DB_PASSWORD)"
MYSQL_READY=0

for attempt in $(seq 1 60); do
    if docker compose exec -T mysql mysqladmin ping \
        --host=127.0.0.1 \
        --user="$DB_USERNAME_VALUE" \
        --password="$DB_PASSWORD_VALUE" \
        --silent >/dev/null 2>&1; then
        MYSQL_READY=1
        break
    fi

    sleep 1
done

[[ "$MYSQL_READY" == '1' ]] || fail 'MySQL non è pronto entro 60 secondi'

if [[ -z "$(env_value APP_KEY)" ]]; then
    ./vendor/bin/sail artisan key:generate --force --no-interaction
fi

write_env_value DEV_ADMIN_PASSWORD "$(env_value DEV_ADMIN_EMAIL)"

./vendor/bin/sail artisan migrate --force --no-interaction
./vendor/bin/sail artisan mp2:ensure-dev-admin --no-interaction

APP_PORT_VALUE="$(env_value APP_PORT)"
APP_PORT_VALUE="${APP_PORT_VALUE:-9000}"
LOCAL_URL="http://127.0.0.1:${APP_PORT_VALUE}/admin"
LOGIN_URL="${LOCAL_URL}/login"
APP_READY=0

for attempt in $(seq 1 30); do
    if command -v curl >/dev/null 2>&1 && curl --fail --silent --output /dev/null "$LOGIN_URL"; then
        APP_READY=1
        break
    fi

    sleep 1
done

[[ "$APP_READY" == '1' ]] || fail "l’applicazione non risponde su ${LOGIN_URL}"

LAN_IP=''
if command -v ip >/dev/null 2>&1; then
    LAN_IP="$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{ for (i = 1; i <= NF; i++) if ($i == "src") { print $(i + 1); exit } }')"
fi

if [[ -z "$LAN_IP" ]] && command -v hostname >/dev/null 2>&1; then
    LAN_IP="$(hostname -I 2>/dev/null | awk '{ for (i = 1; i <= NF; i++) if ($i !~ /^127\./ && $i ~ /^[0-9.]+$/) { print $i; exit } }')"
fi

printf '\nMP2 è pronto.\n'
printf 'Local: %s\n' "$LOCAL_URL"
if [[ -n "$LAN_IP" ]]; then
    printf 'LAN:   http://%s:%s/admin\n' "$LAN_IP" "$APP_PORT_VALUE"
else
    printf 'LAN:   indirizzo IPv4 non rilevato (verifica la rete host)\n'
fi
printf 'Email: %s\n' "$(env_value DEV_ADMIN_EMAIL)"
printf 'Password: %s\n' "$(env_value DEV_ADMIN_PASSWORD)"
