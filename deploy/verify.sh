#!/usr/bin/env bash
#
# Smoke test login.heloklin.com end to end over real HTTPS.
#
#   deploy/verify.sh                                  # dari server (loopback)
#   deploy/verify.sh https://login.heloklin.com       # lewat internet
#
# The test-admin password is read from the credentials file on disk and never
# printed. Foreign-host runs never touch the production database.
set -u

BASE="${1:-https://login.heloklin.com}"
JAR=$(mktemp)
CRED=/home/Heloklin/login-heloklin-account-credentials.txt
TLS_FLAGS=""
if [[ "$BASE" == *127.0.0.1* ]]; then
    TLS_FLAGS="-k"
fi

pass() { printf '  [ok]   %s\n' "$1"; }
fail() { printf '  [FAIL] %s\n' "$1"; FAILED=1; }
FAILED=0

code() { curl -s $TLS_FLAGS -o /dev/null -w '%{http_code}' -m 20 "$@"; }
fetch() { curl -s $TLS_FLAGS -m 20 "$@"; }

echo "== public surface =="
c=$(code "$BASE/");            [ "$c" = 200 ] && pass "GET / -> 200" || fail "GET / -> $c"
c=$(code "$BASE/up");          [ "$c" = 200 ] && pass "GET /up (health) -> 200" || fail "GET /up -> $c"
c=$(code "$BASE/login");       [ "$c" = 200 ] && pass "GET /login -> 200" || fail "GET /login -> $c"
c=$(code "$BASE/robots.txt");  fetch "$BASE/robots.txt" | grep -q '^Disallow: /' \
    && pass "robots.txt disallows indexing" || fail "robots.txt wrong"

loc=$(curl -s $TLS_FLAGS -o /dev/null -w '%{http_code} %{redirect_url}' -m 20 "$BASE/dashboard")
[ "${loc%% *}" = 302 ] && pass "guest /dashboard -> 302" || fail "guest /dashboard -> $loc"

body=$(fetch "$BASE/")
grep -qE "Laravel [0-9]|8\.4\.[0-9]+|app_env|session_driver" <<<"$body" \
    && fail "guest landing leaks server detail" || pass "guest landing hides server detail"
grep -q "login.heloklin.com" <<<"$(fetch "$BASE/" | grep -A1 'Relying Party ID')" \
    && pass "RP ID reported as login.heloklin.com" || fail "RP ID not shown on landing"

echo "== security headers =="
hdr=$(curl -s $TLS_FLAGS -D - -o /dev/null -m 20 "$BASE/login")
for h in "x-content-type-options: nosniff" "x-frame-options: DENY" "referrer-policy: same-origin" "strict-transport-security" "x-robots-tag"; do
    grep -qi "^$h" <<<"$hdr" && pass "header $h" || fail "missing header $h"
done

echo "== password login (real CSRF + cookie jar) =="
page=$(curl -s $TLS_FLAGS -c "$JAR" -m 20 "$BASE/login")
token=$(grep -o 'name="_token" value="[^"]*"' <<<"$page" | head -1 | sed 's/.*value="//;s/"//')
[ -n "$token" ] && pass "CSRF token present" || fail "no CSRF token"

pw=$(grep 'test-admin' "$CRED" | sed 's/.*password: //')
res=$(curl -s $TLS_FLAGS -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' -m 20 -X POST "$BASE/login" \
      --data-urlencode "_token=$token" --data-urlencode "username=test-admin" --data-urlencode "password=$pw")
[ "$res" = 302 ] && pass "POST /login -> 302" || fail "POST /login -> $res"

echo "== authorisation as test-admin =="
for p in /dashboard /passkeys /admin-test /test-results /documentation; do
    c=$(code -b "$JAR" "$BASE$p"); [ "$c" = 200 ] && pass "GET $p -> 200" || fail "GET $p -> $c"
done
c=$(code -b "$JAR" "$BASE/supervisor-test")
[ "$c" = 403 ] && pass "GET /supervisor-test -> 403" || fail "GET /supervisor-test -> $c"

c=$(code -b "$JAR" -H "Accept: application/json" "$BASE/user/passkeys/options")
[ "$c" = 423 ] && pass "passkey options (json) -> 423 password.confirm" || fail "passkey options (json) -> $c"

c=$(curl -s $TLS_FLAGS -b "$JAR" -o /dev/null -w '%{http_code}' -m 20 -X POST "$BASE/logout" \
    --data-urlencode "_token=$(fetch -b "$JAR" "$BASE/dashboard" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')")
[ "$c" = 302 ] && pass "POST /logout -> 302" || fail "POST /logout -> $c"
c=$(code -b "$JAR" "$BASE/dashboard")
[ "$c" = 302 ] && pass "session invalidated after logout" || fail "session survived logout -> $c"

rm -f "$JAR"
echo
[ "$FAILED" = 0 ] && echo "RESULT: all checks passed" || echo "RESULT: failures present"
exit "$FAILED"
