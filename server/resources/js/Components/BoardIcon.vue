<script setup>
/**
 * Icons the boards use OUTSIDE the sidebar.
 *
 * Separate from ModuleIcon on purpose. The boards draw the same subjects with
 * different geometry and different stroke weights depending on where they sit,
 * and those differences are deliberate:
 *
 *   sidebar navigation   1.8   ModuleIcon
 *   KPI cards            1.7   here
 *   activity rows        1.6   here
 *
 * The people icon also differs: the sidebar's has a second figure behind the
 * first, the KPI and activity ones do not. Folding these together behind one
 * component and normalising the stroke is exactly the kind of tidying that
 * produced the drift these boards are being re-lifted to fix.
 *
 * Stroke is a prop rather than baked in, because the same path is drawn at 1.7
 * in a KPI card and 1.6 in an activity row on the same screen.
 *
 * An unknown name renders nothing rather than a fallback glyph. A missing icon
 * is a visible bug worth fixing; a generic placeholder silently ships
 * something nobody drew.
 */
defineProps({
    name: { type: String, required: true },
    stroke: { type: [String, Number], default: 1.7 },
})
</script>

<template>
    <!-- Clients / estates: a building -->
    <svg v-if="name === 'clients'" viewBox="0 0 24 24" fill="none">
        <path d="M3 21V8l9-5 9 5v13M9 21v-6h6v6" :stroke-width="stroke" stroke="currentColor" stroke-linejoin="round" />
    </svg>

    <!-- Units under management: a taller block, standing on a ground line -->
    <svg v-else-if="name === 'units'" viewBox="0 0 24 24" fill="none">
        <path d="M3 21h18M5 21V7l7-4 7 4v14" :stroke-width="stroke" stroke="currentColor" stroke-linejoin="round" />
    </svg>

    <!-- Money: a card -->
    <svg v-else-if="name === 'billing'" viewBox="0 0 24 24" fill="none">
        <rect x="2" y="6" width="20" height="14" rx="2" :stroke-width="stroke" stroke="currentColor" />
        <path d="M2 10h20" :stroke-width="stroke" stroke="currentColor" />
    </svg>

    <!-- Guards: one figure, unlike the sidebar's two -->
    <svg v-else-if="name === 'guards'" viewBox="0 0 24 24" fill="none">
        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" :stroke-width="stroke" stroke="currentColor" />
        <circle cx="9" cy="7" r="4" :stroke-width="stroke" stroke="currentColor" />
    </svg>

    <!-- Reporting: axes with a trend line over them -->
    <svg v-else-if="name === 'reports'" viewBox="0 0 24 24" fill="none">
        <path d="M3 3v18h18" :stroke-width="stroke" stroke="currentColor" stroke-linecap="round" />
        <path
            d="M7 15l4-5 4 3 5-7"
            :stroke-width="stroke"
            stroke="currentColor"
            stroke-linecap="round"
            stroke-linejoin="round"
        />
    </svg>

    <!-- Revenue: a dollar sign. Distinct from `billing`, which is a card -->
    <svg v-else-if="name === 'currency'" viewBox="0 0 24 24" fill="none">
        <path
            d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H7"
            :stroke-width="stroke"
            stroke="currentColor"
            stroke-linecap="round"
        />
    </svg>

    <!--
      Warning: a bang in a ring.

      The only icon here that ignores the stroke prop, and deliberately. The
      boards draw the bang heavier than the ring it sits in — 1.9 against 1.6 —
      wherever this glyph appears (screens 36 and 39), so the pair is a fixed
      relationship rather than one weight to scale. One prop cannot carry two
      values, and averaging them would redraw the icon.
    -->
    <svg v-else-if="name === 'alert'" viewBox="0 0 24 24" fill="none">
        <path d="M12 9v4M12 17h.01" stroke-width="1.9" stroke="currentColor" stroke-linecap="round" />
        <circle cx="12" cy="12" r="9" stroke-width="1.6" stroke="currentColor" />
    </svg>

    <!-- Compliance and audit: a shield -->
    <svg v-else-if="name === 'shield'" viewBox="0 0 24 24" fill="none">
        <path
            d="M12 2 2 7v6c0 5.2 3.8 9 10 11 6.2-2 10-5.8 10-11V7l-10-5z"
            :stroke-width="stroke"
            stroke="currentColor"
            stroke-linejoin="round"
        />
    </svg>

    <!--
      Message an estate admin: a speaker.

      Screen 5 draws this on the client detail action stack. It is the same
      glyph the topbar uses for notifications, but at 1.7 rather than 1.8, which
      is why it lives here as well as inline in the shell.
    -->
    <svg v-else-if="name === 'broadcast'" viewBox="0 0 24 24" fill="none">
        <path
            d="M4 11v2a1 1 0 0 0 1 1h2l4 4V6L7 10H5a1 1 0 0 0-1 1z"
            :stroke-width="stroke"
            stroke="currentColor"
            stroke-linejoin="round"
        />
        <path d="M17 8a5 5 0 0 1 0 8" :stroke-width="stroke" stroke="currentColor" stroke-linecap="round" />
    </svg>

    <!--
      Settled: a tick.

      Drawn far heavier than the line icons beside it — the payroll board's net
      pay card uses 3 where its neighbours use 1.7 — so the weight arrives on
      the prop rather than being assumed here.
    -->
    <svg v-else-if="name === 'check'" viewBox="0 0 24 24" fill="none">
        <polyline
            points="20 6 9 17 4 12"
            :stroke-width="stroke"
            stroke="currentColor"
            stroke-linecap="round"
            stroke-linejoin="round"
        />
    </svg>

    <!--
      Exception: a bang in a TRIANGLE, and not the same glyph as `alert`.

      `alert` puts the bang in a ring and means an incident. This one means a
      record that needs a human before it can be posted, and the payroll board
      (screen 28) draws it on the exception banner. Like `alert` it ignores the
      stroke prop: the bang is 1.9 against the triangle's 1.7, a fixed pair
      rather than one weight to scale.
    -->
    <svg v-else-if="name === 'warning'" viewBox="0 0 24 24" fill="none">
        <path d="M12 9v4M12 17h.01" stroke-width="1.9" stroke="currentColor" stroke-linecap="round" />
        <path
            d="M10.3 3.9L2.5 18a1.8 1.8 0 0 0 1.6 2.7h15.8a1.8 1.8 0 0 0 1.6-2.7L13.7 3.9a1.8 1.8 0 0 0-3.4 0z"
            stroke-width="1.7"
            stroke="currentColor"
        />
    </svg>

    <!-- A billing period: a calendar. The billing board's "Next invoice run" card -->
    <svg v-else-if="name === 'calendar'" viewBox="0 0 24 24" fill="none">
        <rect x="3" y="5" width="18" height="16" rx="2" :stroke-width="stroke" stroke="currentColor" />
        <path
            d="M3 10h18M8 3v4M16 3v4"
            :stroke-width="stroke"
            stroke="currentColor"
            stroke-linecap="round"
        />
    </svg>

    <!--
      Panic: the same triangle as `warning`, drawn a notch heavier.

      The dispatch board (screen 14) puts the bang at 2 against the triangle's
      1.7, where the payroll board's `warning` puts it at 1.9. A tenth of a
      pixel, and still the designer's rather than a rounding error to average
      away — this one is reversed out of a solid red tile, that one sits on a
      pale panel. Like `warning` it ignores the stroke prop: the pair of
      weights is fixed, and one prop cannot carry two.
    -->
    <svg v-else-if="name === 'panic'" viewBox="0 0 24 24" fill="none">
        <path d="M12 9v4M12 17h.01" stroke-width="2" stroke="currentColor" stroke-linecap="round" />
        <path
            d="M10.3 3.9L2.5 18a1.8 1.8 0 0 0 1.6 2.7h15.8a1.8 1.8 0 0 0 1.6-2.7L13.7 3.9a1.8 1.8 0 0 0-3.4 0z"
            stroke-width="1.7"
            stroke="currentColor"
        />
    </svg>

    <!--
      `alert`, one notch up: the bang at 2 against the ring's 1.7.

      Screen 17's escalation button, where the glyph is reversed out of a solid
      red button rather than sitting in a pale tile. Same reason `panic` is not
      `warning`.
    -->
    <svg v-else-if="name === 'alert-strong'" viewBox="0 0 24 24" fill="none">
        <path d="M12 9v4M12 17h.01" stroke-width="2" stroke="currentColor" stroke-linecap="round" />
        <circle cx="12" cy="12" r="9" stroke-width="1.7" stroke="currentColor" />
    </svg>

    <!--
      Away: a clock, hands at ten past twelve.

      The same ring as `alert` at the same radius, which is why the guard
      workforce board (screen 18) can put the two KPI cards side by side and
      have them read as a pair. The hands are one path, not two, exactly as
      drawn.
    -->
    <svg v-else-if="name === 'clock'" viewBox="0 0 24 24" fill="none">
        <circle cx="12" cy="12" r="9" :stroke-width="stroke" stroke="currentColor" />
        <path d="M12 7v5l3.5 2" :stroke-width="stroke" stroke="currentColor" stroke-linecap="round" />
    </svg>

    <!--
      Add: a plus.

      Drawn at 2 on every topbar primary button the boards have — heavier than
      the 1.7 of the KPI icons — so the weight arrives on the prop.
    -->
    <svg v-else-if="name === 'plus'" viewBox="0 0 24 24" fill="none">
        <path d="M12 5v14M5 12h14" :stroke-width="stroke" stroke="currentColor" stroke-linecap="round" />
    </svg>

    <!--
      Export: a tray with an arrow, drawn upside down.

      The rotation is the board's own inline style, kept verbatim rather than
      folded into the path data. The boards really do draw an upload glyph and
      turn it over, and rewriting the coordinates to point the other way would
      quietly become a different icon under anyone who compared the two.
    -->
    <svg v-else-if="name === 'export'" viewBox="0 0 24 24" fill="none">
        <path
            d="M12 16V4m0 0L8 8m4-4l4 4M4 16v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"
            :stroke-width="stroke"
            stroke="currentColor"
            stroke-linecap="round"
            stroke-linejoin="round"
            style="transform: rotate(180deg); transform-origin: center"
        />
    </svg>

    <!--
      Cleared: a tick INSIDE a ring, and not the same glyph as `check`.

      `check` is a bare tick and means settled. This one means a failing thing
      put right — the guard workforce boards (21 and 23) draw it on "Mark
      licence renewed" — and like `alert` it ignores the stroke prop, because
      the boards draw the tick at 2 against the ring's 1.6. A fixed pair, not
      one weight to scale.
    -->
    <svg v-else-if="name === 'check-circle'" viewBox="0 0 24 24" fill="none">
        <path d="M9 12l2 2 4-4" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" />
        <circle cx="12" cy="12" r="9" stroke-width="1.6" stroke="currentColor" />
    </svg>

    <!--
      Refused: a cross in a ring.

      Board 21's "Suspend from active duty", the one destructive control on the
      compliance action screen. Both strokes are 1.7, so this one does take the
      prop.
    -->
    <svg v-else-if="name === 'x-circle'" viewBox="0 0 24 24" fill="none">
        <circle cx="12" cy="12" r="9" :stroke-width="stroke" stroke="currentColor" />
        <path d="M9 9l6 6M15 9l-6 6" :stroke-width="stroke" stroke="currentColor" stroke-linecap="round" />
    </svg>

    <!--
      Rostered shifts: a wall planner.

      A wider, shallower grid than `calendar`, hung from a single tab rather
      than two rings, which is how board 21 distinguishes a roster of shifts
      from a billing period. Kept as a second glyph rather than folded into
      `calendar`: they are drawn at different coordinates on boards that sit
      two screens apart.
    -->
    <svg v-else-if="name === 'shifts'" viewBox="0 0 24 24" fill="none">
        <rect x="2" y="4" width="20" height="16" rx="2" :stroke-width="stroke" stroke="currentColor" />
        <path d="M2 9h20M8 4v5" :stroke-width="stroke" stroke="currentColor" />
    </svg>

    <!--
      Confirmed: a tick in a ring, and the exact counterpart of `alert`.

      The dispatch boards 13 and 15 pair the two side by side — posts covered
      against posts uncovered, guards verified against missed checkpoints — so
      the ring is the same circle at the same radius and only the mark inside
      changes. Like `alert` it ignores the stroke prop: the tick is drawn at 2
      against the ring's 1.6, a fixed pair rather than one weight to scale, and
      averaging them would redraw both halves of the pair.
    -->
    <svg v-else-if="name === 'check-ring'" viewBox="0 0 24 24" fill="none">
        <path
            d="M9 12l2 2 4-4"
            stroke-width="2"
            stroke="currentColor"
            stroke-linecap="round"
            stroke-linejoin="round"
        />
        <circle cx="12" cy="12" r="9" stroke-width="1.6" stroke="currentColor" />
    </svg>

    <!--
      A bound device: a padlock.

      Board 15's "devices bound and reporting" KPI. The shackle and the body
      are both 1.7, so this one does take the prop.
    -->
    <svg v-else-if="name === 'lock'" viewBox="0 0 24 24" fill="none">
        <rect x="3" y="11" width="18" height="10" rx="2" :stroke-width="stroke" stroke="currentColor" />
        <path d="M7 11V7a5 5 0 0 1 10 0v4" :stroke-width="stroke" stroke="currentColor" />
    </svg>

    <!--
      A filed return: a sheet of paper with its corner turned.

      The statutory filings board (screen 30) draws it on every return that has
      been submitted. The turned corner is a second path rather than part of the
      outline, exactly as drawn, so the fold still reads at 16px.
    -->
    <svg v-else-if="name === 'document'" viewBox="0 0 24 24" fill="none">
        <path
            d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"
            :stroke-width="stroke"
            stroke="currentColor"
            stroke-linejoin="round"
        />
        <path d="M14 2v6h6" :stroke-width="stroke" stroke="currentColor" stroke-linejoin="round" />
    </svg>

    <!--
      A tax the law defines rather than a sum somebody billed: a ledger.

      Board 31 draws it beside Education Tax, where its neighbours are a person
      (NIS), a house (NHT) and a calendar (PAYE). The spine is a separate
      rounded path for the inside edge, which is what tells it apart from the
      plain rectangle of `shifts` at this size.
    -->
    <svg v-else-if="name === 'ledger'" viewBox="0 0 24 24" fill="none">
        <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20" :stroke-width="stroke" stroke="currentColor" stroke-linecap="round" />
        <path
            d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"
            :stroke-width="stroke"
            stroke="currentColor"
            stroke-linejoin="round"
        />
    </svg>

    <!--
      Edit this row: a pencil laid over an open page.

      Board 10 draws it on every guard assignment row, at 1.6 — lighter than the
      1.7 of the panel icons, because it sits inside a 30px .icon-btn-sm chip
      rather than on a panel. The pencil is a separate path from the page it
      writes on, exactly as drawn, so the nib still reads at 14px.
    -->
    <svg v-else-if="name === 'pencil'" viewBox="0 0 24 24" fill="none">
        <path
            d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"
            :stroke-width="stroke"
            stroke="currentColor"
            stroke-linejoin="round"
        />
        <path
            d="M18.5 2.5a2.1 2.1 0 0 1 3 3L12 15l-4 1 1-4z"
            :stroke-width="stroke"
            stroke="currentColor"
            stroke-linejoin="round"
        />
    </svg>

    <!--
      Remove this row: a bare cross, and NOT the same glyph as `x-circle`.

      `x-circle` is a cross in a ring and means an officer refused. This one has
      no ring and means a row taken off a list — board 10's remove chip — and the
      boards draw it heavier than anything it sits beside, at 2.2, because it is
      reversed out of a red tile at 14px and a thinner stroke disappears there.
      One path, both strokes, as drawn.
    -->
    <svg v-else-if="name === 'close'" viewBox="0 0 24 24" fill="none">
        <path d="M6 6l12 12M18 6L6 18" :stroke-width="stroke" stroke="currentColor" stroke-linecap="round" />
    </svg>

    <!--
      A patrol: a folded map.

      Board 26 draws it on every feed row that is a checkpoint scan or a shift
      start — a guard moving through the estate rather than deciding at a gate —
      at 1.6, lighter than the KPI icons above it. Three panels and two folds in
      one path, exactly as drawn: split into separate paths the creases would
      round their own ends and the map would come apart at this size.
    -->
    <svg v-else-if="name === 'map'" viewBox="0 0 24 24" fill="none">
        <path
            d="M9 20l-5.5-2V4L9 6m0 14l6-2M9 20V6m6 12l5.5 2V6L15 4m0 14V4"
            :stroke-width="stroke"
            stroke="currentColor"
            stroke-linejoin="round"
        />
    </svg>

    <!--
      Restore a row that was taken off, and NOT the same glyph as `check`.

      `check` is the full tick that marks a feature included or a run approved,
      drawn across the whole box at stroke 3. This one is a short tick sitting
      inside a tinted circle on board 44's removals, where the control undoes a
      removal rather than confirming anything. Different geometry, different
      weight, different meaning — folding them together would put a heavy
      confirmation tick where the board draws a quiet undo.
    -->
    <svg v-else-if="name === 'restore'" viewBox="0 0 24 24" fill="none">
        <path
            d="M9 12l2 2 4-4"
            :stroke-width="stroke"
            stroke="currentColor"
            stroke-linecap="round"
            stroke-linejoin="round"
        />
    </svg>
</template>
