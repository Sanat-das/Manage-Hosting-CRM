#!/usr/bin/env bash
# fresh-clone-smoke.sh — scratch-clone smoke: clone a repo (default: this
# checkout), assert the clean-clone invariants (nothing tracked under vendor/,
# no .env), prove the missing-vendor guard answers HTTP 500 with instructions
# instead of a raw PHP fatal, then run `composer run setup` and prove the clone
# boots into the first-run installer. The caller's checkout is only read, never
# written.
# Usage: bash scripts/fresh-clone-smoke.sh [repo-url] [git-ref]  |  composer fresh:smoke
#   [repo-url] defaults to the current checkout (".", i.e. argument 1 is usually
#   left out for a local run) and, with no [git-ref], the clone keeps that
#   repo's own HEAD. CI passes both through SMOKE_REPO_URL / SMOKE_REF: a
#   `git clone --depth 1` with no ref follows the remote's DEFAULT branch, so
#   without it the gate would certify main on every run and go green while the
#   broken pull_request or feature branch sat untested in the checkout. An
#   explicit ref is therefore fetched after the clone and detached onto
#   (`git clone --branch` cannot reach hidden refs such as refs/pull/N/merge),
#   and the SHA actually tested is reported on the clone line.
set -euo pipefail
REPO="${1:-${SMOKE_REPO_URL:-.}}"
REF="${2:-${SMOKE_REF:-}}"
SCRATCH="${TMPDIR:-/tmp}/fresh-clone-smoke-$$"
CLONE="$SCRATCH/managehosting"
BODY="$SCRATCH/body.txt"
HDR="$SCRATCH/hdr.txt"
LOG="$SCRATCH/setup.log"
SRV_PID=""
PORT=""
CLONE_OK=0
cleanup() {
  if [ -n "${SRV_PID:-}" ]; then kill "$SRV_PID" >/dev/null 2>&1 || true; fi
  rm -rf "$SCRATCH" >/dev/null 2>&1 || true
}
trap cleanup EXIT
mkdir -p "$SCRATCH"
FAIL=0; pass(){ echo "PASS: $1"; }; fail(){ echo "FAIL: $1"; FAIL=1; }
echo "== fresh-clone-smoke: clone $REPO into $SCRATCH =="

# port_free: probe a candidate port by binding it, with PHP only.
port_free() {
  php -r '$s=@stream_socket_server("tcp://127.0.0.1:".$argv[1],$errno,$errstr); exit($s === false ? 1 : 0);' "$1" >/dev/null 2>&1
}
# fetch: HTTP GET that survives non-2xx — PHP streams with ignore_errors, so a
# 500 body stays readable. No curl (not reliably installed here) and no
# Invoke-WebRequest (PowerShell 5.1 cannot read a 500 body). Body lands in $BODY,
# raw headers in $HDR, status code on stdout.
fetch() {
  php -r '
    $u=$argv[1]; $b=$argv[2]; $h=$argv[3];
    $c=stream_context_create(["http"=>[
      "ignore_errors"=>true,
      "follow_location"=>0,
      "timeout"=>60,
      "header"=>"Connection: close\r\nAccept: text/html\r\n",
    ]]);
    $body=@file_get_contents($u,false,$c);
    file_put_contents($b, $body === false ? "" : $body);
    $hdrs=$http_response_header ?? [];
    file_put_contents($h, implode("\n",$hdrs));
    echo isset($hdrs[0]) ? (int)substr($hdrs[0], 9, 3) : 0;
  ' "$1" "$BODY" "$HDR"
}
location_header() {
  grep -i '^Location:' "$HDR" | head -1 | tr -d '\r' | cut -d' ' -f2-
}
body_has() { grep -qF "$1" "$BODY"; }
# serve: pick a free port once, serve the clone's public/ with a RELATIVE docroot
# (so no MSYS/Windows path translation can break php -S) and record the PID —
# the server is always killed by that PID, never by a port match.
serve() {
  # A cached port that has stopped being free is re-picked rather than reused.
  if [ -n "$PORT" ] && ! port_free "$PORT"; then PORT=""; fi
  if [ -z "$PORT" ]; then
    for p in 8931 8932 8933 8934 8935 8936 8937 8938 8939 8940; do
      if port_free "$p"; then PORT="$p"; break; fi
    done
  fi
  [ -n "$PORT" ] || return 1
  ( cd "$CLONE" && exec php -S 127.0.0.1:"$PORT" -t public ) >"$SCRATCH/server.log" 2>&1 &
  SRV_PID=$!
  local i
  for i in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15; do
    php -r 'exit(@fsockopen("127.0.0.1",(int)$argv[1],$e,$m,1) ? 0 : 1);' "$PORT" >/dev/null 2>&1 && return 0
    sleep 1
  done
  return 1
}
stop_server() {
  if [ -n "${SRV_PID:-}" ]; then
    kill "$SRV_PID" >/dev/null 2>&1 || true
    wait "$SRV_PID" >/dev/null 2>&1 || true
    SRV_PID=""
  fi
}

