{{-- Split view: the reading pane is only shown from the xl breakpoint (80rem); below, rows open the message page. --}}
<style>
    .fi-mailbox-split-preview { display: none; }

    @media (min-width: 80rem) {
        .fi-mailbox-split-preview {
            display: block;
            position: sticky;
            top: 5rem;
            max-height: calc(100vh - 6rem);
            overflow-y: auto;
        }
    }

    .fi-mailbox-preview { display: flex; flex-direction: column; gap: 1rem; }
    .fi-mailbox-preview-header { display: flex; flex-direction: column; gap: 0.75rem; }
    .fi-mailbox-preview-subject { font-size: 1.125rem; font-weight: 600; line-height: 1.5rem; overflow-wrap: anywhere; }
    .fi-ta-row.fi-mailbox-selected,
    .fi-ta-record.fi-mailbox-selected { background-color: color-mix(in oklab, var(--primary-500) 12%, transparent); }
</style>
