#!/usr/bin/env bash
#
# Smoke test login.heloklin.com end to end over real HTTPS.
#
#   deploy/verify.sh                                  # dari server (lewat internet)
#   deploy/verify.sh https://127.0.0.1                # loopback TLS tanpa verifikasi sert
#
# Account passwords are read from the credentials file on disk and never printed.
# Foreign-host runs never touch the production database.
#
# Mode passwordless: bila AUTH_ENABLE_PASSWORD_LOGIN=false, form password tidak
# dirender dan bagian yang butuh login password dilaporkan [skip], bukan gagal.
set -u

BASE="${1:-https://login.heloklin.com}"
JAR=$(mktemp)
CRED=/home/Heloklin/login-heloklin-account-credentials.txt
TLS_FLAGS=""
if [[ "$BASE" == *127.0.0.1* ]]; then
    TLS_FLAGS="-k"
fi

pass() { printf '  [ok]   %s\n' "$1"; }
skip() { printf '  [skip] %s\n' "$1"; }
fail() { printf '  [FAIL] %s\n' "$1"; FAILED=1; }
FAILED=0

code() { curl -s $TLS_FLAGS -o /dev/null -w '%{http_code}' -m 20 "$@"; }
fetch() { curl -s $TLS_FLAGS -m 20 "$@"; }
csrf_from() { grep -o 'name="_token" value="[^"]*"' <<<"$1" | head -1 | sed 's/.*value="//;s/"//'; }
password_of() { grep "$1" "$CRED" | sed 's/.*password: //'; }