REF_RESOLVED=1
if git clone --depth 1 "$REPO" "$CLONE" >"$SCRATCH/clone.log" 2>&1; then
  if [ -n "$REF" ]; then
    # Detach onto the requested ref, so a pull_request merge ref or a pushed
    # branch is what gets certified - not the remote's default branch.
    if git -C "$CLONE" fetch --depth 1 origin "$REF" >"$SCRATCH/fetch.log" 2>&1 \
      && git -C "$CLONE" checkout -q --detach FETCH_HEAD; then
      pass "fresh clone from $REPO detached onto ref $REF ($(git -C "$CLONE" rev-parse HEAD))"
    else
      REF_RESOLVED=0
      fail "git fetch $REF from $REPO failed - the clone sits on its default branch, so testing it would certify a commit nobody asked about (see $SCRATCH/fetch.log)"
    fi
  else
    pass "fresh clone from $REPO, no ref requested, at its own HEAD ($(git -C "$CLONE" rev-parse HEAD))"
  fi
else
  fail "git clone $REPO failed - see $SCRATCH/clone.log"
fi
if [ "$REF_RESOLVED" -eq 0 ]; then
  # Certifying the wrong commit is the failure mode this ref plumbing exists
  # to remove, so stop here rather than spend the install certifying it.
  echo ""; echo "fresh-clone-smoke: $FAIL failure(s)"
  exit 1
fi
echo ""; echo "== checks =="
# 1) HEAD resolves to a commit — a clone with no HEAD means the remote is empty.
# Everything below reads the clone, so a missing HEAD gates them all: with no
# clone `grep -c` would count 0 and report a false pass.
HEAD_SHA=$(git -C "$CLONE" rev-parse HEAD 2>/dev/null || true)
if [ -n "$HEAD_SHA" ]; then pass "clone HEAD resolves to a commit ($HEAD_SHA)"; CLONE_OK=1; else fail "clone HEAD does not resolve to a commit"; fi
# 2) CLEAN-CLONE INVARIANT: nothing tracked under vendor/, so a clone stays
# light and every checkout is forced to install its own dependencies.
if [ "$CLONE_OK" -eq 1 ]; then
  VENDOR_TRACKED=$(git -C "$CLONE" ls-files | grep -c '^vendor/' || true)
  if [ "$VENDOR_TRACKED" -eq 0 ]; then
    pass "clean-clone invariant: 0 files tracked under vendor/"
  else
    fail "clean-clone invariant: $VENDOR_TRACKED tracked files under vendor/"
  fi
else
  fail "clean-clone invariant: cannot read the clone, nothing to count"
fi
# 3) no committed .env — a tracked one would ship secrets and shadow the
# .env.example the installer expects.
if [ "$CLONE_OK" -eq 1 ]; then
  ENV_TRACKED=$(git -C "$CLONE" ls-files | grep -c '^\.env$' || true)
  if [ "$ENV_TRACKED" -eq 0 ]; then
    pass "no committed secrets: .env is untracked"
  else
    fail ".env is tracked in the clone ($ENV_TRACKED match(es))"
  fi
