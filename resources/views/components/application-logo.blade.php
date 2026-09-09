{{--
    The BizFlow mark.

    Not a monogram in a rounded square — that is the default move, and the welcome
    page already had one. The product's actual job is the gap between the reminder
    and the appointment, so the mark is that gap: a hollow dot (the message going
    out), a line (the day in between), a filled dot (the client in the chair).

    Drawn with currentColor so the caller sets the colour with a text-* class.
    Do not pass fill-current — the hollow dot depends on fill="none".
--}}
<svg viewBox="0 0 44 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" {{ $attributes }}>
    <path d="M12 12H30" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" />
    <circle cx="7" cy="12" r="4.25" stroke="currentColor" stroke-width="2.5" />
    <circle cx="35" cy="12" r="6" fill="currentColor" />
</svg>
