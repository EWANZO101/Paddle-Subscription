#!/usr/bin/env bash
#
# Paddle Subscription for WemX — installer
#
# Extract this zip, then run this script from your WemX installation root
# (the directory containing `artisan`), pointing at wherever you extracted it:
#
#   bash /path/to/PaddleSubscription/install.sh
#
# Safe to re-run any time, including to update an existing install to the
# latest version — the route and controller method are replaced in place
# (not just left alone if already present), and any file this script edits
# is backed up first.
#
# This installs the same shared Paddle.js checkout page, route, and
# controller method that the Paddle-Checkout gateway also installs (Paddle
# Billing's checkout overlay is identical for one-time and recurring
# prices). Installing both gateways is fine — they use the same BEGIN/END
# markers, so whichever you install second just confirms the shared pieces
# are already in place rather than duplicating them.

set -euo pipefail

# Everything this script needs (Gateway.php, resources/, snippets/) lives
# alongside it in the extracted package — resolved here so this still works
# no matter where you extracted the zip to, or what you run it from.
SCRIPT_DIR=$(cd "$(dirname "$(readlink -f "$0")")" && pwd)

say() { printf '  - %s\n' "$1"; }
die() { printf 'Error: %s\n' "$1" >&2; exit 1; }

# Copies $src to $dst, skipping if they're already the same file (e.g. if
# this script is being run from inside extensions/Gateways/PaddleSubscription
# itself because a marketplace install already placed it there).
copy_if_different() {
    local src="$1" dst="$2"
    if [ -e "$dst" ] && [ "$(readlink -f "$src")" = "$(readlink -f "$dst")" ]; then
        return
    fi
    cp "$src" "$dst"
}

# Locates $anchor as a fixed (non-regex) string in $target and returns its
# 1-based line number, or dies if not found.
line_of() {
    local target="$1" anchor="$2"
    grep -nF -- "$anchor" "$target" | head -n1 | cut -d: -f1
}

# Inserts the contents of file $snippet right after line number $line_num of
# $target, using sed's `r` command — which copies the file in verbatim with
# no escape-sequence interpretation, unlike sed/awk `a` or `i` text, which
# treat backslashes specially and can silently corrupt PHP code full of
# namespaced class references (e.g. `Client\PaymentsController`).
insert_after_line() {
    local target="$1" snippet="$2" line_num="$3"
    sed -i "${line_num}r ${snippet}" "$target"
}

# Deletes a previously-inserted managed block (everything from the BEGIN
# marker to the END marker, inclusive) so a re-run replaces it with whatever
# the snippet currently looks like, instead of leaving a stale copy in place
# forever once a file is past the "not installed yet" check.
remove_managed_block() {
    local target="$1"
    sed -i '/BEGIN PADDLE CHECKOUT INSTALLER/,/END PADDLE CHECKOUT INSTALLER/d' "$target"
}