else
  fail "no committed secrets: cannot read the clone, nothing to count"
fi
# 4) the front-controller guard itself must survive into the clone.
if grep -q 'Composer dependencies are not installed' "$CLONE/public/index.php" 2>/dev/null; then
  pass "cloned public/index.php carries the missing-vendor guard"
else
  fail "cloned public/index.php has no missing-vendor guard"
fi
# 5) GUARD PROOF: before any install, the clone must answer with instructions.
# This is the exact state a clueless `git clone` produces.
if serve; then
  CODE=$(fetch "http://127.0.0.1:$PORT/")
  if [ "$CODE" = "500" ]; then
    pass "pre-install GET / answers HTTP 500 (guard, not a raw PHP fatal)"
  else
    fail "pre-install GET / returned HTTP $CODE, expected 500"
  fi
  if body_has 'composer install'; then
    pass "the pre-install 500 body tells the user to run composer install"
  else
    fail "the pre-install 500 body does not mention composer install"
  fi
else
  fail "could not serve the pre-install clone on a free localhost port"
fi
# 6) INSTALL PROOF: the documented one-liner must complete on its own.
stop_server
if ( cd "$CLONE" && composer run setup ) >"$LOG" 2>&1; then
  pass "composer run setup completed with exit code 0 in the clone"
else
  fail "composer run setup exited non-zero in the clone - last lines:"
  tail -n 15 "$LOG" 2>/dev/null || true
fi
# 7) BOOT PROOF: an installed clone must reach the first-run installer wizard.
stop_server
if serve; then
  CODE=$(fetch "http://127.0.0.1:$PORT/")
  if [ "$CODE" = "302" ]; then pass "GET / returns 302 while the app is uninstalled"; else fail "GET / returned HTTP $CODE, expected 302"; fi
  LOC=$(location_header)
  case "$LOC" in
    */install*) pass "GET / redirects to the installer ($LOC)" ;;
    *) fail "GET / redirects to '$LOC', expected /install" ;;
  esac
  CODE=$(fetch "http://127.0.0.1:$PORT/install")
  if [ "$CODE" = "200" ]; then pass "GET /install returns 200"; else fail "GET /install returned HTTP $CODE, expected 200"; fi
  body_has 'HostVexa' && pass "the installer page renders the application name" \
    || fail "the installer page does not render HostVexa"
  body_has 'System requirements' && pass "the installer page renders the requirements check" \
    || fail "the installer page does not render 'System requirements'"
  if body_has 'Composer dependencies are not installed' || body_has 'Failed opening required'; then
    fail "the installer page still shows the vendor guard or a PHP fatal"
  else
    pass "the installer page shows no vendor guard and no PHP fatal"
  fi
  # The page must also prove the clone is USABLE, not merely rendered: each
  # requirement row renders class "dot ok" or "dot fail", so a missing PHP
  # extension on a runner must fail the gate instead of passing silently. The
  # check keys on that markup rather than the ✓/✕ glyphs (which mojibake through
  # the shell's encoding) or a literal "18/18 passed" (the check count is
  # environment-dependent, so a fixed number would false-fail).
  body_has 'dot ok' && pass "the installer page renders a requirement as met (dot ok)" \
    || fail "the installer page renders no met requirement (no 'dot ok')"
  if body_has 'dot fail'; then
    fail "the installer page renders at least one failed requirement (dot fail)"
  else
    pass "the installer page renders no failed requirement (no 'dot fail')"
  fi
else
  fail "could not serve the installed clone on a free localhost port"
fi
# 8) the artifacts setup must leave behind.
for f in vendor/autoload.php public/build/manifest.json public/storage; do
  if [ -e "$CLONE/$f" ]; then pass "post-setup artifact present: $f"; else fail "post-setup artifact missing: $f"; fi
done
stop_server
echo ""; echo "fresh-clone-smoke: $FAIL failure(s)"
[ "$FAIL" -eq 0 ]