# login_as <username>: 0 = masuk, 2 = form password tidak tersedia
login_as() {
    local user="$1" page token pw res
    page=$(fetch -c "$JAR" "$BASE/login")
    if ! grep -q 'name="password"' <<<"$page"; then
        return 2
    fi
    token=$(csrf_from "$page")
    pw=$(password_of "$user")
    res=$(curl -s $TLS_FLAGS -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' -m 20 \
          -X POST "$BASE/login" \
          --data-urlencode "_token=$token" \
          --data-urlencode "username=$user" \
          --data-urlencode "password=$pw")
    [ "$res" = 302 ]
}

echo "== public surface =="
c=$(code "$BASE/");            [ "$c" = 200 ] && pass "GET / -> 200" || fail "GET / -> $c"
c=$(code "$BASE/up");          [ "$c" = 200 ] && pass "GET /up (health) -> 200" || fail "GET /up -> $c"
c=$(code "$BASE/login");       [ "$c" = 200 ] && pass "GET /login -> 200" || fail "GET /login -> $c"
fetch "$BASE/robots.txt" | grep -q '^Disallow: /' \
    && pass "robots.txt disallows indexing" || fail "robots.txt wrong"

for p in /dashboard /employees /test-results; do
    loc=$(curl -s $TLS_FLAGS -o /dev/null -w '%{http_code} %{redirect_url}' -m 20 "$BASE$p")
    [ "${loc%% *}" = 302 ] && pass "guest $p -> 302" || fail "guest $p -> $loc"
done

c=$(code "$BASE/bootstrap")
[ "$c" = 404 ] && pass "GET /bootstrap -> 404 (sudah ada user / gagal-tertutup)" \
               || fail "GET /bootstrap -> $c (harus 404)"
c=$(code "$BASE/bootstrap?key=")
[ "$c" = 404 ] && pass "GET /bootstrap?key= -> 404 (kunci kosong)" || fail "GET /bootstrap?key= -> $c"

body=$(fetch "$BASE/")
grep -qE "Laravel [0-9]|8\.4\.[0-9]+|app_env|session_driver" <<<"$body" \
    && fail "guest landing leaks server detail" || pass "guest landing hides server detail"
grep -qi "heloklin" <<<"$body" && pass "landing renders" || fail "landing body looks empty"
grep -q "login.heloklin.com" <<<"$(grep -A1 'Relying Party ID' <<<"$body")" \
    && pass "RP ID reported on landing" || fail "RP ID not shown on landing"

echo "== security headers =="
hdr=$(curl -s $TLS_FLAGS -D - -o /dev/null -m 20 "$BASE/login")
for h in "x-content-type-options: nosniff" "x-frame-options: DENY" "referrer-policy: same-origin" "strict-transport-security" "x-robots-tag"; do
    grep -qi "^$h" <<<"$hdr" && pass "header $h" || fail "missing header $h"
done

echo "== http -> https =="
if [[ "$BASE" == https://* ]]; then
    host=${BASE#https://}
    loc=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -m 20 "http://$host/login")
    [ "${loc%% *}" = 301 ] && [ "${loc#* }" != "${loc}" ] \
        && pass "http://$host -> 301 redirect" || fail "http://$host -> $loc"
fi

echo "== login surface =="
page=$(fetch -c "$JAR" "$BASE/login")
grep -q 'name="password"' <<<"$page" \
    && pass "form password dirender (AUTH_ENABLE_PASSWORD_LOGIN=true, masa transisi)" \
    || pass "form password tidak dirender (passwordless)"
grep -qiE "hello|passkey" <<<"$page" && pass "tombol passkey ada di /login" \
    || fail "tombol passkey tidak ditemukan di /login"

echo "== login password (jalur pemulihan) =="
login_as test-admin
rc=$?
if [ "$rc" = 2 ]; then
    skip "AUTH_ENABLE_PASSWORD_LOGIN=false — uji halaman terautentikasi dilewati"
    rm -f "$JAR"
    echo
    [ "$FAILED" = 0 ] && echo "RESULT: all checks passed" || echo "RESULT: failures present"
    exit "$FAILED"
elif [ "$rc" != 0 ]; then
    fail "POST /login (test-admin) gagal -> $rc"
    rm -f "$JAR"
    echo
    echo "RESULT: failures present"
    exit 1
fi
pass "POST /login (test-admin) -> 302"

echo "== authorisation as test-admin =="
for p in /dashboard /passkeys /employees /test-results /documentation; do
    c=$(code -b "$JAR" "$BASE$p"); [ "$c" = 200 ] && pass "GET $p -> 200" || fail "GET $p -> $c"
done
c=$(code -b "$JAR" "$BASE/supervisor-test")
[ "$c" = 403 ] && pass "GET /supervisor-test -> 403" || fail "GET /supervisor-test -> $c"

c=$(code -b "$JAR" -H "Accept: application/json" "$BASE/user/passkeys/options")
[ "$c" = 423 ] && pass "passkey options (json) -> 423 passkey.confirm" || fail "passkey options (json) -> $c"

c=$(code -b "$JAR" -X POST -H "Accept: application/json" "$BASE/employees/1/prepare")
case "$c" in 423|419|404) pass "POST /employees/{id}/prepare tanpa konfirmasi -> $c" ;;
             *) fail "POST /employees/{id}/prepare tanpa konfirmasi -> $c (harus 423/419/404)" ;; esac

echo "== logout =="
token=$(csrf_from "$(fetch -b "$JAR" "$BASE/dashboard")")
c=$(curl -s $TLS_FLAGS -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' -m 20 -X POST "$BASE/logout" \
    --data-urlencode "_token=$token")
[ "$c" = 302 ] && pass "POST /logout -> 302" || fail "POST /logout -> $c"
c=$(code -b "$JAR" "$BASE/dashboard")
[ "$c" = 302 ] && pass "session invalidated after logout" || fail "session survived logout -> $c"

echo "== authorisation as test-supervisor =="
if login_as test-supervisor; then
    pass "POST /login (test-supervisor) -> 302"
    c=$(code -b "$JAR" "$BASE/supervisor-test")
    [ "$c" = 200 ] && pass "GET /supervisor-test -> 200" || fail "GET /supervisor-test -> $c"
    for p in /employees /admin-test; do
        c=$(code -b "$JAR" "$BASE$p")
        [ "$c" = 403 ] && pass "GET $p -> 403" || fail "GET $p -> $c"
    done
else
    fail "POST /login (test-supervisor) gagal"
fi

rm -f "$JAR"
echo
[ "$FAILED" = 0 ] && echo "RESULT: all checks passed" || echo "RESULT: failures present"
exit "$FAILED"
