#!/usr/bin/env bash
# seed-smoke.sh — scratch-DB smoke: migrate:fresh --seed on disposable sqlite
# Never touches MySQL `local`; verifies products/orders, permissions, idempotency.
# Usage: bash scripts/seed-smoke.sh  |  composer seed:smoke
set -euo pipefail
FILE="${SEED_SMOKE_DB:-storage/seed-smoke-$$.sqlite}"
mkdir -p "$(dirname "$FILE")"; touch "$FILE"
cleanup() { rm -f "$FILE"; }; trap cleanup EXIT
export DB_CONNECTION=sqlite DB_DATABASE="$FILE"
FAIL=0; pass(){ echo "PASS: $1"; }; fail(){ echo "FAIL: $1"; FAIL=1; }
echo "== seed-smoke: migrate:fresh --seed on sqlite [$FILE] =="
DB_CONNECTION=sqlite DB_DATABASE="$FILE" php artisan migrate:fresh --seed --force --no-interaction
# helper: run php with Laravel bootstrapped against scratch sqlite
q(){ DB_CONNECTION=sqlite DB_DATABASE="$FILE" php -r '
require "vendor/autoload.php"; $a=require "bootstrap/app.php";
$a->make("Illuminate\Contracts\Console\Kernel")->bootstrap();
'"$1"'' | tr -d "\r"; }
echo ""; echo "== checks =="
# 1) row minima: products >=8, orders >=10
COUNTS=$(q 'echo json_encode(["p"=>\DB::table("products")->count(),"o"=>\DB::table("orders")->count()]);' | grep -o "{.*}")
P=$(echo "$COUNTS" | php -r 'echo json_decode(file_get_contents("php://stdin"))->p??0;')
O=$(echo "$COUNTS" | php -r 'echo json_decode(file_get_contents("php://stdin"))->o??0;')
[ "${P:-0}" -ge 8 ] && pass "products >=8 (got $P)" || fail "products >=8 (got ${P:-?} raw=$COUNTS)"
[ "${O:-0}" -ge 10 ] && pass "orders >=10 (got $O)" || fail "orders >=10 (got ${O:-?})"
# 2) permissions modules.view / modules.manage exist and granted to admin
PJ=$(q '
$perms=\DB::table("adminlte_permissions")->whereIn("name",["modules.view","modules.manage"])->pluck("name")->all();
$role=\DB::table("adminlte_roles")->where("name","admin")->first();
$pivot=$role ? \DB::table("adminlte_permission_role")->where("role_id",$role->id)->pluck("permission_id")->all():[];
$ids=\DB::table("adminlte_permissions")->whereIn("name",["modules.view","modules.manage"])->pluck("id")->all();
echo json_encode(["perms"=>$perms,"granted"=>count(array_intersect($ids,$pivot))]);' | grep -o "{.*}")
PC=$(echo "$PJ" | php -r 'echo count(json_decode(file_get_contents("php://stdin"))->perms??[]);')
GR=$(echo "$PJ" | php -r 'echo json_decode(file_get_contents("php://stdin"))->granted??0;')
[ "${PC:-0}" -eq 2 ] && pass "permissions modules.view/manage exist (2/2)" || fail "permissions missing (got $PC/2 $PJ)"
[ "${GR:-0}" -eq 2 ] && pass "admin granted modules.* (2/2)" || fail "admin not granted modules.* (got $GR/2)"
# 3) idempotency: second db:seed must not change counts
BEFORE=$(q 'echo json_encode(["p"=>\DB::table("products")->count(),"o"=>\DB::table("orders")->count(),"perm"=>\DB::table("adminlte_permissions")->count()]);' | grep -o "{.*}")
DB_CONNECTION=sqlite DB_DATABASE="$FILE" php artisan db:seed --force --no-interaction >/dev/null 2>&1
AFTER=$(q 'echo json_encode(["p"=>\DB::table("products")->count(),"o"=>\DB::table("orders")->count(),"perm"=>\DB::table("adminlte_permissions")->count()]);' | grep -o "{.*}")
[ "$BEFORE" = "$AFTER" ] && pass "idempotency: counts unchanged ($BEFORE)" || fail "idempotency: $BEFORE -> $AFTER"
echo ""; [ "$FAIL" -eq 0 ] && echo "== ALL PASS ==" || echo "== SOME FAILURES =="; exit $FAIL