# Fallback for installs from before this installer wrapped its insertions in
# BEGIN/END markers: finds a bare, unmarked `paddleCheckout()` method by
# brace-counting (so it works regardless of exactly how long the method body
# is) and removes it along with any doc-comment directly above it. Without
# this, re-running on one of those older installs would insert a second
# copy of the method next to the first and fatal with "Cannot redeclare".
remove_legacy_method() {
    local target="$1"
    awk '
        BEGIN { fnline = 0 }
        { lines[NR] = $0 }
        /public function paddleCheckout\(/ { if (fnline == 0) fnline = NR }
        END {
            if (fnline == 0) { for (i = 1; i <= NR; i++) print lines[i]; exit }

            s = fnline
            i = fnline - 1
            while (i >= 1) {
                t = lines[i]
                gsub(/^[ \t]+|[ \t]+$/, "", t)
                if (t == "" || t ~ /^\/\*/ || t ~ /^\*/ || t ~ /\*\/$/ || t ~ /^\/\//) {
                    s = i; i--
                } else {
                    break
                }
            }

            depth = 0; started = 0; e = fnline
            for (j = fnline; j <= NR; j++) {
                o = lines[j]; gsub(/[^{]/, "", o)
                c = lines[j]; gsub(/[^}]/, "", c)
                depth += length(o) - length(c)
                if (length(o) > 0) started = 1
                if (started && depth == 0) { e = j; break }
            }

            if (e + 1 <= NR) {
                t = lines[e+1]
                gsub(/^[ \t]+|[ \t]+$/, "", t)
                if (t == "") e = e + 1
            }

            for (i = 1; i <= NR; i++) {
                if (i < s || i > e) print lines[i]
            }
        }
    ' "$target" > "${target}.awktmp" && mv "${target}.awktmp" "$target"
}

[ -f "artisan" ] && [ -d "app/Http/Controllers/Client" ] \
    || die "run this from your WemX installation root (the directory containing 'artisan')."

[ -f "app/Http/Controllers/Client/PaymentsController.php" ] \
    || die "app/Http/Controllers/Client/PaymentsController.php not found — is this really a WemX v3 install?"

[ -f "$SCRIPT_DIR/Gateway.php" ] \
    || die "Gateway.php not found next to this script — make sure you extracted the full zip, not just install.sh."

# Many WemX installs lock down extensions/, app/, routes/, etc. to root (or the
# web server user), unwritable by a normal shell login — this check fails fast
# with a clear fix instead of a confusing mid-script `mkdir: Permission denied`.
if [ ! -w "." ] || { mkdir -p extensions/Gateways 2>/dev/null && [ ! -w "extensions/Gateways" ]; }; then
    cat >&2 <<'EOF'
Error: this user can't write to this WemX installation.

Run this as whichever user owns the WemX install (or via sudo), e.g.:

    sudo bash /path/to/PaddleSubscription/install.sh
EOF
    exit 1
fi

echo "==> Installing Paddle Subscription gateway for WemX"

# 1. Extension folder
mkdir -p extensions/Gateways/PaddleSubscription
copy_if_different "$SCRIPT_DIR/Gateway.php" extensions/Gateways/PaddleSubscription/Gateway.php
say "extensions/Gateways/PaddleSubscription/Gateway.php"

# 2. Checkout view — shared with the PaddleCheckout gateway (Paddle Billing's
#    checkout overlay is identical for one-time and recurring prices), so this
#    is a no-op if that gateway is already installed. Respects APP_THEME from
#    .env, falls back to "default".
THEME="default"
if [ -f .env ]; then
    env_theme=$(sed -n 's/^APP_THEME=\(.*\)$/\1/p' .env | head -n1 | tr -d '"'"'"'' )
    [ -n "$env_theme" ] && THEME="$env_theme"
fi
view_dir="resources/client_area/${THEME}/payments"
mkdir -p "$view_dir"
copy_if_different "$SCRIPT_DIR/resources/paddle-checkout.blade.php" "$view_dir/paddle-checkout.blade.php"
say "$view_dir/paddle-checkout.blade.php"

# 3. Route — shared with PaddleCheckout, same markers — replaces its own
#    previously-inserted block on re-run so updates always take effect.
routes_file="routes/web.php"
cp "$routes_file" "${routes_file}.bak.$(date +%s)"
already_had_route=0
if grep -q "BEGIN PADDLE CHECKOUT INSTALLER" "$routes_file"; then
    already_had_route=1
    remove_managed_block "$routes_file"
elif grep -q "payments.paddle.checkout" "$routes_file"; then
    # installed by a version of this script (or PaddleCheckout's) from before
    # the marker scheme — the route is a single self-contained line, safe to
    # just delete by match.
    already_had_route=1
    sed -i '/payments\.paddle\.checkout/d' "$routes_file"
fi

anchor="    Route::any('/gateways/callbacks/{webhook_id}', [Client\\PaymentsController::class, 'gatewayCallback'])->name('payments.gateway.callback');"
line_num=$(line_of "$routes_file" "$anchor")
[ -n "$line_num" ] \
    || die "could not find the expected anchor line in routes/web.php — add the route manually, see INSTALL.md"

insert_after_line "$routes_file" "$SCRIPT_DIR/snippets/route.php" "$line_num"
say "route $([ "$already_had_route" -eq 1 ] && echo replaced || echo added) in routes/web.php (backup saved alongside it)"

# 4. Controller method — shared with PaddleCheckout, same replace-on-rerun
#    behaviour as the route above.
controller_file="app/Http/Controllers/Client/PaymentsController.php"
cp "$controller_file" "${controller_file}.bak.$(date +%s)"
already_had_method=0
if grep -q "BEGIN PADDLE CHECKOUT INSTALLER" "$controller_file"; then
    already_had_method=1
    remove_managed_block "$controller_file"
elif grep -q "function paddleCheckout" "$controller_file"; then
    # installed by a version of this script (or PaddleCheckout's) from before
    # the marker scheme — remove the old, unmarked method so we don't end up
    # with two of them.
    already_had_method=1
    remove_legacy_method "$controller_file"
fi

anchor='    public function pay($gatewayConfigId, Payment $payment)'
line_num=$(line_of "$controller_file" "$anchor")
[ -n "$line_num" ] \
    || die "could not find the expected anchor method in PaymentsController.php — add paddleCheckout() manually, see INSTALL.md"

insert_after_line "$controller_file" "$SCRIPT_DIR/snippets/paddleCheckout-method.php" "$((line_num - 1))"
say "paddleCheckout() $([ "$already_had_method" -eq 1 ] && echo replaced || echo added) in PaymentsController.php (backup saved alongside it)"

# Make sure the classes the new method needs are imported — additive only,
# these never need replacing since they don't change between versions.
IMPORTS_TMP=$(mktemp)
trap 'rm -f "$IMPORTS_TMP"' EXIT
: > "$IMPORTS_TMP"
for use_line in \
    'use App\Models\GatewayConfig;' \
    'use App\Models\Payment;' \
    'use App\Models\Subscription;' \
    'use Illuminate\Http\Request;'
do
    grep -qF "$use_line" "$controller_file" || printf '%s\n' "$use_line" >> "$IMPORTS_TMP"
done
if [ -s "$IMPORTS_TMP" ]; then
    ns_line=$(line_of "$controller_file" "namespace App\\Http\\Controllers\\Client;")
    insert_after_line "$controller_file" "$IMPORTS_TMP" "$ns_line"
fi
say "verified required 'use' imports in PaymentsController.php"

# 5. Normalize permissions on everything we just created, so the web server
#    user (which may be completely different from whoever ran this script —
#    root via sudo, a deploy user, etc.) can read the new files regardless.
#    Ownership is deliberately left alone: we don't reliably know the correct
#    user across every install, and read access is all a web server needs.
chmod -R a+rX extensions/Gateways/PaddleSubscription "$view_dir" 2>/dev/null || true
say "normalized permissions on installed files"

# 6. Clear caches so the new route/view/class are picked up immediately
if command -v php >/dev/null 2>&1; then
    php artisan optimize:clear >/dev/null 2>&1 || true
    say "cleared Laravel caches"
fi

echo
echo "==> Done. Next steps:"
echo "   1. Admin -> Gateways -> Configs -> create a config for 'Paddle Subscription'"
echo "   2. Fill in your Paddle API Key, Client-side Token, and Webhook Secret"
echo "   3. Point a Paddle notification destination at the Webhook URL shown there, subscribed to the subscription.* events"
echo "      (subscription.created, subscription.activated, subscription.updated, subscription.past_due,"
echo "       subscription.paused, subscription.resumed, subscription.canceled)"
echo "   See INSTALL.md (included in this zip) for full details."
