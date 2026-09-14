#!/usr/bin/env bash
# BizFlow design guard — see docs/DESIGN-SYSTEM.md.
#
# Fails (exit 1) on new off-system styling in every surface under the design
# system — owner app, public booking, marketing pages, auth screens.
#
# Usage:  bash scripts/check-design.sh          # CI mode, non-zero on errors
#         bash scripts/check-design.sh --quiet  # print only the summary

set -uo pipefail
cd "$(dirname "$0")/.."

errors=0
warnings=0

fail() { echo "  ✗ $1"; errors=$((errors+1)); }
warn() { echo "  ⚠ $1"; warnings=$((warnings+1)); }

# --- 1. Banned colour tones -------------------------------------------------
# rose/pink/indigo/violet/purple/fuchsia = dead brand generations or decoration;
# slate/zinc = the second neutral scale. gray is the only neutral, mulberry the
# only accent. Status tones (amber/sky/emerald/red) are fine and not checked.
# Marketing pages joined the system on 2026-09-14 — they are strict too now.
STRICT="resources/views/livewire
resources/views/layouts
resources/views/components
resources/views/welcome.blade.php
resources/views/pricing.blade.php
resources/views/terms.blade.php
resources/views/privacy.blade.php"

echo "[1/3] colour tones (rose|pink|indigo|violet|purple|fuchsia|slate|zinc)"
for path in $STRICT; do
    if [ -d "$path" ]; then
        hits=$(grep -rnoE "(rose|pink|indigo|violet|purple|fuchsia|slate|zinc)-[0-9]+" "$path" --include="*.blade.php" 2>/dev/null | grep -v "COLORS" || true)
    else
        hits=$(grep -noE "(rose|pink|indigo|violet|purple|fuchsia|slate|zinc)-[0-9]+" "$path" 2>/dev/null | grep -v "COLORS" || true)
    fi
    if [ -n "$hits" ]; then
        while IFS= read -r h; do fail "banned tone — $h"; done <<< "$hits"
    fi
done

# --- 2. Hand-written controls in converted pages -----------------------------
# The four screens on the system must not grow raw <button>/<input>/<textarea>
# (svg, span, a are fine; buttons inside <x-*> slots are what we want gone).
# Deliberate exceptions — tab strips, swatch pickers, clipboard panels, compact
# schedule inputs — carry a `design-allow: <reason>` blade comment on the line
# above; the guard honours it and nobody has to force-fit a wrong component.
echo "[2/3] raw controls in converted pages"
CONVERTED="resources/views/livewire/services/index.blade.php
resources/views/livewire/staff/index.blade.php
resources/views/livewire/customers/index.blade.php"
for path in $CONVERTED; do
    # NB: POSIX grep/awk have no \s — [[:space:]] is required or this check is a no-op.
    # A raw control is allowed when it sits inside a
    #   {{-- design-allow:start ... - ... --}} ... {{-- design-allow:end --}}
    # region, or directly under a one-line {{-- design-allow: ... --}}.
    hits=$(awk '
        /design-allow:start/ { on = 1 }
        /design-allow:end/   { on = 0 }
        /^[[:space:]]*<(button|input|textarea)([[:space:]]|>)/ && !on && prev !~ /design-allow/ { print FILENAME ":" NR }
        { prev = $0 }
    ' "$path")
    if [ -n "$hits" ]; then
        while IFS= read -r h; do fail "raw control — $h (use x-button/x-checkbox/x-textarea or wrap in a design-allow region)"; done <<< "$hits"
    fi
done

# --- 3. Arbitrary radii in pages ---------------------------------------------
# rounded-[32px] etc. belongs in a component (x-card, guest layout), not in a page.
echo "[3/3] arbitrary radii in page files"
page_hits=$(grep -rn "rounded-\[" resources/views/livewire --include="*.blade.php" 2>/dev/null | grep -v "layout/" || true)
if [ -n "$page_hits" ]; then
    while IFS= read -r h; do fail "arbitrary radius — $h (radius belongs in a component or token)"; done <<< "$page_hits"
fi

echo
if [ "${1:-}" != "--quiet" ]; then
    echo "errors: $errors   warnings: $warnings"
fi
[ "$errors" -eq 0 ] || exit 1
